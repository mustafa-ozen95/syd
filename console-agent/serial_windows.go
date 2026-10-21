//go:build windows

package main

import (
	"errors"
	"fmt"
	"strings"
	"sync"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Windows'ta port go.bug.st/serial ile DEĞİL, doğrudan açılır.
//
// Bazı CH340 USB-seri sürücüleri bazı hız değişimlerinde SetCommState'ten 31 (ERROR_GEN_FAILURE) dönüyor
// ama ayarı UYGULUYOR: hemen ardından GetCommState istenen hızı gösteriyor.
// Kütüphane bu hatada portu kapatıp "Invalid serial port" diyor ve gerçek
// kodu yutuyordu. Burada hata dönerse ayar geri okunur; tutmuşsa devam
// edilir, tutmamışsa Windows'un kendi hatası söylenir. Hızın gerçekten
// işlediğini ayrıca hizBul doğrular (okunur metin gelmezse o hızı seçmez).
type winHat struct {
	h      windows.Handle
	kapat  sync.Once
	kapErr error
}

func seriAc(ad string) (Hat, error) {
	yol := ad
	if !strings.HasPrefix(yol, `\\.\`) {
		yol = `\\.\` + yol
	}
	p, err := windows.UTF16PtrFromString(yol)
	if err != nil {
		return nil, err
	}
	// Eşzamanlı (overlapped olmayan) tutamaç: okuma en geç 200 ms'de döner,
	// yazma da en çok o kadar bekler.
	h, err := windows.CreateFile(p, windows.GENERIC_READ|windows.GENERIC_WRITE, 0, nil, windows.OPEN_EXISTING, 0, 0)
	if err != nil {
		switch {
		case errors.Is(err, windows.ERROR_ACCESS_DENIED):
			return nil, errors.New("port başka bir programda açık (PuTTY, minicom vb. kapatın)")
		case errors.Is(err, windows.ERROR_FILE_NOT_FOUND):
			return nil, errors.New("port yok: kablo çıkmış olabilir")
		}
		return nil, fmt.Errorf("port açılamadı: %w", err)
	}
	hat := &winHat{h: h}
	if err := hat.HizAyarla(hizlar[0]); err != nil {
		hat.Close()
		return nil, err
	}
	zaman := windows.CommTimeouts{
		ReadIntervalTimeout:        0xFFFFFFFF,
		ReadTotalTimeoutMultiplier: 0xFFFFFFFF,
		ReadTotalTimeoutConstant:   200,
		WriteTotalTimeoutConstant:  5000,
	}
	if err := windows.SetCommTimeouts(h, &zaman); err != nil {
		hat.Close()
		return nil, fmt.Errorf("zaman aşımı ayarlanamadı: %w", err)
	}
	return hat, nil
}

// DCB bayrakları (winbase.h).
const (
	dcbIkili        = 0x0001 // fBinary
	dcbEslik        = 0x0002 // fParity
	dcbCtsAkis      = 0x0004 // fOutxCtsFlow
	dcbDsrAkis      = 0x0008 // fOutxDsrFlow
	dcbDtrMaske     = 0x0030 // fDtrControl
	dcbDsrDuyarli   = 0x0040 // fDsrSensitivity
	dcbXoffSurdur   = 0x0080 // fTXContinueOnXoff
	dcbXonCikis     = 0x0100 // fOutX
	dcbXonGiris     = 0x0200 // fInX
	dcbHataKarakter = 0x0400 // fErrorChar
	dcbBosAt        = 0x0800 // fNull
	dcbRtsMaske     = 0x3000 // fRtsControl
	dcbHataDurdur   = 0x4000 // fAbortOnError
)

func (w *winHat) HizAyarla(hiz int) error {
	var d windows.DCB
	d.DCBlength = uint32(unsafe.Sizeof(d))
	if err := windows.GetCommState(w.h, &d); err != nil {
		return fmt.Errorf("port ayarı okunamadı: %w", err)
	}
	d.BaudRate = uint32(hiz)
	d.ByteSize = 8
	d.Parity = windows.NOPARITY
	d.StopBits = windows.ONESTOPBIT
	// 8N1, akış denetimi yok; DTR/RTS açık. Xon/Xoff sınırlarına dokunulmaz:
	// sürücünün kendi değerleri kalır.
	d.Flags |= dcbIkili | dcbXoffSurdur
	d.Flags &^= dcbEslik | dcbCtsAkis | dcbDsrAkis | dcbDsrDuyarli | dcbXonCikis | dcbXonGiris |
		dcbHataKarakter | dcbBosAt | dcbHataDurdur | dcbDtrMaske | dcbRtsMaske
	d.Flags |= windows.DTR_CONTROL_ENABLE | windows.RTS_CONTROL_ENABLE

	err := windows.SetCommState(w.h, &d)
	if err == nil {
		return nil
	}
	var g windows.DCB
	g.DCBlength = uint32(unsafe.Sizeof(g))
	if windows.GetCommState(w.h, &g) == nil && g.BaudRate == d.BaudRate && g.ByteSize == 8 &&
		g.Parity == windows.NOPARITY && g.StopBits == windows.ONESTOPBIT {
		return nil
	}
	return fmt.Errorf("%d baud ayarlanamadı: %w", hiz, err)
}

func (w *winHat) Read(b []byte) (int, error) {
	var n uint32
	if err := windows.ReadFile(w.h, b, &n, nil); err != nil {
		return int(n), err
	}
	return int(n), nil
}

func (w *winHat) Write(b []byte) (int, error) {
	toplam := 0
	for toplam < len(b) {
		var n uint32
		if err := windows.WriteFile(w.h, b[toplam:], &n, nil); err != nil {
			return toplam, err
		}
		if n == 0 {
			return toplam, errors.New("porta yazılamadı (zaman aşımı)")
		}
		toplam += int(n)
	}
	return toplam, nil
}

func (w *winHat) Close() error {
	w.kapat.Do(func() { w.kapErr = windows.CloseHandle(w.h) })
	return w.kapErr
}
