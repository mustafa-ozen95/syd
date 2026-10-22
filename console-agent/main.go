// Console kurulum ajanı.
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
var version = "gelistirme"

// Config agent.json'dan okunur; jeton komut satırından ALINMAZ (süreç
// listesinde görünürdü).
type Config struct {
	Panel       string `json:"panel"`
	Token       string `json:"token"`
	CAFile      string `json:"ca_file"`
	SerialPort  string `json:"serial_port"`
	PollSeconds int    `json:"poll_seconds"`
}

func main() {
	configPath := flag.String("config", "", "ayar dosyası (varsayılan: programın yanındaki agent.json)")
	showVersion := flag.Bool("version", false, "sürümü yazar ve çıkar")
	listPorts := flag.Bool("ports", false, "seri portları listeler ve çıkar")
	portTest := flag.String("port-test", "", "panelsiz: portu açar, hızı arar, sonucu yazar ve çıkar (\"oto\": ajanın seçtiği port)")
	flag.Parse()

	if *showVersion {
		fmt.Println("console-agent", version)
		return
	}
	if *listPorts {
		list, err := portCandidates()
		if err != nil {
			log.Fatal(err)
		}
		fmt.Println(strings.Join(list, "\n"))
		return
	}
	if *portTest != "" {
		os.Exit(testPort(*portTest))
	}

	path := *configPath
	if path == "" {
		path = filepath.Join(programDir(), "agent.json")
	}
	openLog(filepath.Join(filepath.Dir(path), "agent.log"))

	cfg, err := readConfig(path)
	if err != nil {
		log.Fatalf("ayar: %v", err)
	}
	panel, err := newPanel(cfg.Panel, cfg.Token, cfg.CAFile)
	if err != nil {
		log.Fatalf("panel: %v", err)
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	log.Printf("console-agent %s · panel %s", version, cfg.Panel)
	for {
		jobs, err := panel.jobs(ctx)
		if err != nil && ctx.Err() == nil {
			log.Printf("işler alınamadı: %v", err)
		}
		if len(jobs) > 0 {
			runJob(ctx, panel, cfg, jobs[0])
			continue
		}
		select {
		case <-ctx.Done():
			log.Print("kapatılıyor")
			return
		case <-time.After(time.Duration(cfg.PollSeconds) * time.Second):
		}
	}
}

func programDir() string {
	exe, err := os.Executable()
	if err != nil {
		return "."
	}
	return filepath.Dir(exe)
}

func openLog(path string) {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o600)
	if err != nil {
		log.Printf("kayıt dosyası açılamadı (%v); yalnız ekrana yazılıyor", err)
		return
	}
	log.SetOutput(io.MultiWriter(os.Stdout, f))
}

func readConfig(path string) (*Config, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	a := &Config{}
	if err := json.Unmarshal(b, a); err != nil {
		return nil, fmt.Errorf("%s okunamadı: %w", path, err)
	}
	a.Panel = strings.TrimRight(a.Panel, "/")
	if a.Panel == "" || a.Token == "" {
		return nil, errors.New("panel ve jeton dolu olmalı")
	}
	if !strings.HasPrefix(a.Panel, "https://") && !strings.HasPrefix(a.Panel, "http://") {
		return nil, errors.New("panel adresi https:// ile başlamalı")
	}
	if a.CAFile != "" && !filepath.IsAbs(a.CAFile) {
		a.CAFile = filepath.Join(filepath.Dir(path), a.CAFile)
	}
	if a.PollSeconds <= 0 {
		a.PollSeconds = 5
	}
	return a, nil
}

// runJob bir işi baştan sona yürütür ve sonucunu panele bildirir.
func runJob(parent context.Context, panel *Panel, cfg *Config, job Job) {
	ctx, cancel := context.WithCancel(parent)
	defer cancel()

	notify := func(stage, message string) {
		log.Printf("[%s] %s: %s", job.UUID, stage, message)
		nctx, b := context.WithTimeout(parent, 20*time.Second)
		defer b()
		if err := panel.notify(nctx, job.UUID, stage, message); errors.Is(err, errConflict) {
			cancel()
		} else if err != nil {
			log.Printf("bildirilemedi: %v", err)
		}
	}

	notify("basladi", "Ajan işi aldı ("+version+").")
	k, port, err := openPort(ctx, firstNonEmpty(job.SerialPort, cfg.SerialPort))
	if err != nil {
		notify("hata", err.Error())
		return
	}
	defer k.line.Close()

	pumpDone := make(chan struct{})
	go func() {
		defer close(pumpDone)
		pump(ctx, panel, job.UUID, k, cancel)
	}()

	speed, reply, err := detectSpeed(ctx, k)
	if err != nil {
		notify("hata", "Konsol hızı ayarlanamadı: "+err.Error())
		return
	}
	if reply {
		notify("port", fmt.Sprintf("Konsol: %s · %d baud (cihaz cevap verdi).", port, speed))
	} else {
		notify("port", fmt.Sprintf("Konsol: %s · %d baud (cihaz henüz cevap vermedi; hız varsayılan).", port, speed))
	}

	minutes := job.InstallMinutes
	if minutes <= 0 {
		minutes = 240
	}
	flow := &Flow{
		k:      k,
		job:    job,
		notify: notify,
		configuration: func(c context.Context) (string, error) {
			return panel.configuration(c, job.UUID)
		},
		addressInUse:   pingReplies,
		bootTimeout:    10 * time.Minute,
		installTimeout: time.Duration(minutes) * time.Minute,
		manualTimeout:  30 * time.Minute,
	}
	err = flow.Install(ctx)

	// Son çıktı da panele gitsin, sonuç ondan sonra yazılsın.
	time.Sleep(1500 * time.Millisecond)
	switch {
	case ctx.Err() != nil:
		log.Printf("[%s] iş panelden kapatıldı", job.UUID)
	case err != nil:
		notify("hata", err.Error())
	default:
		notify("tamam", "Kurulum bitti.")
	}
	cancel()
	<-pumpDone
}

