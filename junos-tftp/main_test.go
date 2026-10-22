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

func TestFileName(t *testing.T) {
	for name, valid := range map[string]bool{
		"junos-arm-32-24.4R2.23.tgz": true, "/junos.tgz": true,
		"../etc/passwd": false, "a/b.tgz": false, `a\b`: false, ".gizli": false, "": false,
	} {
		if _, err := fileName(name); (err == nil) != valid {
			t.Errorf("%q: %v", name, err)
		}
	}
}

func TestTransferAndProgress(t *testing.T) {
	root, stateDir := t.TempDir(), t.TempDir()
	data := make([]byte, 300_000)
	rand.Read(data)
	if err := os.WriteFile(filepath.Join(root, "j.tgz"), data, 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(filepath.Dir(root), "gizli"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	s := &Server{root: root, stateFile: filepath.Join(stateDir, "tftp.json")}
	srv := tftp.NewServer(s.readHandler, nil)
	srv.EnableSinglePort()
	srv.SetTimeout(2 * time.Second)
	conn, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	go srv.Serve(conn)
	defer srv.Shutdown()

	client, err := tftp.NewClient(conn.LocalAddr().String())
	if err != nil {
		t.Fatal(err)
	}
	wt, err := client.Receive("j.tgz", "octet")
	if err != nil {
		t.Fatal(err)
	}
	var received bytes.Buffer
	if _, err := wt.WriteTo(&received); err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(received.Bytes(), data) {
		t.Fatal("dosya bozuk geldi")
	}

	/* Dizin dışı istenemez. */
	if wt, err := client.Receive("../gizli", "octet"); err == nil {
		if _, err := wt.WriteTo(&bytes.Buffer{}); err == nil {
			t.Fatal("dizin dışı dosya verildi")
		}
	}

	if err := s.writeState(); err != nil {
		t.Fatal(err)
	}
	b, err := os.ReadFile(s.stateFile)
	if err != nil {
		t.Fatal(err)
	}
	var d struct{ Transfers []Transfer }
	if err := json.Unmarshal(b, &d); err != nil {
		t.Fatal(err)
	}
	if len(d.Transfers) != 1 || d.Transfers[0].Sent != int64(len(data)) || !d.Transfers[0].Done || d.Transfers[0].Size != int64(len(data)) {
		t.Fatalf("durum: %s", b)
	}
}

// İstemci 1468 baytlık blok isterse OACK 1468 demeli (Junos kurucusu
// küçültülmüş cevabı dikkate almıyor).
func TestRequestedBlockSize(t *testing.T) {
	root := t.TempDir()
	if err := os.WriteFile(filepath.Join(root, "j.tgz"), make([]byte, 5000), 0o644); err != nil {
		t.Fatal(err)
	}
	s := &Server{root: root, stateFile: filepath.Join(t.TempDir(), "tftp.json")}
	srv := tftp.NewServer(s.readHandler, nil)
	srv.EnableSinglePort()
	srv.SetBlockSize(blockLimit)
	srv.SetTimeout(time.Second)
	conn, err := net.ListenUDP("udp", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	// Kütüphane sınırı paketin geldiği arayüzün MTU'sundan alır; bunu Serve
	// içinde açtığı denetim iletisiyle öğrenir. Paket Serve o ayarı açmadan
	// gelirse sınır 512'ye düşer ve sınama yarışa girer.
	// Ayar burada önceden açılır; sunucuda paket hep Serve'den sonra gelir.
	if err := ipv4.NewPacketConn(conn).SetControlMessage(ipv4.FlagDst|ipv4.FlagInterface, true); err != nil {
		t.Fatal(err)
	}
	go srv.Serve(conn)
	defer srv.Shutdown()

	c, err := net.DialUDP("udp", nil, conn.LocalAddr().(*net.UDPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer c.Close()
	request := append([]byte{0, 1}, []byte("j.tgz\x00octet\x00blksize\x001468\x00")...)
	if _, err := c.Write(request); err != nil {
		t.Fatal(err)
	}
	c.SetReadDeadline(time.Now().Add(2 * time.Second))
	reply := make([]byte, 100)
	n, err := c.Read(reply)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.HasPrefix(reply[:n], []byte{0, 6}) || !bytes.Contains(reply[:n], []byte("blksize\x001468\x00")) {
		t.Fatalf("OACK beklenen blksize 1468 değil: %q", reply[:n])
	}
}
