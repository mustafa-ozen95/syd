package main

import (
	"context"
	"io"
	"strings"
	"sync"
	"testing"
	"time"
)

// sahteCihaz konsoldan EX-2200 gibi davranır: yazılanı yankılar, satır
// gelince durumuna göre cevap verir.
type sahteCihaz struct {
	t             *testing.T
	okur          *io.PipeReader
	yazar         *io.PipeWriter
	mu            sync.Mutex
	durum         string // cli | onay | uboot | geri-sayim | loader | kuruluyor | login | kabuk | conf | yukleme
	satir         string
	parola        string
	yuklenen      []string
	loaderAyari   map[string]string
	kurulan       string
	hiz           int
	cevapHizi     int
	kapali        bool
	kurulumHatasi bool
	// kurucuHatasi: loader görüntüyü açar ama kurucu paketi indiremez
	// (ör. TFTP blok boyu yüzünden).
	kurucuHatasi bool
	cikis        chan string
}

func yeniSahte(t *testing.T, durum string) *sahteCihaz {
	r, w := io.Pipe()
	c := &sahteCihaz{t: t, okur: r, yazar: w, durum: durum, loaderAyari: map[string]string{}, hiz: 115200, cevapHizi: 115200, cikis: make(chan string, 4096)}
	// Tek yazar: çıktı sırası bozulmasın.
	go func() {
		for s := range c.cikis {
			if _, err := w.Write([]byte(s)); err != nil {
				return
			}
		}
	}()
	return c
}

func (c *sahteCihaz) Read(p []byte) (int, error) { return c.okur.Read(p) }
func (c *sahteCihaz) Close() error {
	c.mu.Lock()
	c.kapali = true
	c.mu.Unlock()
	return c.yazar.Close()
}
func (c *sahteCihaz) HizAyarla(h int) error {
	c.mu.Lock()
	c.hiz = h
	c.mu.Unlock()
	return nil
}

// cik kilit altında çağrılır.
func (c *sahteCihaz) cik(s string) {
	if c.hiz != c.cevapHizi {
		s = strings.Repeat("\xfe\x80", len(s)/2+1)
	}
	c.cikis <- s
}

func (c *sahteCihaz) sonra(d time.Duration, f func()) { time.AfterFunc(d, f) }

// elektrikVer2300 EX2300 gibi açar (bir EX2300 konsolunda görülen sıra):
// U-Boot, FreeBSD/armv6 loader, menü çizilir ve "Autoboot"
// geri sayımı yalnız Ctrl-C ile durur; durmazsa Junos açılır.
func (c *sahteCihaz) elektrikVer2300() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.durum = "acilis"
	c.cikLocked("\r\nU-Boot 2016.01 (Jan 03 2024)\r\nDRAM:  2 GiB\r\nstarting USB...\r\nConsoles: U-Boot console\r\n\r\nFreeBSD/armv6 U-Boot loader, Revision 1.2\r\n")
	c.sonra(300*time.Millisecond, func() {
		c.mu.Lock()
		defer c.mu.Unlock()
		c.durum = "ex-autoboot"
		c.cikLocked("\x1b[m\x1b[H\x1b[J/\x08-\x08\\\x08|\x08\x1b[H\x1b[KAutoboot in 3 seconds... (press Ctrl-C to interrupt)")
		c.sonra(2*time.Second, func() {
			c.mu.Lock()
			defer c.mu.Unlock()
			if c.durum == "ex-autoboot" {
				c.durum = "acilis"
				c.cikLocked("\r\nBooting from Junos volume ...\r\n")
			}
		})
	})
}

// elektrikVer kapalı cihazı açar: U-Boot geri sayımı kimse durdurmadan
// geçer, loader'ın kısa penceresi gelir.
func (c *sahteCihaz) elektrikVer() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.durum = "acilis"
	c.cikLocked("\r\nU-Boot 1.1.6 (Mar 1 2012)\r\nDRAM: 512 MB\r\nFlash: 8 MB\r\nNet: eTSEC1\r\nHit any key to stop autoboot:  0 \r\n")
	c.sonra(400*time.Millisecond, func() {
		c.mu.Lock()
		defer c.mu.Unlock()
		c.cikLocked("FreeBSD/PowerPC U-Boot bootstrap loader, Revision 2.4\r\nLoading /boot/defaults/loader.conf\r\n")
		c.sonra(300*time.Millisecond, func() {
			c.mu.Lock()
			defer c.mu.Unlock()
			c.durum = "geri-sayim-loader"
			c.cikLocked("Hit [Enter] to boot immediately, or space bar for command prompt.\r\nBooting [/kernel] in 1 second... ")
		})
	})
}

