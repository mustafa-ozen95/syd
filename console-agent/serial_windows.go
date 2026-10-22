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
// işlediğini ayrıca detectSpeed doğrular (okunur metin gelmezse o hızı seçmez).
type winLine struct {
	h         windows.Handle
	closeOnce sync.Once
	closeErr  error
}

func openSerial(name string) (Line, error) {
	path := name
	if !strings.HasPrefix(path, `\\.\`) {
		path = `\\.\` + path
	}
	p, err := windows.UTF16PtrFromString(path)
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
	line := &winLine{h: h}
	if err := line.SetSpeed(speeds[0]); err != nil {
		line.Close()
		return nil, err
	}
	timeouts := windows.CommTimeouts{
		ReadIntervalTimeout:        0xFFFFFFFF,
		ReadTotalTimeoutMultiplier: 0xFFFFFFFF,
		ReadTotalTimeoutConstant:   200,
		WriteTotalTimeoutConstant:  5000,
	}
	if err := windows.SetCommTimeouts(h, &timeouts); err != nil {
		line.Close()
		return nil, fmt.Errorf("zaman aşımı ayarlanamadı: %w", err)
	}
	return line, nil
}

// DCB bayrakları (winbase.h).
const (
	dcbBinary       = 0x0001 // fBinary
	dcbParity       = 0x0002 // fParity
	dcbCtsFlow      = 0x0004 // fOutxCtsFlow
	dcbDsrFlow      = 0x0008 // fOutxDsrFlow
	dcbDtrMask      = 0x0030 // fDtrControl
	dcbDsrSensitive = 0x0040 // fDsrSensitivity
	dcbXoffContinue = 0x0080 // fTXContinueOnXoff
	dcbXonOut       = 0x0100 // fOutX
	dcbXonIn        = 0x0200 // fInX
	dcbErrorChar    = 0x0400 // fErrorChar
	dcbDropNull     = 0x0800 // fNull
	dcbRtsMask      = 0x3000 // fRtsControl
	dcbAbortOnError = 0x4000 // fAbortOnError
)

func (w *winLine) SetSpeed(speed int) error {
	var d windows.DCB
	d.DCBlength = uint32(unsafe.Sizeof(d))
	if err := windows.GetCommState(w.h, &d); err != nil {
		return fmt.Errorf("port ayarı okunamadı: %w", err)
	}
	d.BaudRate = uint32(speed)
	d.ByteSize = 8
	d.Parity = windows.NOPARITY
	d.StopBits = windows.ONESTOPBIT
	// 8N1, akış denetimi yok; DTR/RTS açık. Xon/Xoff sınırlarına dokunulmaz:
	// sürücünün kendi değerleri kalır.
	d.Flags |= dcbBinary | dcbXoffContinue
	d.Flags &^= dcbParity | dcbCtsFlow | dcbDsrFlow | dcbDsrSensitive | dcbXonOut | dcbXonIn |
		dcbErrorChar | dcbDropNull | dcbAbortOnError | dcbDtrMask | dcbRtsMask
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
	return fmt.Errorf("%d baud ayarlanamadı: %w", speed, err)
}

func (w *winLine) Read(b []byte) (int, error) {
	var n uint32
	if err := windows.ReadFile(w.h, b, &n, nil); err != nil {
		return int(n), err
	}
	return int(n), nil
}

func (w *winLine) Write(b []byte) (int, error) {
	total := 0
	for total < len(b) {
		var n uint32
		if err := windows.WriteFile(w.h, b[total:], &n, nil); err != nil {
			return total, err
		}
		if n == 0 {
			return total, errors.New("porta yazılamadı (zaman aşımı)")
		}
		total += int(n)
	}
	return total, nil
}

func (w *winLine) Close() error {
	w.closeOnce.Do(func() { w.closeErr = windows.CloseHandle(w.h) })
	return w.closeErr
}
