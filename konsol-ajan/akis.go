package main

import (
	"context"
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"runtime"
	"strings"
	"time"
)

/*
 * KONSOLDAN KURULUM AKIŞI — elle kurulumun aynısı:
 *
 *   cihaz ne durumdaysa loader'a indirilir (çalışıyorsa yeniden başlatılır,
 *   açılışta art arda boşluk), loader'da adres verilir,
 *   `install --format tftp://...` yazılır, cihaz açılınca sürüm doğrulanır,
 *   istenmişse yedek `load override terminal` ile yüklenip commit edilir.
 *
 * Komutların metni panelden gelir (iş tanımı); burada yalnız istemler
 * beklenir. İstemler cihazın son satırına bakılarak tanınır.
 */

var (
	// Junos'un loader istemi "loader>"; FreeBSD'nin kendi istemi "OK".
	reLoader = regexp.MustCompile(`(loader>|(^|\n)OK)\s*$`)
	reUboot  = regexp.MustCompile(`(^|\n)\s*=>\s*$`)
	reDb     = regexp.MustCompile(`db>\s*$`)
	reMount  = regexp.MustCompile(`mountroot>\s*$`)
	reLogin  = regexp.MustCompile(`login:\s*$`)
	reParola = regexp.MustCompile(`(?i)password:\s*$`)
	reKabuk  = regexp.MustCompile(`%\s*$`)
	reConf   = regexp.MustCompile(`#\s*$`)
	// Junos kurucusu düşünce açılan kabuk: satır başında yalnız "#"
	// (yapılandırma modunda önünde "root" vardır). "exit" yeniden başlatır
	// (kurucu: "A shell has been started. Type exit<cr> to reboot").
	reKurucu = regexp.MustCompile(`(^|\n)#\s*$`)
	// Kurucunun kendi hata yazısı: beklemek boşuna.
	reKurucuHata = regexp.MustCompile(`(?i)installation is unsuccessful|installer exited with status`)
	reCli        = regexp.MustCompile(`[\w.@:-]+>\s*$`)
	reEvetHayr   = regexp.MustCompile(`\[yes,no\][^\n]*$`)
	reYanlis     = regexp.MustCompile(`(?i)login incorrect`)
	// Açılışın ya da kapanışın başladığını gösteren yazılar: boşluk ancak
	// bunlardan sonra gönderilir (giriş istemine boşluk yazılmasın).
	reAcilis = regexp.MustCompile(`(?i)u-boot|hit any key|bootstrap loader|loading /boot|kernel data=|booting \[|space bar|copyright \(c\)|rebooting|syncing disks|shutdown now|uptime:|power-on|board:|dram:`)
	// EX2300/QFX5100 gibi yeni platformlar konsolu Enter ile açar.
	reEtkinlestir = regexp.MustCompile(`(?i)press enter to activate this console\.?\s*$`)
	// EX2300/QFX5100'ün açılış menüsü: otomatik seçilmez, operatöre söylenir.
	reMenu = regexp.MustCompile(`(?i)choice:\s*$`)
	// EX2300'ün loader'ı boşlukla durmaz ("Autoboot in 3 seconds... (press
	// Ctrl-C to interrupt)").
	reAutoboot = regexp.MustCompile(`(?i)press ctrl-c to interrupt`)
	// Menüde loader istemine götüren madde ve "daha fazla" maddesi; numara
	// ekrandan okunur.
	reMenuIstem  = regexp.MustCompile(`(?i)(\d+)\.\s*[^\n\d]{0,30}?(\[b\]oot prompt|boot \[p\]rompt|loader \[?p\]?rompt|escape to loader)`)
	reMenuDaha   = regexp.MustCompile(`(?i)(\d+)\.\s*\[m\]ore options`)
	reYukleme    = regexp.MustCompile(`\[Type \^D at a new line to end input\]`)
	reYuklendi   = regexp.MustCompile(`load complete`)
	reCommit     = regexp.MustCompile(`commit complete`)
	reHata       = regexp.MustCompile(`(?i)(syntax error|error:|failed|missing mandatory|load complete \(\d+ errors?\))`)
	reLoaderHata = regexp.MustCompile(`(?i)(not found|unknown command|invalid|error)`)
)

