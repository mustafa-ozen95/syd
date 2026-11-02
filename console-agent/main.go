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
	"sync"
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
	// Bitirilen işler ve panele ulaşamayan sonuçları. Panel bir işi açık
	// gösterse de ajan onu bir daha YÜRÜTMEZ: sonucu ("tamam"/"hata") ağ ya da
	// panel kesintisinde kaybolan iş yeniden alınırsa, kurulumu bitmiş switch
	// loader'a indirilip baştan formatlanıyordu. Sonuç her turda yeniden
	// gönderilir, panel alınca (ya da işi kapatmışsa) düşer.
	done := map[string]*finalEvent{}
	// Çalışan işler (uuid → port anahtarı) yalnız bu döngüde tutulur: kilit
	// gerekmez. Her seri portta aynı anda bir iş; farklı portlardaki işler
	// birlikte yürür (bir PC'ye birden çok konsol kablosu).
	running := map[string]string{}
	results := make(chan jobResult)
	for {
		resendFinals(ctx, panel, done)
		jobs, err := panel.jobs(ctx)
		if err != nil && ctx.Err() == nil {
			log.Printf("işler alınamadı: %v", err)
		}
		for _, job := range launchable(jobs, done, running, cfg) {
			running[job.UUID] = portKey(job, cfg)
			go func(j Job) { results <- jobResult{uuid: j.UUID, unsent: runJob(ctx, panel, cfg, j)} }(job)
		}
		select {
		case r := <-results:
			done[r.uuid] = r.unsent
			delete(running, r.uuid)
		case <-ctx.Done():
			log.Print("kapatılıyor")
			// Süren işler son çıktılarını ve sonuçlarını bildirsin.
			for len(running) > 0 {
				r := <-results
				delete(running, r.uuid)
			}
			return
		case <-time.After(time.Duration(cfg.PollSeconds) * time.Second):
		}
	}
}

// jobResult bir işin bittiği ve panele ulaşamamış sonucudur (yoksa nil).
type jobResult struct {
	uuid   string
	unsent *finalEvent
}

// portKey işin seri portudur; boşsa "otomatik": aynı anda tek otomatik iş
// (tarama öbür işin kablosunu deneyebilirdi).
func portKey(job Job, cfg *Config) string {
	if p := firstNonEmpty(job.SerialPort, cfg.SerialPort); p != "" {
		return p
	}
	return "otomatik"
}

