//go:build !windows

package main

import (
	"time"

	"go.bug.st/serial"
)

type seriHat struct{ p serial.Port }

func (h *seriHat) Read(b []byte) (int, error)  { return h.p.Read(b) }
func (h *seriHat) Write(b []byte) (int, error) { return h.p.Write(b) }
func (h *seriHat) Close() error                { return h.p.Close() }
func (h *seriHat) HizAyarla(hiz int) error {
	return h.p.SetMode(&serial.Mode{BaudRate: hiz, DataBits: 8, Parity: serial.NoParity, StopBits: serial.OneStopBit})
}

func seriAc(ad string) (Hat, error) {
	p, err := serial.Open(ad, &serial.Mode{BaudRate: hizlar[0], DataBits: 8, Parity: serial.NoParity, StopBits: serial.OneStopBit})
	if err != nil {
		return nil, err
	}
	// Okuma kısa aralıklarla döner: kapatma ve iptal beklemeden işler.
	if err := p.SetReadTimeout(200 * time.Millisecond); err != nil {
		p.Close()
		return nil, err
	}
	return &seriHat{p: p}, nil
}