func firstNonEmpty(s ...string) string {
	for _, v := range s {
		if v != "" {
			return v
		}
	}
	return ""
}

// openPort verilen portu, yoksa USB-seri dönüştürücüleri dener. Birden çok
// aday varsa cihazın cevap verdiği seçilir.
func openPort(ctx context.Context, name string) (*Console, string, error) {
	if name != "" {
		h, err := openSerial(name)
		if err != nil {
			return nil, "", fmt.Errorf("%s açılamadı: %w", name, err)
		}
		return newConsole(h), name, nil
	}
	candidates, err := portCandidates()
	if err != nil {
		return nil, "", fmt.Errorf("seri portlar listelenemedi: %w", err)
	}
	if len(candidates) == 0 {
		return nil, "", errors.New("PC'de seri port yok: konsol kablosu (USB-seri dönüştürücü) takılı mı?")
	}
	if len(candidates) == 1 {
		h, err := openSerial(candidates[0])
		if err != nil {
			return nil, "", fmt.Errorf("%s açılamadı: %w", candidates[0], err)
		}
		return newConsole(h), candidates[0], nil
	}
	var first string
	for _, a := range candidates {
		h, err := openSerial(a)
		if err != nil {
			continue
		}
		k := newConsole(h)
		if _, reply, err := detectSpeed(ctx, k); err == nil && reply {
			return k, a, nil
		}
		h.Close()
		if first == "" {
			first = a
		}
	}
	if first == "" {
		return nil, "", fmt.Errorf("seri portların hiçbiri açılamadı: %s", strings.Join(candidates, ", "))
	}
	h, err := openSerial(first)
	if err != nil {
		return nil, "", err
	}
	return newConsole(h), first, nil
}

// pump konsol çıktısını saniyede bir panele taşır; dönüşte panelden elle
// yazılanları konsola yazar. İş panelde kapanınca işi durdurur.
func pump(ctx context.Context, panel *Panel, uuid string, k *Console, cancel context.CancelFunc) {
	for {
		end := ctx.Err() != nil
		b := k.TakeForPanel()
		pctx, stop := context.WithTimeout(context.Background(), 15*time.Second)
		resp, err := panel.output(pctx, uuid, b)
		stop()
		switch {
		case errors.Is(err, errConflict):
			cancel()
			return
		case err != nil:
			k.PutBack(b)
			log.Printf("çıktı panele gidemedi: %v", err)
		default:
			for _, g := range resp.Inputs {
				if err := k.Send(g); err != nil {
					log.Printf("konsola yazılamadı: %v", err)
				}
			}
			if resp.Cancelled {
				cancel()
			}
		}
		if end {
			return
		}
		select {
		case <-ctx.Done():
		case <-time.After(time.Second):
		}
	}
}

// testPort sahadaki sınama: işi açmadan portun açılıp ayarlandığını ve
// cihazın hangi hızda cevap verdiğini gösterir. Cihaz kapalıysa hız
// bulunamaz; port açılıyorsa o da bir sonuçtur.
func testPort(name string) int {
	if name == "oto" {
		name = ""
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	k, port, err := openPort(ctx, name)
	if err != nil {
		fmt.Println("HATA:", err)
		return 1
	}
	defer k.line.Close()
	fmt.Println("açıldı:", port)
	speed, reply, err := detectSpeed(ctx, k)
	switch {
	case err != nil:
		fmt.Println("HATA: hız ayarlanamadı:", err)
		return 1
	case reply:
		fmt.Printf("cihaz %d baud'da cevap verdi\n", speed)
	default:
		fmt.Printf("cihaz cevap vermedi (kapalı ya da açılıyor); hız %d\n", speed)
	}
	return 0
}
