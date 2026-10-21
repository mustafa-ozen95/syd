// Konsol kurulum ajanı.
//
// Sahadaki PC'de çalışır, panelle (SYD) API jetonuyla konuşur. PC'ye takılı
// USB-seri konsol kablosundan switch'i sürer: cihazı loader'a indirir,
// `install --format tftp://...` ile Junos'u kurar, sürümü doğrular,
// istenmişse yedeği yükler. Konsolda görülen her şey panele canlı gider;
// panelden konsola elle de yazılabilir.
//
// Bağlantı hep AJANDAN panele açılır: PC'de dinlenen bir kapı yoktur.
// Görüntü switch'in kendi mgmt portundan TFTP sunucusundan iner; ajan dosya
// taşımaz.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// Derlemede -ldflags "-X main.surum=..." ile verilir.
var surum = "gelistirme"

// Ayar ajan.json'dan okunur; jeton komut satırından ALINMAZ (süreç
// listesinde görünürdü).
type Ayar struct {
	Panel       string `json:"panel"`
	Jeton       string `json:"jeton"`
	CADosyasi   string `json:"ca_dosyasi"`
	SeriPort    string `json:"seri_port"`
	SorguSaniye int    `json:"sorgu_saniye"`
}

func main() {
	ayarYolu := flag.String("ayar", "", "ayar dosyası (varsayılan: programın yanındaki ajan.json)")
	surumGoster := flag.Bool("surum", false, "sürümü yazar ve çıkar")
	portlar := flag.Bool("portlar", false, "seri portları listeler ve çıkar")
	portSina := flag.String("port-sina", "", "panelsiz: portu açar, hızı arar, sonucu yazar ve çıkar (\"oto\": ajanın seçtiği port)")
	flag.Parse()

	if *surumGoster {
		fmt.Println("konsol-ajan", surum)
		return
	}
	if *portlar {
		liste, err := portAdaylari()
		if err != nil {
			log.Fatal(err)
		}
		fmt.Println(strings.Join(liste, "\n"))
		return
	}
	if *portSina != "" {
		os.Exit(portuSina(*portSina))
	}

	yol := *ayarYolu
	if yol == "" {
		yol = filepath.Join(programDizini(), "ajan.json")
	}
	kayitAc(filepath.Join(filepath.Dir(yol), "ajan.log"))

	ayar, err := ayarOku(yol)
	if err != nil {
		log.Fatalf("ayar: %v", err)
	}
	panel, err := yeniPanel(ayar.Panel, ayar.Jeton, ayar.CADosyasi)
	if err != nil {
		log.Fatalf("panel: %v", err)
	}

	ctx, bitir := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer bitir()

	log.Printf("konsol-ajan %s · panel %s", surum, ayar.Panel)
	for {
		isler, err := panel.isler(ctx)
		if err != nil && ctx.Err() == nil {
			log.Printf("işler alınamadı: %v", err)
		}
		if len(isler) > 0 {
			isYap(ctx, panel, ayar, isler[0])
			continue
		}
		select {
		case <-ctx.Done():
			log.Print("kapatılıyor")
			return
		case <-time.After(time.Duration(ayar.SorguSaniye) * time.Second):
		}
	}
}

func programDizini() string {
	exe, err := os.Executable()
	if err != nil {
		return "."
	}
	return filepath.Dir(exe)
}

func kayitAc(yol string) {
	f, err := os.OpenFile(yol, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		log.Printf("kayıt dosyası açılamadı (%v); yalnız ekrana yazılıyor", err)
		return
	}
	log.SetOutput(io.MultiWriter(os.Stdout, f))
}

func ayarOku(yol string) (*Ayar, error) {
	b, err := os.ReadFile(yol)
	if err != nil {
		return nil, err
	}
	a := &Ayar{}
	if err := json.Unmarshal(b, a); err != nil {
		return nil, fmt.Errorf("%s okunamadı: %w", yol, err)
	}
	a.Panel = strings.TrimRight(a.Panel, "/")
	if a.Panel == "" || a.Jeton == "" {
		return nil, errors.New("panel ve jeton dolu olmalı")
	}
	if !strings.HasPrefix(a.Panel, "https://") && !strings.HasPrefix(a.Panel, "http://") {
		return nil, errors.New("panel adresi https:// ile başlamalı")
	}
	if a.CADosyasi != "" && !filepath.IsAbs(a.CADosyasi) {
		a.CADosyasi = filepath.Join(filepath.Dir(yol), a.CADosyasi)
	}
	if a.SorguSaniye <= 0 {
		a.SorguSaniye = 5
	}
	return a, nil
}

