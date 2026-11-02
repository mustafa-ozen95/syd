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
	done := map[string]*finalEvent{"u1": {"tamam", "Kurulum bitti."}}
	jobs := []Job{{UUID: "u1"}, {UUID: "u2"}}

	job, ok := nextJob(jobs, done)
	if !ok || job.UUID != "u2" {
		t.Fatalf("bitmiş iş yeniden seçildi: %+v", job)
	}
	if _, ok := nextJob([]Job{{UUID: "u1"}}, done); ok {
		t.Fatal("sonucu panele ulaşmamış iş yeniden yürütülmemeli")
	}

	var got string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.URL.Path
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()
	panel, _ := newPanel(srv.URL, "jeton", "")

	resendFinals(context.Background(), panel, done)
	if !strings.Contains(got, "/u1/event") || done["u1"] != nil {
		t.Fatalf("sonuç yeniden gönderilmeli ve düşmeli: yol=%s kalan=%+v", got, done["u1"])
	}
	if _, ok := nextJob([]Job{{UUID: "u1"}}, done); ok {
		t.Fatal("sonucu gönderilmiş iş de yeniden yürütülmemeli")
	}
}