type durum int

const (
	dSessiz durum = iota
	dLoader
	dUboot
	dDb
	dMount
	dEvetHayir
	dLogin
	dParola
	dKabuk
	dKurucu
	dConf
	dCli
	dEtkinlestir
	dMenu
)

var durumAdi = map[durum]string{
	dSessiz: "sessiz", dLoader: "loader", dUboot: "U-Boot", dDb: "db (çekirdek hata ayıklayıcı)",
	dMount: "mountroot", dEvetHayir: "onay sorusu", dLogin: "giriş", dParola: "parola sorusu",
	dKabuk: "kabuk", dKurucu: "kurulum kabuğu (yarım kalmış kurulum)", dConf: "yapılandırma modu", dCli: "CLI",
	dEtkinlestir: "konsolu etkinleştirme sorusu", dMenu: "açılış menüsü",
}

// Sıra önemli: "loader>" ve "db>" CLI istemine de benzer.
var durumDesenleri = []struct {
	d  durum
	re *regexp.Regexp
}{
	{dLoader, reLoader}, {dEtkinlestir, reEtkinlestir}, {dMenu, reMenu}, {dUboot, reUboot}, {dDb, reDb}, {dMount, reMount}, {dEvetHayir, reEvetHayr},
	{dLogin, reLogin}, {dParola, reParola}, {dKabuk, reKabuk}, {dKurucu, reKurucu}, {dConf, reConf}, {dCli, reCli},
}

var (
	errKacti  = errors.New("loader kaçırıldı: cihaz açılıp giriş istemine geldi")
	errParola = errors.New("cihaza girilemedi: root parolası yok ya da yanlış")
)

type Bildirici func(asama, mesaj string)

type Akis struct {
	k      *Konsol
	is     Is
	bildir Bildirici
	// yapilandirma panelden yedek metnini alır; diske yazılmaz.
	yapilandirma func(ctx context.Context) (string, error)
	// adresKullanimda kurulum adresine başka bir cihazın cevap verip
	// vermediğini söyler (testte sahte).
	adresKullanimda func(ip string) bool
	// Bekleme süreleri (testte kısaltılır).
	acilisSuresi  time.Duration
	kurulumSuresi time.Duration
	elleSuresi    time.Duration
}

func (a *Akis) yaz(s string) error { return a.k.Yaz(s) }

// durumBul Enter gönderip son istemi tanır.
func (a *Akis) durumBul(ctx context.Context) (durum, error) {
	konum := a.k.Konum()
	if err := a.yaz("\r"); err != nil {
		return dSessiz, err
	}
	desenler := make([]*regexp.Regexp, len(durumDesenleri))
	for i, d := range durumDesenleri {
		desenler[i] = d.re
	}
	i, err := a.k.Bekle(ctx, konum, 4*time.Second, desenler...)
	if errors.Is(err, errSure) {
		return dSessiz, nil
	}
	if err != nil {
		return dSessiz, err
	}
	return durumDesenleri[i].d, nil
}