func (c *sahteCihaz) Write(p []byte) (int, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, b := range p {
		c.bayt(b)
	}
	return len(p), nil
}

func (c *sahteCihaz) bayt(b byte) {
	switch c.durum {
	case "geri-sayim-uboot":
		c.durum = "uboot"
		c.cikLocked("\r\n=> ")
		return
	case "geri-sayim-loader":
		c.durum = "loader"
		c.cikLocked("\r\nType '?' for a list of commands.\r\nloader> ")
		return
	case "kuruluyor", "acilis", "kapali-cihaz":
		return
	case "ex-autoboot":
		if b == 0x03 {
			c.durum = "ex-menu"
			c.cikLocked("\x1b[H\x1b[JMain Menu\r\n\r\n1. Boot [J]unos volume\r\n2. Boot Junos volume in [S]afe mode\r\n3. [R]eboot\r\n4. [B]oot menu\r\n5. [M]ore options\r\n\r\nChoice: ")
		}
		return // boşluk bu geri sayımı durdurmaz
	case "yukleme":
		if b == 0x04 {
			c.durum = "conf"
			c.cikLocked("\r\nload complete\r\n\r\n[edit]\r\nroot# ")
			return
		}
	}
	if b == '\r' || b == '\n' {
		satir := strings.TrimSpace(c.satir)
		c.satir = ""
		c.cikLocked("\r\n")
		c.satirGeldi(satir)
		return
	}
	c.satir += string(b)
	c.cikLocked(string(b))
}

func (c *sahteCihaz) cikLocked(s string) { c.cik(s) }