// launchable bu ajanın şimdi başlatabileceği işlerdir: daha önce
// yürütülmemiş, şu an yürümeyen ve portu boş olanlar; aynı porta iki iş
// verilmez.
func launchable(jobs []Job, done map[string]*finalEvent, running map[string]string, cfg *Config) []Job {
	busy := map[string]bool{}
	for _, key := range running {
		busy[key] = true
	}
	var out []Job
	for _, j := range jobs {
		if _, ok := done[j.UUID]; ok {
			continue
		}
		if _, ok := running[j.UUID]; ok {
			continue
		}
		key := portKey(j, cfg)
		if busy[key] {
			continue
		}
		busy[key] = true
		out = append(out, j)
	}
	return out
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

// finalEvent panele henüz ulaşamamış "tamam" ya da "hata" bildirimidir.
type finalEvent struct{ stage, message string }

// finalRetries son bildirimin iş içinde kaç kez, hangi aralıklarla
// deneneceğidir; ulaşamazsa ana döngü her turda yeniden dener.
var finalRetries = []time.Duration{2 * time.Second, 5 * time.Second, 15 * time.Second, 30 * time.Second}

// resendFinals panele ulaşamamış son bildirimleri yeniden gönderir.
func resendFinals(ctx context.Context, panel *Panel, done map[string]*finalEvent) {
	for uuid, f := range done {
		if f == nil {
			continue
		}
		nctx, cancel := context.WithTimeout(ctx, 20*time.Second)
		err := panel.notify(nctx, uuid, f.stage, f.message)
		cancel()
		if err == nil || errors.Is(err, errConflict) {
			log.Printf("[%s] %s bildirimi panele ulaştı", uuid, f.stage)
			done[uuid] = nil
		}
	}
}

// deliverFinal son bildirimi panel alana (ya da işi kapatmış olana) kadar
// birkaç kez dener; ulaşamazsa false.
func deliverFinal(ctx context.Context, panel *Panel, uuid, stage, message string) bool {
	for i := 0; ; i++ {
		nctx, cancel := context.WithTimeout(ctx, 20*time.Second)
		err := panel.notify(nctx, uuid, stage, message)
		cancel()
		if err == nil || errors.Is(err, errConflict) {
			return true
		}
		log.Printf("[%s] %s bildirilemedi: %v", uuid, stage, err)
		if i >= len(finalRetries) {
			return false
		}
		select {
		case <-ctx.Done():
			return false
		case <-time.After(finalRetries[i]):
		}
	}
}

// runJob bir işi baştan sona yürütür ve sonucunu panele bildirir. Sonuç
// panele ulaşamadıysa onu döndürür (ana döngü yeniden gönderir), yoksa nil.
func runJob(parent context.Context, panel *Panel, cfg *Config, job Job) (unsent *finalEvent) {
	ctx, cancel := context.WithCancel(parent)
	defer cancel()

	notify := func(stage, message string) {
		log.Printf("[%s] %s: %s", job.UUID, stage, message)
		if stage == "tamam" || stage == "hata" {
			if !deliverFinal(parent, panel, job.UUID, stage, message) {
				unsent = &finalEvent{stage, message}
			}
			return
		}
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
		return unsent
	}
	defer claimed.Release(port)
	defer k.line.Close()

	pumpDone := make(chan struct{})
	go func() {
		defer close(pumpDone)
		pump(ctx, panel, job.UUID, k, cancel)
	}()

	speed, reply, err := detectSpeed(ctx, k)
	if err != nil {
		notify("hata", "Konsol hızı ayarlanamadı: "+err.Error())
		return unsent
	}
	if reply {
		notify("port", fmt.Sprintf("Konsol: %s · %d baud (cihaz cevap verdi).", k.device, speed))
	} else {
		notify("port", fmt.Sprintf("Konsol: %s · %d baud (cihaz henüz cevap vermedi; hız varsayılan).", k.device, speed))
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
		patch: func(c context.Context) (string, error) {
			return panel.patch(c, job.UUID)
		},
		addressInUse:   pingReplies,
		bootTimeout:    10 * time.Minute,
		installTimeout: time.Duration(minutes) * time.Minute,
		manualTimeout:  30 * time.Minute,
	}
	switch {
	case job.LoadOnly:
		err = flow.LoadOnly(ctx)
	case job.CliInstall && job.ResumeStage == "":
		err = flow.CliInstall(ctx)
	case job.ResumeStage != "":
		err = flow.Resume(ctx)
	default:
		err = flow.Install(ctx)
	}

	// Son çıktı da panele gitsin, sonuç ondan sonra yazılsın.
	time.Sleep(1500 * time.Millisecond)
	switch {
	case ctx.Err() != nil:
		log.Printf("[%s] iş panelden kapatıldı", job.UUID)
	case err != nil:
		notify("hata", err.Error())
	default:
		if job.LoadOnly {
			notify("tamam", "Yedek yüklendi.")
		} else {
			notify("tamam", "Kurulum bitti.")
		}
	}
	cancel()
	<-pumpDone
	return unsent
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
//
// Açılan port, iş bitene kadar claimed'de tutulur (runJob bırakır): aynı anda
// yürüyen başka bir iş onu açmaz, otomatik tarama onu denemez.
func openPort(ctx context.Context, name string) (*Console, string, error) {
	if name != "" {
		if !claimed.Claim(name) {
			return nil, "", fmt.Errorf("%s başka bir işte kullanılıyor", name)
		}
		// Panel portu kablonun kimliğiyle verir; o anki COM adı burada bulunur.
		dev, err := resolvePort(name)
		if err != nil {
			claimed.Release(name)
			return nil, "", err
		}
		h, err := openSerial(dev)
		if err != nil {
			claimed.Release(name)
			return nil, "", fmt.Errorf("%s açılamadı: %w", dev, err)
		}
		k := newConsole(h)
		k.device = dev
		return k, name, nil
	}
	all, err := portCandidates()
	if err != nil {
		return nil, "", fmt.Errorf("seri portlar listelenemedi: %w", err)
	}
	var candidates []string
	for _, a := range all {
		if !claimed.Held(a) {
			candidates = append(candidates, a)
		}
	}
	if len(candidates) == 0 {
		return nil, "", errors.New("PC'de seri port yok: konsol kablosu (USB-seri dönüştürücü) takılı mı?")
	}
	if len(candidates) == 1 {
		return openPort(ctx, candidates[0])
	}
	var first string
	for _, a := range candidates {
		if !claimed.Claim(a) {
			continue
		}
		h, err := openSerial(a)
		if err != nil {
			claimed.Release(a)
			continue
		}
		k := newConsole(h)
		k.device = a
		if _, reply, err := detectSpeed(ctx, k); err == nil && reply {
			return k, a, nil
		}
		h.Close()
		claimed.Release(a)
		if first == "" {
			first = a
		}
	}
	if first == "" {
		return nil, "", fmt.Errorf("seri portların hiçbiri açılamadı: %s", strings.Join(candidates, ", "))
	}
	return openPort(ctx, first)
}

// portSet aynı anda yürüyen işlerin açtığı seri portlardır.
type portSet struct {
	mu sync.Mutex
	m  map[string]bool
}

var claimed = &portSet{m: map[string]bool{}}

// Claim portu alır; başkası tutuyorsa false.
func (p *portSet) Claim(name string) bool {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.m[name] {
		return false
	}
	p.m[name] = true
	return true
}

func (p *portSet) Release(name string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	delete(p.m, name)
}

func (p *portSet) Held(name string) bool {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.m[name]
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
