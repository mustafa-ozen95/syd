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
//
// AYNI DİZİN HTTP'DEN DE (-http): Junos CLI'si ("request system software add")
// TFTP'den değil HTTP/FTP/SCP'den çeker. QFX5100, QFX5110 ve EX4600'de
// loader yok; bunlar CLI'den kurulur. Yalnız okuma, dizin listesi yok, yalnız
// dizindeki .tgz dosyaları.
package main

import (
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/pin/tftp/v3"
)

// Transfer tek bir dosya isteği.
type Transfer struct {
	Number  int64     `json:"number"`
	File    string    `json:"file"`
	Client  string    `json:"client"`
	Size    int64     `json:"size"`
	Sent    int64     `json:"sent"`
	Started time.Time `json:"started"`
	Updated time.Time `json:"updated"`
	Done    bool      `json:"done"`
	Error   string    `json:"error,omitempty"`

	sent atomic.Int64
}

// Server dizini ve durum kaydı.
type Server struct {
	root      string
	stateFile string

	mu        sync.Mutex
	counter   int64
	transfers []*Transfer
	changed   bool
	lastWrite time.Time
}

const recordLimit = 20

// blockLimit TFTP'nin izin verdiği en büyük blok (kütüphane MTU'ya indirir).
const blockLimit = 65456

var errName = errors.New("geçersiz dosya adı")

// fileName yalnız dizindeki düz bir dosyaya izin verir: alt dizin, "..",
// gizli dosya yok. Loader adı "/" ile başlatabilir.
func fileName(name string) (string, error) {
	name = strings.TrimLeft(name, "/")
	if name == "" || strings.ContainsAny(name, `/\`) || strings.HasPrefix(name, ".") {
		return "", errName
	}
	return name, nil
}

func (s *Server) readHandler(requested string, rf io.ReaderFrom) error {
	name, err := fileName(requested)
	if err != nil {
		log.Printf("reddedildi: %q", requested)
		return err
	}
	f, err := os.Open(filepath.Join(s.root, name))
	if err != nil {
		log.Printf("yok: %s (%v)", name, err)
		return fmt.Errorf("%s bulunamadı", name)
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil || !info.Mode().IsRegular() {
		return fmt.Errorf("%s düz dosya değil", name)
	}

	client := ""
	if ot, ok := rf.(tftp.OutgoingTransfer); ok {
		ot.SetSize(info.Size())
		remote := ot.RemoteAddr()
		client = remote.String()
	}
	a := s.newTransfer(name, client, info.Size())
	log.Printf("başladı #%d: %s → %s (%d bayt)", a.Number, name, client, info.Size())

	_, err = rf.ReadFrom(&countingReader{r: f, a: a})
	s.finish(a, err)
	if err != nil {
		log.Printf("kesildi #%d: %s · %d/%d bayt · %v", a.Number, name, a.sent.Load(), a.Size, err)
	} else {
		log.Printf("bitti #%d: %s · %d bayt · %s", a.Number, name, a.sent.Load(), time.Since(a.Started).Round(time.Second))
	}
	return err
}

type countingReader struct {
	r io.Reader
	a *Transfer
}

func (o *countingReader) Read(p []byte) (int, error) {
	n, err := o.r.Read(p)
	o.a.sent.Add(int64(n))
	return n, err
}

func (s *Server) newTransfer(name, client string, size int64) *Transfer {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.counter++
	now := time.Now()
	a := &Transfer{Number: s.counter, File: name, Client: client, Size: size, Started: now, Updated: now}
	s.transfers = append(s.transfers, a)
	if len(s.transfers) > recordLimit {
		s.transfers = s.transfers[len(s.transfers)-recordLimit:]
	}
	s.changed = true
	return a
}

func (s *Server) finish(a *Transfer, err error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	a.Done = true
	if err != nil {
		a.Error = err.Error()
	}
	s.changed = true
}

// writeState durum dosyasını yazar (önce geçici dosya, sonra yeniden adlandırma:
// panel yarım dosya okumasın).
func (s *Server) writeState() error {
	s.mu.Lock()
	now := time.Now()
	active := false
	for _, a := range s.transfers {
		g := a.sent.Load()
		if g != a.Sent {
			a.Sent, a.Updated = g, now
			s.changed = true
		}
		active = active || !a.Done
	}
	// Açık aktarım yokken dosya yalnız değişince yazılır.
	if !s.changed && !active {
		s.mu.Unlock()
		return nil
	}
	s.changed = false
	b, err := json.Marshal(map[string]any{"updated": now, "transfers": s.transfers})
	s.mu.Unlock()
	if err != nil {
		return err
	}
	tmp := s.stateFile + ".yeni"
	if err := os.WriteFile(tmp, b, 0o644); err != nil {
		return err
	}
	return os.Rename(tmp, s.stateFile)
}

func main() {
	root := flag.String("root", "/junos", "görüntülerin durduğu dizin (yalnız okunur)")
	addr := flag.String("addr", ":69", "dinlenecek adres")
	stateFile := flag.String("state", "/durum/tftp.json", "ilerleme dosyası")
	httpAddr := flag.String("http", "", "dosyaları HTTP'den de ver (ör. :8069); boşsa kapalı")
	flag.Parse()

	s := &Server{root: *root, stateFile: *stateFile}
	if err := os.MkdirAll(filepath.Dir(*stateFile), 0o755); err != nil {
		log.Fatal(err)
	}
	go func() {
		for range time.Tick(time.Second) {
			if err := s.writeState(); err != nil {
				log.Printf("durum yazılamadı: %v", err)
			}
		}
	}()

	if *httpAddr != "" {
		go func() {
			log.Printf("junos-tftp: HTTP %s · dizin %s", *httpAddr, *root)
			log.Fatal(http.ListenAndServe(*httpAddr, s.httpHandler()))
		}()
	}

	srv := tftp.NewServer(s.readHandler, nil) // yazma yok
	srv.EnableSinglePort()
	// Tek port kipi blok boyunu 512'ye sabitliyor; istenen blksize'a OACK'te
	// 512 denir. Junos kurucusu (EX2300, 24.4) OACK'i dikkate almıyor: 512
	// baytlık ilk bloğu "son blok" sanıp "truncated gzip input" ile düşer.
	// İstenen boy verilir; üst sınır arayüzün MTU'su.
	srv.SetBlockSize(blockLimit)
	srv.SetTimeout(5 * time.Second)
	log.Printf("junos-tftp: %s · dizin %s · tek port", *addr, *root)
	log.Fatal(srv.ListenAndServe(*addr))
}

// httpHandler dizindeki .tgz dosyalarını verir; başka hiçbir şeyi değil:
// dizin listesi, alt dizin, gizli dosya (yükleniyor-…) ya da üst dizin yok.
func (s *Server) httpHandler() http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet && r.Method != http.MethodHead {
			http.Error(w, "yalnız okuma", http.StatusMethodNotAllowed)
			return
		}
		name := strings.TrimPrefix(r.URL.Path, "/")
		if name == "" || strings.ContainsAny(name, "/\\") || strings.HasPrefix(name, ".") || !strings.HasSuffix(name, ".tgz") {
			http.NotFound(w, r)
			return
		}
		path := filepath.Join(s.root, name)
		info, err := os.Stat(path)
		if err != nil || !info.Mode().IsRegular() {
			http.NotFound(w, r)
			return
		}
		log.Printf("HTTP: %s → %s (%d bayt)", name, r.RemoteAddr, info.Size())
		http.ServeFile(w, r, path)
	})
}