func (c *sahteCihaz) satirGeldi(s string) {
	switch c.durum {
	case "ex-menu":
		if s == "5" {
			c.durum = "ex-menu2"
			c.cikLocked("\x1b[H\x1b[JMore Options\r\n\r\n1. Recover [J]unos volume\r\n2. Recovery mode - [C]LI\r\n3. Check [F]ile system\r\n4. Enable [V]erbose boot\r\n5. [B]oot prompt\r\n6. [M]ain menu\r\n\r\nChoice: ")
			return
		}
		c.cikLocked("Choice: ")
	case "ex-menu2":
		if s == "5" {
			c.durum = "loader"
			c.cikLocked("\r\nType '?' for a list of commands, 'help' for more detailed help.\r\nloader> ")
			return
		}
		c.cikLocked("Choice: ")
	case "cli":
		switch s {
		case "":
			c.cikLocked("root@sw1> ")
		case "request system reboot":
			c.durum = "onay"
			c.cikLocked("Reboot the system ? [yes,no] (no) ")
		case "show version | no-more":
			c.cikLocked("Hostname: sw1\r\nModel: ex2200-24t-4g\r\nJUNOS Base OS boot [12.3R12.4]\r\nroot> ")
		case "configure":
			c.durum = "conf"
			c.cikLocked("Entering configuration mode\r\n\r\n[edit]\r\nroot# ")
		case "exit":
			c.durum = "login"
			c.cikLocked("\r\nAmnesiac (ttyu0)\r\n\r\nlogin: ")
		default:
			c.cikLocked("unknown command.\r\nroot> ")
		}
	case "onay":
		if s == "yes" {
			c.durum = "acilis"
			c.cikLocked("Shutdown NOW!\r\n*** FINAL System shutdown message ***\r\n")
			c.sonra(200*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.durum = "geri-sayim-uboot"
				c.cikLocked("\r\nU-Boot 1.1.6 (Mar 1 2012)\r\nDRAM: 512 MB\r\nHit any key to stop autoboot:  3 ")
			})
		}
	case "uboot":
		if s == "boot" {
			c.durum = "acilis"
			c.cikLocked("FreeBSD/PowerPC U-Boot bootstrap loader, Revision 2.4\r\nLoading /boot/defaults/loader.conf\r\n")
			c.sonra(200*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.durum = "geri-sayim-loader"
				c.cikLocked("Hit [Enter] to boot immediately, or space bar for command prompt.\r\nBooting [/kernel] in 1 second... ")
			})
		} else {
			c.cikLocked("Unknown command '" + s + "'\r\n=> ")
		}
	case "loader":
		switch {
		case s == "":
			c.cikLocked("loader> ")
		case strings.HasPrefix(s, "set "):
			kv := strings.SplitN(strings.TrimPrefix(s, "set "), "=", 2)
			c.loaderAyari[kv[0]] = kv[1]
			c.cikLocked("loader> ")
		case strings.HasPrefix(s, "install --format tftp://"):
			c.kurulan = strings.TrimPrefix(s, "install --format ")
			if c.kurulumHatasi {
				c.cikLocked("can't load file '" + c.kurulan + "': No such file or directory\r\nloader> ")
				return
			}
			if c.kurucuHatasi {
				c.kurucuHatasi = false // yeniden açılışta düzelmiş say
				c.durum = "kurucu"
				c.cikLocked("/kernel text=0x1b4\r\nDownloading /j.tgz from 192.0.2.5 ...\r\n  512B  00:01\r\ntar: Error opening archive: truncated gzip input\r\nERROR: cannot unpack installation package\r\n*** The installer exited with status 3 ***\r\n*** The installation is unsuccessful!!! ***\r\nA shell has been started. Type exit<cr> to reboot:\r\n# ")
				return
			}
			c.durum = "kuruluyor"
			c.cikLocked("Formatting...\r\nInstalling...\r\n")
			c.sonra(300*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.durum = "login"
				c.cikLocked("Copyright (c) 1996-2017, Juniper Networks\r\nAmnesiac (ttyu0)\r\n\r\nlogin: ")
			})
		default:
			c.cikLocked("not found\r\nloader> ")
		}
	case "login":
		if s == "root" {
			if c.parola != "" {
				c.durum = "parola"
				c.cikLocked("Password:")
				return
			}
			c.durum = "kabuk"
			c.cikLocked("--- JUNOS 12.3R12.4 built\r\nroot@:RE:0% ")
		} else if s == "" {
			c.cikLocked("login: ")
		}
	case "parola":
		if s == c.parola {
			c.durum = "cli"
			c.cikLocked("root@sw1> ")
		} else {
			c.durum = "login"
			c.cikLocked("Login incorrect\r\nlogin: ")
		}
	case "kabuk":
		switch s {
		case "cli":
			c.durum = "cli"
			c.cikLocked("root> ")
		case "exit":
			c.durum = "login"
			c.cikLocked("login: ")
		default:
			c.cikLocked("root@:RE:0% ")
		}
	case "conf":
		switch s {
		case "load override terminal":
			c.durum = "yukleme"
			c.cikLocked("[Type ^D at a new line to end input]\r\n")
		case "commit and-quit":
			c.durum = "cli"
			c.cikLocked("commit complete\r\nExiting configuration mode\r\n\r\nroot@sw1> ")
		default:
			c.cikLocked("[edit]\r\nroot# ")
		}
	case "yukleme":
		c.yuklenen = append(c.yuklenen, s)
	case "kurucu":
		if s == "exit" {
			c.durum = "acilis"
			c.sonra(100*time.Millisecond, c.elektrikVer2300)
			return
		}
		if s == "" {
			c.cikLocked("# ")
			return
		}
		c.cikLocked("sh: " + s + ": not found\r\n# ")
	}
}

func testAkisi(t *testing.T, c *sahteCihaz, is Is) (*Akis, *[]string) {
	k := yeniKonsol(c)
	var mu sync.Mutex
	olaylar := []string{}
	a := &Akis{
		k:  k,
		is: is,
		bildir: func(asama, mesaj string) {
			mu.Lock()
			olaylar = append(olaylar, asama+": "+mesaj)
			mu.Unlock()
		},
		yapilandirma:    func(context.Context) (string, error) { return "system {\n    host-name sw1;\n}", nil },
		adresKullanimda: func(string) bool { return false },
		acilisSuresi:    10 * time.Second,
		kurulumSuresi:   10 * time.Second,
		elleSuresi:      5 * time.Second,
	}
	return a, &olaylar
}

var ornekIs = Is{
	UUID:            "u1",
	CihazIP:         "198.51.100.120",
	LoaderKomutlari: []string{"set ipaddr=198.51.100.120", "set netmask=255.255.255.0", "set gatewayip=198.51.100.1", "set serverip=198.51.100.250"},
	KurulumKomutu:   "install --format tftp://198.51.100.250/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz",
	BeklenenSurum:   "12.3R12.4",
	Yapilandirma:    true,
	YuklemeKomutu:   "load override terminal",
}

