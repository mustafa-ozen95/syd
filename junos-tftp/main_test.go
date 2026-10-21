package main

import (
	"bytes"
	"crypto/rand"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/pin/tftp/v3"
	"golang.org/x/net/ipv4"
)

func TestDosyaAdi(t *testing.T) {
	for ad, iyi := range map[string]bool{
		"junos-arm-32-24.4R2.23.tgz": true, "/junos.tgz": true,
		"../etc/passwd": false, "a/b.tgz": false, `a\b`: false, ".gizli": false, "": false,
	} {
		if _, err := dosyaAdi(ad); (err == nil) != iyi {
			t.Errorf("%q: %v", ad, err)
		}
	}
}

func TestAktarimVeIlerleme(t *testing.T) {
	kok, durumDizin := t.TempDir(), t.TempDir()
	veri := make([]byte, 300_000)
	rand.Read(veri)
	if err := os.WriteFile(filepath.Join(kok, "j.tgz"), veri, 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(filepath.Dir(kok), "gizli"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	s := &Sunucu{kok: kok, durum: filepath.Join(durumDizin, "tftp.json")}
	srv := tftp.NewServer(s.oku, nil)
	srv.EnableSinglePort()
	srv.SetTimeout(2 * time.Second)
	baglanti, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	go srv.Serve(baglanti)
	defer srv.Shutdown()

	istemci, err := tftp.NewClient(baglanti.LocalAddr().String())
	if err != nil {
		t.Fatal(err)
	}
	wt, err := istemci.Receive("j.tgz", "octet")
	if err != nil {
		t.Fatal(err)
	}
	var gelen bytes.Buffer
	if _, err := wt.WriteTo(&gelen); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(gelen.Bytes(), veri) {
		t.Fatal("dosya bozuk geldi")
	}

	/* Dizin dışı istenemez. */
	if wt, err := istemci.Receive("../gizli", "octet"); err == nil {
		if _, err := wt.WriteTo(&bytes.Buffer{}); err == nil {
			t.Fatal("dizin dışı dosya verildi")
		}
	}

	if err := s.yaz(); err != nil {
		t.Fatal(err)
	}
	b, err := os.ReadFile(s.durum)
	if err != nil {
		t.Fatal(err)
	}
	var d struct{ Aktarimlar []Aktarim }
	if err := json.Unmarshal(b, &d); err != nil {
		t.Fatal(err)
	}
	if len(d.Aktarimlar) != 1 || d.Aktarimlar[0].Giden != int64(len(veri)) || !d.Aktarimlar[0].Bitti || d.Aktarimlar[0].Boyut != int64(len(veri)) {
		t.Fatalf("durum: %s", b)
	}
}

// İstemci 1468 baytlık blok isterse OACK 1468 demeli (Junos kurucusu
// küçültülmüş cevabı dikkate almıyor).
func TestIstenenBlokBoyu(t *testing.T) {
	kok := t.TempDir()
	if err := os.WriteFile(filepath.Join(kok, "j.tgz"), make([]byte, 5000), 0o644); err != nil {
		t.Fatal(err)
	}
	s := &Sunucu{kok: kok, durum: filepath.Join(t.TempDir(), "tftp.json")}
	srv := tftp.NewServer(s.oku, nil)
	srv.EnableSinglePort()
	srv.SetBlockSize(blokSiniri)
	srv.SetTimeout(time.Second)
	baglanti, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	// Kütüphane sınırı paketin geldiği arayüzün MTU'sundan alır; bunu Serve
	// içinde açtığı denetim iletisiyle öğrenir. Paket Serve o ayarı açmadan
	// gelirse sınır 512'ye düşer ve sınama yarışa girer.
	// Ayar burada önceden açılır; sunucuda paket hep Serve'den sonra gelir.
	if err := ipv4.NewPacketConn(baglanti).SetControlMessage(ipv4.FlagDst|ipv4.FlagInterface, true); err != nil {
		t.Fatal(err)
	}
	go srv.Serve(baglanti)
	defer srv.Shutdown()

	c, err := net.DialUDP("udp", nil, baglanti.LocalAddr().(*net.UDPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	istek := append([]byte{0, 1}, []byte("j.tgz\x00octet\x00blksize\x001468\x00")...)
	if _, err := c.Write(istek); err != nil {
		t.Fatal(err)
	}
	c.SetReadDeadline(time.Now().Add(2 * time.Second))
	cevap := make([]byte, 100)
	n, err := c.Read(cevap)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(cevap[:n], []byte{0, 6}) || !bytes.Contains(cevap[:n], []byte("blksize\x001468\x00")) {
		t.Fatalf("OACK beklenen blksize 1468 değil: %q", cevap[:n])
	}
}
