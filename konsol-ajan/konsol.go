package main

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"
	"sync"
	"time"

	"go.bug.st/serial"
	"go.bug.st/serial/enumerator"
)

// Hat konsol kablosunun kendisi. Testte sahte bir cihaz yerine geçer.
type Hat interface {
	Read(p []byte) (int, error)
	Write(p []byte) (int, error)
	Close() error
	HizAyarla(hiz int) error
}

// Denenecek hızlar, sırayla. Önce 115200;
// Juniper'ın fabrika hızı 9600 hemen ardından denenir.
var hizlar = []int{115200, 9600, 19200, 38400, 57600}

// portAdaylari önce USB-seri dönüştürücüler; yoksa bütün seri portlar.
func portAdaylari() ([]string, error) {
	ayrinti, err := enumerator.GetDetailedPortsList()
	if err == nil {
		var usb []string
		for _, p := range ayrinti {
			if p.IsUSB {
				usb = append(usb, p.Name)
			}
		}
		if len(usb) > 0 {
			return usb, nil
		}
	}
	return serial.GetPortsList()
}

// Konsol hattan gelen her şeyi biriktirir; akış istemleri buradan bekler,
// pompa da panele buradan aktarır.
type Konsol struct {
	hat Hat

	mu       sync.Mutex
	tampon   []byte // eşleştirme için son kısım
	bas      int64  // tamponun ilk baytının toplamdaki yeri
	panele   []byte // panele henüz gitmemiş çıktı
	yeni     chan struct{}
	okumaHat error

	hiz int // portun şu anki hızı; yalnız akışın goroutine'i değiştirir
}

const tamponSiniri = 64 << 10

func yeniKonsol(h Hat) *Konsol {
	k := &Konsol{hat: h, yeni: make(chan struct{}), hiz: hizlar[0]}
	go k.oku()
	return k
}

func (k *Konsol) oku() {
	b := make([]byte, 4096)
	for {
		n, err := k.hat.Read(b)
		k.mu.Lock()
		if n > 0 {
			k.tampon = append(k.tampon, b[:n]...)
			k.panele = append(k.panele, b[:n]...)
			if fazla := len(k.tampon) - tamponSiniri; fazla > 0 {
				k.tampon = append([]byte(nil), k.tampon[fazla:]...)
				k.bas += int64(fazla)
			}
			// Panel uzun süre ulaşılamazsa bellek şişmesin: en yenisi kalır.
			if fazla := len(k.panele) - 4*tamponSiniri; fazla > 0 {
				k.panele = append([]byte(nil), k.panele[fazla:]...)
			}
			close(k.yeni)
			k.yeni = make(chan struct{})
		}
		if err != nil {
			k.okumaHat = err
			close(k.yeni)
			k.yeni = make(chan struct{})
			k.mu.Unlock()
			return
		}
		k.mu.Unlock()
	}
}

// Konum şu ana kadar gelen bayt sayısı; Bekle'ye "bundan sonrası" demek için.
func (k *Konsol) Konum() int64 {
	k.mu.Lock()
	defer k.mu.Unlock()
	return k.bas + int64(len(k.tampon))
}

// Metin konumdan bu yana gelen çıktı (tampona sığdığı kadar).
func (k *Konsol) Metin(konum int64) string {
	k.mu.Lock()
	defer k.mu.Unlock()
	i := konum - k.bas
	if i < 0 {
		i = 0
	}
	if i > int64(len(k.tampon)) {
		return ""
	}
	return string(k.tampon[i:])
}

// PaneleAl panele gidecek birikmiş çıktıyı verir ve sıfırlar.
func (k *Konsol) PaneleAl() []byte {
	k.mu.Lock()
	defer k.mu.Unlock()
	b := k.panele
	k.panele = nil
	return b
}

func (k *Konsol) Yaz(s string) error {
	_, err := k.hat.Write([]byte(s))
	return err
}

var errSure = errors.New("süre doldu")