func TestCalisanCihazdanKurulum(t *testing.T) {
	c := yeniSahte(t, "login")
	c.parola = "gizli"
	is := ornekIs
	is.Parola = "gizli"
	a, olaylar := testAkisi(t, c, is)
	ctx, bitir := context.WithTimeout(context.Background(), 30*time.Second)
	defer bitir()

	if err := a.Kurulum(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v\nkonsol: %q", err, *olaylar, a.k.Metin(0))
	}
	if c.loaderAyari["ipaddr"] != "198.51.100.120" || c.loaderAyari["serverip"] != "198.51.100.250" {
		t.Errorf("loader ayarları: %v", c.loaderAyari)
	}
	if c.kurulan != "tftp://198.51.100.250/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz" {
		t.Errorf("kurulan: %q", c.kurulan)
	}
	if strings.Join(c.yuklenen, "\n") != "system {\nhost-name sw1;\n}" {
		t.Errorf("yüklenen: %q", c.yuklenen)
	}
	if c.durum != "login" {
		t.Errorf("oturum açık kaldı: %s", c.durum)
	}
}

func TestLoaderdakiCihaz(t *testing.T) {
	c := yeniSahte(t, "loader")
	is := ornekIs
	is.Yapilandirma = false
	a, olaylar := testAkisi(t, c, is)
	ctx, bitir := context.WithTimeout(context.Background(), 30*time.Second)
	defer bitir()
	if err := a.Kurulum(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *olaylar)
	}
}

func TestLoaderKurulumuReddederse(t *testing.T) {
	c := yeniSahte(t, "loader")
	c.kurulumHatasi = true
	a, _ := testAkisi(t, c, ornekIs)
	ctx, bitir := context.WithTimeout(context.Background(), 30*time.Second)
	defer bitir()
	err := a.Kurulum(ctx)
	if err == nil || !strings.Contains(err.Error(), "No such file") {
		t.Fatalf("hata beklenirdi, gelen: %v", err)
	}
}

func TestAdresKullanimdaysaKurmaz(t *testing.T) {
	c := yeniSahte(t, "loader")
	a, _ := testAkisi(t, c, ornekIs)
	a.adresKullanimda = func(string) bool { return true }
	err := a.Kurulum(context.Background())
	if err == nil || !strings.Contains(err.Error(), "kullanılıyor") {
		t.Fatalf("hata beklenirdi, gelen: %v", err)
	}
	if c.kurulan != "" {
		t.Fatal("adres kullanımdayken kurulum başladı")
	}
}

func TestParolaYanlissaElleAcilisBeklenir(t *testing.T) {
	c := yeniSahte(t, "login")
	c.parola = "dogru"
	is := ornekIs
	is.Parola = "yanlis"
	a, olaylar := testAkisi(t, c, is)
	a.elleSuresi = 1 * time.Second
	err := a.Kurulum(context.Background())
	if err == nil {
		t.Fatal("hata beklenirdi")
	}
	bulundu := false
	for _, o := range *olaylar {
		if strings.HasPrefix(o, "uyari: ") && strings.Contains(o, "elektriğini") {
			bulundu = true
		}
	}
	if !bulundu {
		t.Fatalf("operatöre uyarı yok: %v", *olaylar)
	}
}

func TestHizBulunur(t *testing.T) {
	c := yeniSahte(t, "cli")
	c.cevapHizi = 9600
	k := yeniKonsol(c)
	hiz, cevap, err := hizBul(context.Background(), k)
	if err != nil || !cevap || hiz != 9600 {
		t.Fatalf("hız %d cevap %v hata %v", hiz, cevap, err)
	}
}