// isYap bir işi baştan sona yürütür ve sonucunu panele bildirir.
func isYap(ana context.Context, panel *Panel, ayar *Ayar, is Is) {
	ctx, iptal := context.WithCancel(ana)
	defer iptal()

	bildir := func(asama, mesaj string) {
		log.Printf("[%s] %s: %s", is.UUID, asama, mesaj)
		bctx, b := context.WithTimeout(ana, 20*time.Second)
		defer b()
		if err := panel.bildir(bctx, is.UUID, asama, mesaj); errors.Is(err, errCakisma) {
			iptal()
		} else if err != nil {
			log.Printf("bildirilemedi: %v", err)
		}
	}

	bildir("basladi", "Ajan işi aldı ("+surum+").")
	k, port, err := portAc(ctx, firstNonEmpty(is.SeriPort, ayar.SeriPort))
	if err != nil {
		bildir("hata", err.Error())
		return
	}
	defer k.hat.Close()

	pompaBitti := make(chan struct{})
	go func() {
		defer close(pompaBitti)
		pompa(ctx, panel, is.UUID, k, iptal)
	}()

	hiz, cevap, err := hizBul(ctx, k)
	if err != nil {
		bildir("hata", "Konsol hızı ayarlanamadı: "+err.Error())
		return
	}
	if cevap {
		bildir("port", fmt.Sprintf("Konsol: %s · %d baud (cihaz cevap verdi).", port, hiz))
	} else {
		bildir("port", fmt.Sprintf("Konsol: %s · %d baud (cihaz henüz cevap vermedi; hız varsayılan).", port, hiz))
	}

	dakika := is.KurulumDakika
	if dakika <= 0 {
		dakika = 240
	}
	akis := &Akis{
		k:      k,
		is:     is,
		bildir: bildir,
		yapilandirma: func(c context.Context) (string, error) {
			return panel.yapilandirma(c, is.UUID)
		},
		adresKullanimda: pingCevapVerir,
		acilisSuresi:    10 * time.Minute,
		kurulumSuresi:   time.Duration(dakika) * time.Minute,
		elleSuresi:      30 * time.Minute,
	}
	err = akis.Kurulum(ctx)

	// Son çıktı da panele gitsin, sonuç ondan sonra yazılsın.
	time.Sleep(1500 * time.Millisecond)
	switch {
	case ctx.Err() != nil:
		log.Printf("[%s] iş panelden kapatıldı", is.UUID)
	case err != nil:
		bildir("hata", err.Error())
	default:
		bildir("tamam", "Kurulum bitti.")
	}
	iptal()
	<-pompaBitti
}

func firstNonEmpty(s ...string) string {
	for _, v := range s {
		if v != "" {
			return v
		}
	}
	return ""
}

// portAc verilen portu, yoksa USB-seri dönüştürücüleri dener. Birden çok
// aday varsa cihazın cevap verdiği seçilir.
func portAc(ctx context.Context, ad string) (*Konsol, string, error) {
	if ad != "" {
		h, err := seriAc(ad)
		if err != nil {
			return nil, "", fmt.Errorf("%s açılamadı: %w", ad, err)
		}
		return yeniKonsol(h), ad, nil
	}
	adaylar, err := portAdaylari()
	if err != nil {
		return nil, "", fmt.Errorf("seri portlar listelenemedi: %w", err)
	}
	if len(adaylar) == 0 {
		return nil, "", errors.New("PC'de seri port yok: konsol kablosu (USB-seri dönüştürücü) takılı mı?")
	}
	if len(adaylar) == 1 {
		h, err := seriAc(adaylar[0])
		if err != nil {
			return nil, "", fmt.Errorf("%s açılamadı: %w", adaylar[0], err)
		}
		return yeniKonsol(h), adaylar[0], nil
	}
	var ilk string
	for _, a := range adaylar {
		h, err := seriAc(a)
		if err != nil {
			continue
		}
		k := yeniKonsol(h)
		if _, cevap, err := hizBul(ctx, k); err == nil && cevap {
			return k, a, nil
		}
		h.Close()
		if ilk == "" {
			ilk = a
		}
	}
	if ilk == "" {
		return nil, "", fmt.Errorf("seri portların hiçbiri açılamadı: %s", strings.Join(adaylar, ", "))
	}
	h, err := seriAc(ilk)
	if err != nil {
		return nil, "", err
	}
	return yeniKonsol(h), ilk, nil
}

// pompa konsol çıktısını saniyede bir panele taşır; dönüşte panelden elle
// yazılanları konsola yazar. İş panelde kapanınca işi durdurur.
func pompa(ctx context.Context, panel *Panel, uuid string, k *Konsol, iptal context.CancelFunc) {
	for {
		son := ctx.Err() != nil
		b := k.PaneleAl()
		pctx, bitir := context.WithTimeout(context.Background(), 15*time.Second)
		yanit, err := panel.cikti(pctx, uuid, b)
		bitir()
		switch {
		case errors.Is(err, errCakisma):
			iptal()
			return
		case err != nil:
			k.GeriKoy(b)
			log.Printf("çıktı panele gidemedi: %v", err)
		default:
			for _, g := range yanit.Girdiler {
				if err := k.Yaz(g); err != nil {
					log.Printf("konsola yazılamadı: %v", err)
				}
			}
			if yanit.Iptal {
				iptal()
			}
		}
		if son {
			return
		}
		select {
		case <-ctx.Done():
		case <-time.After(time.Second):
		}
	}
}

// portuSina sahadaki sınama: işi açmadan portun açılıp ayarlandığını ve
// cihazın hangi hızda cevap verdiğini gösterir. Cihaz kapalıysa hız
// bulunamaz; port açılıyorsa o da bir sonuçtur.
func portuSina(ad string) int {
	if ad == "oto" {
		ad = ""
	}
	ctx, iptal := context.WithTimeout(context.Background(), 2*time.Minute)
	defer iptal()
	k, port, err := portAc(ctx, ad)
	if err != nil {
		fmt.Println("HATA:", err)
		return 1
	}
	defer k.hat.Close()
	fmt.Println("açıldı:", port)
	hiz, cevap, err := hizBul(ctx, k)
	switch {
	case err != nil:
		fmt.Println("HATA: hız ayarlanamadı:", err)
		return 1
	case cevap:
		fmt.Printf("cihaz %d baud'da cevap verdi\n", hiz)
	default:
		fmt.Printf("cihaz cevap vermedi (kapalı ya da açılıyor); hız %d\n", hiz)
	}
	return 0
}