// loaderaIn cihazı hangi durumdaysa loader istemine getirir.
func (a *Akis) loaderaIn(ctx context.Context) error {
	for deneme := 0; deneme < 6; deneme++ {
		bas := a.k.Konum()
		d, err := a.durumBul(ctx)
		if err != nil {
			return err
		}
		a.bildir("bilgi", "Konsolda görülen: "+durumAdi[d]+".")

		switch d {
		case dLoader:
			return nil
		case dEvetHayir:
			if err := a.yaz("no\r"); err != nil {
				return err
			}
			continue
		case dEtkinlestir:
			continue // durumBul zaten Enter gönderiyor
		case dMenu:
			if secim := menuSecimi(temizle(a.k.Metin(bas))); secim != "" {
				a.bildir("bilgi", "Açılış menüsü: "+secim+" seçiliyor.")
				konum := a.k.Konum()
				if err := a.yaz(secim + "\r"); err != nil {
					return err
				}
				err = a.acilisiBekleBuradan(ctx, a.acilisSuresi, konum)
				break
			}
			a.bildir("uyari", "Cihaz açılış menüsünde (Choice:) ama loader maddesi okunamadı. Menüden loader'a ([B]oot prompt) inmek için konsola elle yazın ya da cihazın elektriğini çekip takın.")
			err = a.acilisiBekle(ctx, a.elleSuresi)
		case dKurucu:
			a.bildir("bilgi", "Önceki kurulum yarım kalmış (kurulum kabuğu); cihaz exit ile yeniden başlatılıyor.")
			if err := a.yaz("exit\r"); err != nil {
				return err
			}
			err = a.acilisiBekle(ctx, a.acilisSuresi)
		case dConf:
			konum := a.k.Konum()
			if err := a.yaz("exit configuration-mode\r"); err != nil {
				return err
			}
			// Commit edilmemiş değişiklik varsa sorar; atılır.
			i, err := a.k.Bekle(ctx, konum, 10*time.Second, reCli, reEvetHayr)
			if err == nil && i == 1 {
				err = a.yaz("yes\r")
			}
			if err != nil && !errors.Is(err, errSure) {
				return err
			}
			continue
		case dKabuk:
			if err := a.komut(ctx, "cli", 20*time.Second, reCli); err != nil && !errors.Is(err, errSure) {
				return err
			}
			continue
		case dParola:
			// Yarım kalmış bir giriş: boş geçilir, giriş istemi yeniden gelir.
			if err := a.yaz("\r"); err != nil {
				return err
			}
			time.Sleep(3 * time.Second)
			continue
		case dLogin:
			if err := a.giris(ctx); err != nil {
				if !errors.Is(err, errParola) {
					return err
				}
				a.bildir("uyari", "Cihaza girilemedi (root parolası yok ya da yanlış). Cihazın elektriğini çekip takın; açılışı bekliyorum, loader'a kendim gireceğim.")
				err = a.acilisiBekle(ctx, a.elleSuresi)
				break
			}
			continue
		case dCli:
			a.bildir("bilgi", "Cihaz çalışıyor; loader'a inmek için yeniden başlatılıyor.")
			if err := a.yenidenBaslat(ctx); err != nil {
				return err
			}
			err = a.acilisiBekle(ctx, a.acilisSuresi)
		case dUboot:
			a.bildir("bilgi", "U-Boot isteminde; loader açılıyor.")
			if err := a.yaz("boot\r"); err != nil {
				return err
			}
			err = a.acilisiBekle(ctx, a.acilisSuresi)
		case dDb:
			a.bildir("bilgi", "Cihaz çekirdek hata ayıklayıcısında (db>); yeniden başlatılıyor.")
			if err := a.yaz("reset\r"); err != nil {
				return err
			}
			err = a.acilisiBekle(ctx, a.acilisSuresi)
		default: // sessiz, mountroot
			a.bildir("uyari", "Cihaza elektrik verin (açıksa çekip takın). Cihaz konsoldan cevap vermiyor ("+durumAdi[d]+"); açılışı bekliyorum, loader'a kendim gireceğim.")
			// Sorulurken gelen (belki bozuk hızda) çıktı da sayılır.
			err = a.acilisiBekleBuradan(ctx, a.elleSuresi, bas)
		}

		switch {
		case err == nil:
			return nil
		case errors.Is(err, errMenu):
			// Menüye Enter yazılmasın (varsayılan maddeyi açabilir): yalnız
			// operatörün loader'a inmesi beklenir.
			a.bildir("uyari", "Açılış menüsünde loader maddesi okunamadı. Menüden loader'a ([B]oot prompt) konsoldan elle inin; loader istemini görünce devam ederim.")
			if _, err := a.k.Bekle(ctx, a.k.Konum(), a.elleSuresi, reLoader); err != nil {
				return fmt.Errorf("loader istemi gelmedi: %w", err)
			}
			return nil
		case errors.Is(err, errKacti):
			a.bildir("bilgi", "Loader kaçırıldı; yeniden deneniyor.")
		default:
			return err
		}
	}
	return errors.New("cihaz birkaç denemede loader'a indirilemedi")
}