// Cihaz iş açıldıktan sonra açılıyor ve 9600'de konuşuyor; ajan 115200'de
// bekliyordu. Bozuk karakterleri görüp hızı değiştirmeli ve loader'ı
// yakalamalı.
func TestKapaliCihazBaskaHizdaAcilir(t *testing.T) {
	c := yeniSahte(t, "kapali-cihaz")
	c.cevapHizi = 9600
	is := ornekIs
	is.Yapilandirma = false
	a, olaylar := testAkisi(t, c, is)
	ctx, bitir := context.WithTimeout(context.Background(), 30*time.Second)
	defer bitir()

	if _, cevap, err := hizBul(ctx, a.k); err != nil || cevap {
		t.Fatalf("kapalı cihaz cevap vermemeli: %v %v", cevap, err)
	}
	if a.k.hiz != 115200 {
		t.Fatalf("varsayılan hız 115200 olmalı: %d", a.k.hiz)
	}
	// Operatörün yapacağı gibi: "elektriği verin" uyarısı görülünce.
	a.elleSuresi = 20 * time.Second
	var bir sync.Once
	asil := a.bildir
	a.bildir = func(asama, mesaj string) {
		asil(asama, mesaj)
		if strings.Contains(mesaj, "elektrik") {
			bir.Do(func() { go c.elektrikVer() })
		}
	}

	if err := a.Kurulum(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *olaylar)
	}
	if a.k.hiz != 9600 {
		t.Errorf("hız: %d", a.k.hiz)
	}
	if c.kurulan == "" {
		t.Errorf("kurulmadı; olaylar: %v", *olaylar)
	}
}

// EX2300: boşluk işe yaramaz, Ctrl-C ve menüden loader'a inilir.
func TestEx2300MenudenLoadera(t *testing.T) {
	c := yeniSahte(t, "kapali-cihaz")
	c.cevapHizi = 9600
	is := ornekIs
	is.Yapilandirma = false
	a, olaylar := testAkisi(t, c, is)
	a.elleSuresi = 20 * time.Second
	var bir sync.Once
	asil := a.bildir
	a.bildir = func(asama, mesaj string) {
		asil(asama, mesaj)
		if strings.Contains(mesaj, "elektrik") {
			bir.Do(func() { go c.elektrikVer2300() })
		}
	}
	ctx, bitir := context.WithTimeout(context.Background(), 40*time.Second)
	defer bitir()
	if err := a.Kurulum(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v\nkonsol: %q", err, *olaylar, a.k.Metin(0))
	}
	if c.kurulan == "" {
		t.Errorf("kurulmadı; olaylar: %v", *olaylar)
	}
}

func TestMenuSecimi(t *testing.T) {
	ana := "Main Menu\n\n1. Boot [J]unos volume\n2. Boot Junos volume in [S]afe mode\n3. [R]eboot\n4. [B]oot menu\n5. [M]ore options\n\nChoice: "
	daha := "More Options\n\n1. Recover [J]unos volume\n4. Enable [V]erbose boot\n5. [B]oot prompt\n6. [M]ain menu\n\nChoice: "
	if s := menuSecimi(ana); s != "5" {
		t.Errorf("ana menü: %q", s)
	}
	if s := menuSecimi(daha); s != "5" {
		t.Errorf("seçenekler: %q", s)
	}
	if s := menuSecimi("1. Boot [J]unos volume\nChoice: "); s != "" {
		t.Errorf("bilinmeyen menü: %q", s)
	}
}

// Kurucu düşerse 4 saat beklenmez; sonraki işte cihaz kurulum kabuğunda
// bulunur, "exit" ile yeniden başlatılıp kurulur.
func TestKurucuDuserseVeKabuktanDonus(t *testing.T) {
	c := yeniSahte(t, "loader")
	c.kurucuHatasi = true
	is := ornekIs
	is.Yapilandirma = false
	a, _ := testAkisi(t, c, is)
	ctx, bitir := context.WithTimeout(context.Background(), 40*time.Second)
	defer bitir()
	err := a.Kurulum(ctx)
	if err == nil || !strings.Contains(err.Error(), "installation is unsuccessful") {
		t.Fatalf("kurucu hatası beklenirdi, gelen: %v", err)
	}

	// Aynı konsol (ikinci okuyucu çıktıyı bölüşürdü), yeni olay listesi.
	var mu sync.Mutex
	olaylar := &[]string{}
	a.bildir = func(asama, mesaj string) {
		mu.Lock()
		*olaylar = append(*olaylar, asama+": "+mesaj)
		mu.Unlock()
	}
	c.kurulan = ""
	if err := a.Kurulum(ctx); err != nil {
		t.Fatalf("kabuktan dönüş: %v\nolaylar: %v\nkonsol: %q", err, *olaylar, a.k.Metin(0))
	}
	if c.kurulan == "" || !strings.Contains(strings.Join(*olaylar, "\n"), "kurulum kabuğu") {
		t.Errorf("kurulmadı ya da kabuk tanınmadı; olaylar: %v", *olaylar)
	}
}
