package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

// Son bildirim panele ilk denemelerde ulaşamazsa yeniden denenir; panel
// alınca durur.
func TestFinalEventRetriedUntilPanelAccepts(t *testing.T) {
	var calls atomic.Int32
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasSuffix(r.URL.Path, "/event") {
			http.NotFound(w, r)
			return
		}
		if calls.Add(1) < 3 {
			http.Error(w, "geçici", http.StatusBadGateway)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	old := finalRetries
	finalRetries = []time.Duration{time.Millisecond, time.Millisecond, time.Millisecond}
	defer func() { finalRetries = old }()

	panel, err := newPanel(srv.URL, "jeton", "")
	if err != nil {
		t.Fatal(err)
	}
	if !deliverFinal(context.Background(), panel, "u1", "tamam", "Kurulum bitti.") {
		t.Fatal("panel üçüncü denemede aldı; ulaştı sayılmalıydı")
	}
	if calls.Load() != 3 {
		t.Errorf("deneme sayısı: %d", calls.Load())
	}
}

// Panel hiç almazsa sonuç döndürülür; ana döngü işi bir daha YÜRÜTMEZ,
// yalnız sonucu yeniden gönderir.
func TestFinishedJobNeverRunsAgain(t *testing.T) {
	cfg := &Config{}
	done := map[string]*finalEvent{"u1": {"tamam", "Kurulum bitti."}}
	jobs := []Job{{UUID: "u1", SerialPort: "COM3"}, {UUID: "u2", SerialPort: "COM3"}}

	got := launchable(jobs, done, map[string]string{}, cfg)
	if len(got) != 1 || got[0].UUID != "u2" {
		t.Fatalf("bitmiş iş yeniden seçildi: %+v", got)
	}
	if got := launchable([]Job{{UUID: "u1"}}, done, map[string]string{}, cfg); len(got) != 0 {
		t.Fatal("sonucu panele ulaşmamış iş yeniden yürütülmemeli")
	}

	var path string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		path = r.URL.Path
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()
	panel, _ := newPanel(srv.URL, "jeton", "")

	resendFinals(context.Background(), panel, done)
	if !strings.Contains(path, "/u1/event") || done["u1"] != nil {
		t.Fatalf("sonuç yeniden gönderilmeli ve düşmeli: yol=%s kalan=%+v", path, done["u1"])
	}
}

// Farklı portlardaki işler birlikte başlar; aynı porttaki ikinci iş, ilki
// bitene kadar bekler; portsuz (otomatik) işler tek tek.
func TestJobsRunPerSerialPort(t *testing.T) {
	cfg := &Config{}
	jobs := []Job{{UUID: "a", SerialPort: "COM3"}, {UUID: "b", SerialPort: "COM9"}, {UUID: "c", SerialPort: "COM3"}, {UUID: "d"}, {UUID: "e"}}
	done := map[string]*finalEvent{}
	running := map[string]string{}

	first := launchable(jobs, done, running, cfg)
	if ids := uuids(first); ids != "a,b,d" {
		t.Fatalf("ilk tur: %s", ids)
	}
	for _, j := range first {
		running[j.UUID] = portKey(j, cfg)
	}
	if got := launchable(jobs, done, running, cfg); len(got) != 0 {
		t.Fatalf("portlar doluyken iş başlamamalı: %s", uuids(got))
	}
	delete(running, "a")
	done["a"] = nil
	if ids := uuids(launchable(jobs, done, running, cfg)); ids != "c" {
		t.Fatalf("COM3 boşalınca sıradaki: %s", ids)
	}
}

func TestPortClaimedOnce(t *testing.T) {
	p := &portSet{m: map[string]bool{}}
	if !p.Claim("COM3") || p.Claim("COM3") || !p.Held("COM3") {
		t.Fatal("aynı port iki işe verilmemeli")
	}
	p.Release("COM3")
	if !p.Claim("COM3") {
		t.Fatal("bırakılan port yeniden alınabilmeli")
	}
}

func uuids(jobs []Job) string {
	var s []string
	for _, j := range jobs {
		s = append(s, j.UUID)
	}
	return strings.Join(s, ",")
}