// Bekle konumdan sonraki çıktıda desenlerden biri görülene kadar bekler;
// görülenin sırasını döner.
func (k *Konsol) Bekle(ctx context.Context, konum int64, sure time.Duration, desenler ...*regexp.Regexp) (int, error) {
	son := time.NewTimer(sure)
	defer son.Stop()
	for {
		k.mu.Lock()
		metin := temizle(k.metinKilitli(konum))
		yeni, okumaHat := k.yeni, k.okumaHat
		k.mu.Unlock()
		for i, d := range desenler {
			if d.MatchString(metin) {
				return i, nil
			}
		}
		if okumaHat != nil {
			return -1, fmt.Errorf("konsol okunamıyor: %w", okumaHat)
		}
		select {
		case <-ctx.Done():
			return -1, ctx.Err()
		case <-son.C:
			return -1, errSure
		case <-yeni:
		}
	}
}

func (k *Konsol) metinKilitli(konum int64) string {
	i := konum - k.bas
	if i < 0 {
		i = 0
	}
	if i > int64(len(k.tampon)) {
		return ""
	}
	return string(k.tampon[i:])
}

var ansi = regexp.MustCompile(`\x1b\[[0-9;?]*[A-Za-z]|\x1b[()][0-9A-Za-z]|\x00`)

// temizle eşleştirmeyi bozan kaçış dizilerini ve satır başı karakterlerini atar.
func temizle(s string) string {
	s = ansi.ReplaceAllString(s, "")
	return strings.ReplaceAll(s, "\r", "")
}

// hizBul cihazın cevap verdiği hızı arar: Enter gönderir, gelen baytların
// okunur metin olup olmadığına bakar. Cihaz susuyorsa (kapalı, açılıyor)
// karar verilemez; varsayılan hızda kalınır.
func hizBul(ctx context.Context, k *Konsol) (hiz int, cevapVar bool, err error) {
	enIyi, enIyiPuan := hizlar[0], -1.0
	for _, h := range hizlar {
		if err := k.hat.HizAyarla(h); err != nil {
			return 0, false, err
		}
		time.Sleep(300 * time.Millisecond)
		konum := k.Konum()
		if err := k.Yaz("\r"); err != nil {
			return 0, false, err
		}
		select {
		case <-ctx.Done():
			return 0, false, ctx.Err()
		case <-time.After(1500 * time.Millisecond):
		}
		puan, adet := okunurluk(k.Metin(konum))
		if adet >= 3 && puan >= 0.9 {
			k.hiz = h
			return h, true, nil
		}
		if adet > 0 && puan > enIyiPuan {
			enIyi, enIyiPuan = h, puan
		}
	}
	if enIyiPuan < 0 {
		enIyi = hizlar[0]
	}
	if err := k.hat.HizAyarla(enIyi); err != nil {
		return 0, false, err
	}
	k.hiz = enIyi
	return enIyi, false, nil
}

// SonrakiHiz listede sıradaki hıza geçer (sona gelince başa döner).
func (k *Konsol) SonrakiHiz() (int, error) {
	i := 0
	for j, h := range hizlar {
		if h == k.hiz {
			i = j + 1
		}
	}
	yeni := hizlar[i%len(hizlar)]
	if err := k.hat.HizAyarla(yeni); err != nil {
		return 0, err
	}
	k.hiz = yeni
	return yeni, nil
}

func okunurluk(s string) (float64, int) {
	if s == "" {
		return 0, 0
	}
	iyi := 0
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c == '\r' || c == '\n' || c == '\t' || (c >= 0x20 && c < 0x7f) {
			iyi++
		}
	}
	return float64(iyi) / float64(len(s)), len(s)
}

// GeriKoy panele gidemeyen çıktıyı sıranın başına geri koyar.
func (k *Konsol) GeriKoy(b []byte) {
	if len(b) == 0 {
		return
	}
	k.mu.Lock()
	defer k.mu.Unlock()
	k.panele = append(append([]byte(nil), b...), k.panele...)
}