// komut bir satır yazar ve desenlerden birini bekler.
func (a *Akis) komut(ctx context.Context, satir string, sure time.Duration, desenler ...*regexp.Regexp) error {
	konum := a.k.Konum()
	if err := a.yaz(satir + "\r"); err != nil {
		return err
	}
	_, err := a.k.Bekle(ctx, konum, sure, desenler...)
	return err
}

// giris giriş isteminden root olarak kabuğa ya da CLI'ye geçer.
func (a *Akis) giris(ctx context.Context) error {
	konum := a.k.Konum()
	if err := a.yaz("root\r"); err != nil {
		return err
	}
	i, err := a.k.Bekle(ctx, konum, 15*time.Second, reParola, reKabuk, reCli, reYanlis, reLogin)
	if err != nil {
		return fmt.Errorf("giriş: %w", err)
	}
	if i == 0 {
		konum = a.k.Konum()
		if err := a.yaz(a.is.Parola + "\r"); err != nil {
			return err
		}
		i, err = a.k.Bekle(ctx, konum, 30*time.Second, reKabuk, reCli, reYanlis, reLogin)
		if err != nil {
			return fmt.Errorf("giriş: %w", err)
		}
		i++
	}
	switch i {
	case 1: // kabuk
		if err := a.komut(ctx, "cli", 20*time.Second, reCli); err != nil {
			return fmt.Errorf("cli açılmadı: %w", err)
		}
		return nil
	case 2:
		return nil
	default:
		return errParola
	}
}

func (a *Akis) yenidenBaslat(ctx context.Context) error {
	if err := a.komut(ctx, "request system reboot", 20*time.Second, reEvetHayr); err != nil {
		return fmt.Errorf("yeniden başlatma sorusu gelmedi: %w", err)
	}
	return a.yaz("yes\r")
}

var errMenu = errors.New("açılış menüsünde loader maddesi okunamadı")

// menuSecimi menüde loader istemine götüren maddenin numarası; o yoksa
// "daha fazla seçenek"in numarası. İkisi de yoksa boş.
func menuSecimi(metin string) string {
	for _, re := range []*regexp.Regexp{reMenuIstem, reMenuDaha} {
		if len(metin) > 4000 {
			metin = metin[len(metin)-4000:]
		}
		if m := re.FindAllStringSubmatch(metin, -1); len(m) > 0 {
			return m[len(m)-1][1]
		}
	}
	return ""
}

// bozuklukOrnegi hızın tutup tutmadığına karar vermek için gereken bayt.
const bozuklukOrnegi = 24

// acilisiBekle açılış başlayınca art arda boşluk göndererek loader
// istemini yakalar. U-Boot'ta durulursa "boot" ile loader'a geçilir.
//
// Cihaz kapalıyken hız bulunamaz; ajan varsayılan hızda bekler. Cihaz başka
// hızda açılırsa (ör. elektrik ajan hazır olduktan sonra verildiyse) gelen
// baytlar bozuk görünür: okunur metin
// görülene kadar sıradaki hıza geçilir.
func (a *Akis) acilisiBekle(ctx context.Context, sure time.Duration) error {
	return a.acilisiBekleBuradan(ctx, sure, a.k.Konum())
}

