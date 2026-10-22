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

// Line konsol kablosunun kendisi. Testte sahte bir cihaz yerine geçer.
type Line interface {
	Read(p []byte) (int, error)
	Write(p []byte) (int, error)
	Close() error
	SetSpeed(speed int) error
}

// Denenecek hızlar, sırayla. Önce 115200;
// Juniper'ın fabrika hızı 9600 hemen ardından denenir.
var speeds = []int{115200, 9600, 19200, 38400, 57600}

// portCandidates önce USB-seri dönüştürücüler; yoksa bütün seri portlar.
func portCandidates() ([]string, error) {
	details, err := enumerator.GetDetailedPortsList()
	if err == nil {
		var usb []string
		for _, p := range details {
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

// Console hattan gelen her şeyi biriktirir; akış istemleri buradan bekler,
// pump da panele buradan aktarır.
type Console struct {
	line Line

	mu       sync.Mutex
	buffer   []byte // eşleştirme için son kısım
	start    int64  // tamponun ilk baytının toplamdaki yeri
	forPanel []byte // forPanel henüz gitmemiş çıktı
	fresh    chan struct{}
	readErr  error

	speed int // portun şu anki hızı; yalnız akışın goroutine'i değiştirir
}

const bufferLimit = 64 << 10

func newConsole(h Line) *Console {
	k := &Console{line: h, fresh: make(chan struct{}), speed: speeds[0]}
	go k.read()
	return k
}

func (k *Console) read() {
	b := make([]byte, 4096)
	for {
		n, err := k.line.Read(b)
		k.mu.Lock()
		if n > 0 {
			k.buffer = append(k.buffer, b[:n]...)
			k.forPanel = append(k.forPanel, b[:n]...)
			if excess := len(k.buffer) - bufferLimit; excess > 0 {
				k.buffer = append([]byte(nil), k.buffer[excess:]...)
				k.start += int64(excess)
			}
			// Panel uzun süre ulaşılamazsa bellek şişmesin: en yenisi kalır.
			if excess := len(k.forPanel) - 4*bufferLimit; excess > 0 {
				k.forPanel = append([]byte(nil), k.forPanel[excess:]...)
			}
			close(k.fresh)
			k.fresh = make(chan struct{})
		}
		if err != nil {
			k.readErr = err
			close(k.fresh)
			k.fresh = make(chan struct{})
			k.mu.Unlock()
			return
		}
		k.mu.Unlock()
	}
}

// Position şu ana kadar gelen bayt sayısı; Bekle'ye "bundan sonrası" demek için.
func (k *Console) Position() int64 {
	k.mu.Lock()
	defer k.mu.Unlock()
	return k.start + int64(len(k.buffer))
}

// Text konumdan bu yana gelen çıktı (tampona sığdığı kadar).
func (k *Console) Text(pos int64) string {
	k.mu.Lock()
	defer k.mu.Unlock()
	i := pos - k.start
	if i < 0 {
		i = 0
	}
	if i > int64(len(k.buffer)) {
		return ""
	}
	return string(k.buffer[i:])
}

// TakeForPanel panele gidecek birikmiş çıktıyı verir ve sıfırlar.
func (k *Console) TakeForPanel() []byte {
	k.mu.Lock()
	defer k.mu.Unlock()
	b := k.forPanel
	k.forPanel = nil
	return b
}

func (k *Console) Send(s string) error {
	_, err := k.line.Write([]byte(s))
	return err
}

var errTimeout = errors.New("süre doldu")

// Wait konumdan sonraki çıktıda desenlerden biri görülene kadar bekler;
// görülenin sırasını döner.
func (k *Console) Wait(ctx context.Context, pos int64, timeout time.Duration, patterns ...*regexp.Regexp) (int, error) {
	end := time.NewTimer(timeout)
	defer end.Stop()
	for {
		k.mu.Lock()
		text := clean(k.textLocked(pos))
		fresh, readErr := k.fresh, k.readErr
		k.mu.Unlock()
		for i, d := range patterns {
			if d.MatchString(text) {
				return i, nil
			}
		}
		if readErr != nil {
			return -1, fmt.Errorf("konsol okunamıyor: %w", readErr)
		}
		select {
		case <-ctx.Done():
			return -1, ctx.Err()
		case <-end.C:
			return -1, errTimeout
		case <-fresh:
		}
	}
}

func (k *Console) textLocked(pos int64) string {
	i := pos - k.start
	if i < 0 {
		i = 0
	}
	if i > int64(len(k.buffer)) {
		return ""
	}
	return string(k.buffer[i:])
}

var ansiEscape = regexp.MustCompile(`\x1b\[[0-9;?]*[A-Za-z]|\x1b[()][0-9A-Za-z]|\x00`)

// clean eşleştirmeyi bozan kaçış dizilerini ve satır başı karakterlerini atar.
func clean(s string) string {
	s = ansiEscape.ReplaceAllString(s, "")
	return strings.ReplaceAll(s, "\r", "")
}

// detectSpeed cihazın cevap verdiği hızı arar: Enter gönderir, gelen baytların
// okunur metin olup olmadığına bakar. Cihaz susuyorsa (kapalı, açılıyor)
// karar verilemez; varsayılan hızda kalınır.
func detectSpeed(ctx context.Context, k *Console) (speed int, replied bool, err error) {
	best, bestScore := speeds[0], -1.0
	for _, h := range speeds {
		if err := k.line.SetSpeed(h); err != nil {
			return 0, false, err
		}
		time.Sleep(300 * time.Millisecond)
		pos := k.Position()
		if err := k.Send("\r"); err != nil {
			return 0, false, err
		}
		select {
		case <-ctx.Done():
			return 0, false, ctx.Err()
		case <-time.After(1500 * time.Millisecond):
		}
		score, count := readability(k.Text(pos))
		if count >= 3 && score >= 0.9 {
			k.speed = h
			return h, true, nil
		}
		if count > 0 && score > bestScore {
			best, bestScore = h, score
		}
	}
	if bestScore < 0 {
		best = speeds[0]
	}
	if err := k.line.SetSpeed(best); err != nil {
		return 0, false, err
	}
	k.speed = best
	return best, false, nil
}

// NextSpeed listede sıradaki hıza geçer (sona gelince başa döner).
func (k *Console) NextSpeed() (int, error) {
	i := 0
	for j, h := range speeds {
		if h == k.speed {
			i = j + 1
		}
	}
	fresh := speeds[i%len(speeds)]
	if err := k.line.SetSpeed(fresh); err != nil {
		return 0, err
	}
	k.speed = fresh
	return fresh, nil
}

func readability(s string) (float64, int) {
	if s == "" {
		return 0, 0
	}
	good := 0
	for i := 0; i < len(s); i++ {
		c := s[i]
		if c == '\r' || c == '\n' || c == '\t' || (c >= 0x20 && c < 0x7f) {
			good++
		}
	}
	return float64(good) / float64(len(s)), len(s)
}

// PutBack panele gidemeyen çıktıyı sıranın başına geri koyar.
func (k *Console) PutBack(b []byte) {
	if len(b) == 0 {
		return
	}
	k.mu.Lock()
	defer k.mu.Unlock()
	k.forPanel = append(append([]byte(nil), b...), k.forPanel...)
}
