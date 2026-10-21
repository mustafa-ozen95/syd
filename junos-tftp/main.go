// junos-tftp Junos görüntülerini TFTP ile verir: yalnız okuma, tek dizin.
//
// CEVAP DA 69'DAN GİDER (tek port). Switch'ler loader'da başka bir ağda,
// sunucu NAT'ın arkasında (istek yönlendiricinin adresinden geliyor). Sıradan
// TFTP cevabı yeni bir porttan verir, NAT onu tanımayıp geri çeviriyordu:
// tftpd-hpa "read: No route to host" yazar, loader "cannot open package
// (error 60)" der. 69'dan verilen cevap aynı yoldan birebir geçer.
//
// İlerleme bir durum dosyasına yazılır; panel iş sayfasında gösterir
// (belirsiz bir bekleme yerine).
package main

import (
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pin/tftp/v3"
)

// Aktarim tek bir dosya isteği.
type Aktarim struct {
	No          int64     `json:"no"`
	Dosya       string    `json:"dosya"`
	Istemci     string    `json:"istemci"`
	Boyut       int64     `json:"boyut"`
	Giden       int64     `json:"giden"`
	Basladi     time.Time `json:"basladi"`
	Guncellendi time.Time `json:"guncellendi"`
	Bitti       bool      `json:"bitti"`
	Hata        string    `json:"hata,omitempty"`

	giden atomic.Int64
}

// Sunucu dizini ve durum kaydı.
type Sunucu struct {
	kok   string
	durum string

	mu       sync.Mutex
	sayac    int64
	aktarim  []*Aktarim
	degisti  bool
	sonYazim time.Time
}

const kayitSiniri = 20

// blokSiniri TFTP'nin izin verdiği en büyük blok (kütüphane MTU'ya indirir).
const blokSiniri = 65456

var errAd = errors.New("geçersiz dosya adı")

// dosyaAdi yalnız dizindeki düz bir dosyaya izin verir: alt dizin, "..",
// gizli dosya yok. Loader adı "/" ile başlatabilir.
func dosyaAdi(ad string) (string, error) {
	ad = strings.TrimLeft(ad, "/")
	if ad == "" || strings.ContainsAny(ad, `/\`) || strings.HasPrefix(ad, ".") {
		return "", errAd
	}
	return ad, nil
}

func (s *Sunucu) oku(istenen string, rf io.ReaderFrom) error {
	ad, err := dosyaAdi(istenen)
	if err != nil {
		log.Printf("reddedildi: %q", istenen)
		return err
	}
	f, err := os.Open(filepath.Join(s.kok, ad))
	if err != nil {
		log.Printf("yok: %s (%v)", ad, err)
		return fmt.Errorf("%s bulunamadı", ad)
	}
	defer f.Close()
	bilgi, err := f.Stat()
	if err != nil || !bilgi.Mode().IsRegular() {
		return fmt.Errorf("%s düz dosya değil", ad)
	}

	istemci := ""
	if ot, ok := rf.(tftp.OutgoingTransfer); ok {
		ot.SetSize(bilgi.Size())
		adr := ot.RemoteAddr()
		istemci = adr.String()
	}
	a := s.yeni(ad, istemci, bilgi.Size())
	log.Printf("başladı #%d: %s → %s (%d bayt)", a.No, ad, istemci, bilgi.Size())

	_, err = rf.ReadFrom(&sayanOkuyucu{r: f, a: a})
	s.bitir(a, err)
	if err != nil {
		log.Printf("kesildi #%d: %s · %d/%d bayt · %v", a.No, ad, a.giden.Load(), a.Boyut, err)
	} else {
		log.Printf("bitti #%d: %s · %d bayt · %s", a.No, ad, a.giden.Load(), time.Since(a.Basladi).Round(time.Second))
	}
	return err
}

type sayanOkuyucu struct {
	r io.Reader
	a *Aktarim
}

func (o *sayanOkuyucu) Read(p []byte) (int, error) {
	n, err := o.r.Read(p)
	o.a.giden.Add(int64(n))
	return n, err
}

func (s *Sunucu) yeni(ad, istemci string, boyut int64) *Aktarim {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sayac++
	simdi := time.Now()
	a := &Aktarim{No: s.sayac, Dosya: ad, Istemci: istemci, Boyut: boyut, Basladi: simdi, Guncellendi: simdi}
	s.aktarim = append(s.aktarim, a)
	if len(s.aktarim) > kayitSiniri {
		s.aktarim = s.aktarim[len(s.aktarim)-kayitSiniri:]
	}
	s.degisti = true
	return a
}

func (s *Sunucu) bitir(a *Aktarim, err error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	a.Bitti = true
	if err != nil {
		a.Hata = err.Error()
	}
	s.degisti = true
}

// yaz durum dosyasını yazar (önce geçici dosya, sonra yeniden adlandırma:
// panel yarım dosya okumasın).
func (s *Sunucu) yaz() error {
	s.mu.Lock()
	simdi := time.Now()
	acik := false
	for _, a := range s.aktarim {
		g := a.giden.Load()
		if g != a.Giden {
			a.Giden, a.Guncellendi = g, simdi
			s.degisti = true
		}
		acik = acik || !a.Bitti
	}
	// Açık aktarım yokken dosya yalnız değişince yazılır.
	if !s.degisti && !acik {
		s.mu.Unlock()
		return nil
	}
	s.degisti = false
	b, err := json.Marshal(map[string]any{"guncellendi": simdi, "aktarimlar": s.aktarim})
	s.mu.Unlock()
	if err != nil {
		return err
	}
	gecici := s.durum + ".yeni"
	if err := os.WriteFile(gecici, b, 0o644); err != nil {
		return err
	}
	return os.Rename(gecici, s.durum)
}

func main() {
	kok := flag.String("kok", "/junos", "görüntülerin durduğu dizin (yalnız okunur)")
	adres := flag.String("adres", ":69", "dinlenecek adres")
	durum := flag.String("durum", "/durum/tftp.json", "ilerleme dosyası")
	flag.Parse()

	s := &Sunucu{kok: *kok, durum: *durum}
	if err := os.MkdirAll(filepath.Dir(*durum), 0o755); err != nil {
		log.Fatal(err)
	}
	go func() {
		for range time.Tick(time.Second) {
			if err := s.yaz(); err != nil {
				log.Printf("durum yazılamadı: %v", err)
			}
		}
	}()

	srv := tftp.NewServer(s.oku, nil) // yazma yok
	srv.EnableSinglePort()
	// Tek port kipi blok boyunu 512'ye sabitliyor; istenen blksize'a OACK'te
	// 512 denir. Junos kurucusu (EX2300, 24.4) OACK'i dikkate almıyor: 512
	// baytlık ilk bloğu "son blok" sanıp "truncated gzip input" ile düşer.
	// İstenen boy verilir; üst sınır arayüzün MTU'su.
	srv.SetBlockSize(blokSiniri)
	srv.SetTimeout(5 * time.Second)
	log.Printf("junos-tftp: %s · dizin %s · tek port", *adres, *kok)
	log.Fatal(srv.ListenAndServe(*adres))
}