func (a *Akis) acilisiBekleBuradan(ctx context.Context, sure time.Duration, konum int64) error {
	son := time.Now().Add(sure)
	acildi := false
	uboot := 0
	hizTuttu, hizDegisimi := false, 0
	ctrlc, menu, bosluk := 0, 0, true
	for time.Now().Before(son) {
		if !hizTuttu && !acildi {
			ham := a.k.Metin(konum)
			if puan, adet := okunurluk(ham); adet >= bozuklukOrnegi {
				if puan >= 0.8 {
					hizTuttu = true
				} else if hizDegisimi < 2*len(hizlar) {
					hizDegisimi++
					eski := a.k.hiz
					yeni, err := a.k.SonrakiHiz()
					if err != nil {
						return fmt.Errorf("konsol hızı değiştirilemedi: %w", err)
					}
					a.bildir("bilgi", fmt.Sprintf("Konsolda bozuk karakterler: %d baud tutmuyor, %d deneniyor.", eski, yeni))
					konum = a.k.Konum()
					continue
				}
			}
		}
		metin := temizle(a.k.Metin(konum))
		switch {
		case reLoader.MatchString(metin):
			return nil
		case reUboot.MatchString(metin) && uboot < 3:
			// Boşluk U-Boot'un geri sayımını da durdurur; "boot" ile loader
			// açılır, boşluk loader'ın yazısı görülünce yeniden başlar.
			uboot++
			acildi = false
			konum = a.k.Konum()
			if err := a.yaz("boot\r"); err != nil {
				return err
			}
			continue
		case acildi && reLogin.MatchString(metin):
			return errKacti
		case reAutoboot.MatchString(metin) && ctrlc < 5:
			if ctrlc == 0 {
				a.bildir("acilis", "Autoboot geri sayımı görüldü; Ctrl-C gönderiliyor.")
			}
			ctrlc++
			acildi, bosluk = true, false // boşluk menünün istemine yazılmasın
			konum = a.k.Konum()
			if err := a.yaz("\x03"); err != nil {
				return err
			}
			continue
		case reMenu.MatchString(metin):
			secim := menuSecimi(metin)
			if secim == "" || menu >= 4 {
				return errMenu
			}
			menu++
			a.bildir("bilgi", "Açılış menüsü: "+secim+" seçiliyor.")
			konum = a.k.Konum()
			if err := a.yaz(secim + "\r"); err != nil {
				return err
			}
			continue
		case reEtkinlestir.MatchString(metin):
			konum = a.k.Konum()
			if err := a.yaz("\r"); err != nil {
				return err
			}
			continue
		}
		if !acildi && reAcilis.MatchString(metin) {
			acildi = true
			a.bildir("acilis", "Açılış görüldü; loader için boşluk gönderiliyor.")
		}
		if acildi && bosluk {
			if err := a.yaz(" "); err != nil {
				return err
			}
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(150 * time.Millisecond):
		}
	}
	if acildi {
		return errors.New("açılış görüldü ama loader istemi gelmedi")
	}
	return errors.New("cihaz açılmadı: konsolda hiçbir şey görülmedi")
}

// Kurulum bütün akış.
func (a *Akis) Kurulum(ctx context.Context) error {
	a.bildir("loader", "Cihaz loader'a indiriliyor.")
	if err := a.loaderaIn(ctx); err != nil {
		return err
	}
	// Yakalarken yazılan boşluklar satırda kalmasın: boş satırla temiz istem.
	time.Sleep(500 * time.Millisecond)
	if err := a.komut(ctx, "", 5*time.Second, reLoader); err != nil {
		return fmt.Errorf("loader istemi: %w", err)
	}
	a.bildir("loader", "Loader istemindeyim.")

	if a.is.CihazIP != "" && a.adresKullanimda(a.is.CihazIP) {
		return fmt.Errorf("kurulum adresi %s ağda başka bir cihazda kullanılıyor; işteki adresi değiştirin ya da DHCP seçin", a.is.CihazIP)
	}

	for _, satir := range a.is.LoaderKomutlari {
		konum := a.k.Konum()
		if err := a.komut(ctx, satir, 15*time.Second, reLoader); err != nil {
			return fmt.Errorf("loader komutu (%s): %w", satir, err)
		}
		if cevap := cevapSatirlari(a.k.Metin(konum), satir); reLoaderHata.MatchString(cevap) {
			return fmt.Errorf("loader komutu reddetti (%s): %s", satir, cevap)
		}
	}

	a.bildir("kurulum", "Kurulum başladı: "+a.is.KurulumKomutu)
	konum := a.k.Konum()
	if err := a.yaz(a.is.KurulumKomutu + "\r"); err != nil {
		return err
	}
	if err := a.kurulumuIzle(ctx, konum); err != nil {
		return err
	}
	a.bildir("kurulum", "Cihaz yeni yazılımla açıldı.")

	if err := a.giris(ctx); err != nil {
		return fmt.Errorf("kurulumdan sonra giriş: %w", err)
	}
	if err := a.surumuDogrula(ctx); err != nil {
		return err
	}
	if a.is.Yapilandirma {
		if err := a.yapilandirmaYukle(ctx); err != nil {
			return err
		}
	}
	// Konsol oturumu açık bırakılmaz.
	_ = a.komut(ctx, "exit", 10*time.Second, reLogin, reKabuk)
	if reKabuk.MatchString(temizle(a.k.Metin(a.k.Konum() - 100))) {
		_ = a.komut(ctx, "exit", 10*time.Second, reLogin)
	}
	return nil
}

// kurulumuIzle install komutundan cihazın giriş istemine gelmesine kadar.
// Loader istemi geri gelirse kurulum başarısızdır (loader hata verince
// isteme döner).
func (a *Akis) kurulumuIzle(ctx context.Context, konum int64) error {
	son := time.Now().Add(a.kurulumSuresi)
	basla := time.Now()
	sonBildirim := time.Now()
	for {
		kalan := time.Until(son)
		if kalan <= 0 {
			return fmt.Errorf("kurulum %s içinde bitmedi", a.kurulumSuresi)
		}
		bekle := 30 * time.Second
		if kalan < bekle {
			bekle = kalan
		}
		i, err := a.k.Bekle(ctx, konum, bekle, reLoader, reUboot, reLogin, reEtkinlestir, reKurucuHata)
		switch {
		case err == nil && i == 4:
			return fmt.Errorf("Junos kurucusu başarısız: %s", sonSatirlar(a.k.Metin(konum), 8))
		case err == nil && i == 3:
			konum = a.k.Konum()
			if err := a.yaz("\r"); err != nil {
				return err
			}
		case err == nil && i == 0:
			return fmt.Errorf("loader kurulumu yapmadı: %s", sonSatirlar(a.k.Metin(konum), 6))
		case err == nil && i == 1:
			return errors.New("kurulum sırasında cihaz U-Boot istemine düştü")
		case err == nil:
			return nil
		case errors.Is(err, errSure):
			if time.Since(sonBildirim) >= 5*time.Minute {
				sonBildirim = time.Now()
				a.bildir("kurulum", fmt.Sprintf("Kurulum sürüyor (%d dk).", int(time.Since(basla).Minutes())))
			}
		default:
			return err
		}
	}
}

func (a *Akis) surumuDogrula(ctx context.Context) error {
	konum := a.k.Konum()
	if err := a.komut(ctx, "show version | no-more", 60*time.Second, reCli); err != nil {
		return fmt.Errorf("show version: %w", err)
	}
	cikti := temizle(a.k.Metin(konum))
	if a.is.BeklenenSurum == "" {
		a.bildir("dogrulama", "Sürüm: "+surumSatiri(cikti))
		return nil
	}
	if !strings.Contains(cikti, a.is.BeklenenSurum) {
		return fmt.Errorf("cihazda beklenen sürüm (%s) görülmedi: %s", a.is.BeklenenSurum, surumSatiri(cikti))
	}
	a.bildir("dogrulama", "Sürüm doğrulandı: "+a.is.BeklenenSurum)
	return nil
}

func (a *Akis) yapilandirmaYukle(ctx context.Context) error {
	metin, err := a.yapilandirma(ctx)
	if err != nil {
		return fmt.Errorf("yapılandırma alınamadı: %w", err)
	}
	a.bildir("yapilandirma", fmt.Sprintf("Yapılandırma yükleniyor (%d satır).", strings.Count(metin, "\n")+1))

	if err := a.komut(ctx, "configure", 20*time.Second, reConf); err != nil {
		return fmt.Errorf("configure: %w", err)
	}
	if a.is.OnceSil {
		if err := a.komut(ctx, "delete", 10*time.Second, reEvetHayr); err != nil {
			return fmt.Errorf("delete: %w", err)
		}
		if err := a.komut(ctx, "yes", 20*time.Second, reConf); err != nil {
			return fmt.Errorf("delete: %w", err)
		}
	}
	if err := a.komut(ctx, a.is.YuklemeKomutu, 20*time.Second, reYukleme); err != nil {
		return fmt.Errorf("%s: %w", a.is.YuklemeKomutu, err)
	}

	konum := a.k.Konum()
	for _, satir := range strings.Split(strings.ReplaceAll(metin, "\r", ""), "\n") {
		if err := a.satirGonder(ctx, satir); err != nil {
			return err
		}
	}
	if err := a.yaz("\x04"); err != nil {
		return err
	}
	if _, err := a.k.Bekle(ctx, konum, 3*time.Minute, reYuklendi, reConf); err != nil {
		return fmt.Errorf("yükleme bitmedi: %w", err)
	}
	if cevap := temizle(a.k.Metin(konum)); reHata.MatchString(cevap) {
		_ = a.komut(ctx, "rollback 0", 20*time.Second, reConf)
		_ = a.komut(ctx, "exit configuration-mode", 20*time.Second, reCli)
		return fmt.Errorf("yapılandırma hatalı, geri alındı: %s", hataSatirlari(cevap))
	}

	a.bildir("yapilandirma", "Yüklendi; commit ediliyor.")
	konum = a.k.Konum()
	if err := a.yaz("commit and-quit\r"); err != nil {
		return err
	}
	i, err := a.k.Bekle(ctx, konum, 10*time.Minute, reCommit, reHata)
	if err != nil {
		return fmt.Errorf("commit: %w", err)
	}
	if i == 1 {
		_ = a.komut(ctx, "rollback 0", 20*time.Second, reConf)
		_ = a.komut(ctx, "exit configuration-mode", 20*time.Second, reCli)
		return fmt.Errorf("commit reddedildi, geri alındı: %s", hataSatirlari(temizle(a.k.Metin(konum))))
	}
	if _, err := a.k.Bekle(ctx, konum, time.Minute, reCli); err != nil {
		return fmt.Errorf("commit sonrası: %w", err)
	}
	a.bildir("yapilandirma", "Yapılandırma commit edildi.")
	return nil
}

// satirGonder bir satırı yazar ve cihazın yankısını bekler: düşük hızda
// yapıştırılan metin cihazın girişini taşırmasın.
func (a *Akis) satirGonder(ctx context.Context, satir string) error {
	konum := a.k.Konum()
	if err := a.yaz(satir + "\n"); err != nil {
		return err
	}
	beklenen := int64(len(satir))
	son := time.Now().Add(5 * time.Second)
	for a.k.Konum()-konum < beklenen && time.Now().Before(son) {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(10 * time.Millisecond):
		}
	}
	return nil
}

// cevapSatirlari komutun yankısından sonra gelenler (istem hariç).
func cevapSatirlari(cikti, satir string) string {
	s := temizle(cikti)
	if i := strings.Index(s, satir); i >= 0 {
		s = s[i+len(satir):]
	}
	s = reLoader.ReplaceAllString(s, "")
	return strings.TrimSpace(s)
}

func sonSatirlar(cikti string, n int) string {
	satirlar := strings.Split(strings.TrimSpace(temizle(cikti)), "\n")
	if len(satirlar) > n {
		satirlar = satirlar[len(satirlar)-n:]
	}
	return strings.Join(satirlar, " | ")
}

func hataSatirlari(cikti string) string {
	var bulunan []string
	for _, s := range strings.Split(cikti, "\n") {
		if reHata.MatchString(s) {
			bulunan = append(bulunan, strings.TrimSpace(s))
		}
		if len(bulunan) == 5 {
			break
		}
	}
	return strings.Join(bulunan, " | ")
}

var reJunos = regexp.MustCompile(`(?m)^(JUNOS|Junos:)[^\n]*`)

func surumSatiri(cikti string) string {
	if m := reJunos.FindString(cikti); m != "" {
		return strings.TrimSpace(m)
	}
	return sonSatirlar(cikti, 3)
}

// pingCevapVerir: ICMP için yetki gerekmesin diye sistemin ping'i. Windows'ta
// "ulaşılamıyor" yanıtında da çıkış kodu 0 olabildiği için TTL aranır.
func pingCevapVerir(ip string) bool {
	var cmd *exec.Cmd
	if runtime.GOOS == "windows" {
		cmd = exec.Command("ping", "-n", "2", "-w", "1000", ip)
	} else {
		cmd = exec.Command("ping", "-c", "2", "-W", "1", ip)
	}
	cikti, _ := cmd.CombinedOutput()
	return strings.Contains(strings.ToLower(string(cikti)), "ttl=")
}
