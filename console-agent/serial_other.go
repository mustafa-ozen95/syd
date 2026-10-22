//go:build !windows

package main

import (
	"time"

	"go.bug.st/serial"
)

type serialLine struct{ p serial.Port }

func (h *serialLine) Read(b []byte) (int, error)  { return h.p.Read(b) }
func (h *serialLine) Write(b []byte) (int, error) { return h.p.Write(b) }
func (h *serialLine) Close() error                { return h.p.Close() }
func (h *serialLine) SetSpeed(speed int) error {
	return h.p.SetMode(&serial.Mode{BaudRate: speed, DataBits: 8, Parity: serial.NoParity, StopBits: serial.OneStopBit})
}

func openSerial(name string) (Line, error) {
	p, err := serial.Open(name, &serial.Mode{BaudRate: speeds[0], DataBits: 8, Parity: serial.NoParity, StopBits: serial.OneStopBit})
	if err != nil {
		return nil, err
	}
	// Okuma kısa aralıklarla döner: kapatma ve iptal beklemeden işler.
	if err := p.SetReadTimeout(200 * time.Millisecond); err != nil {
		p.Close()
		return nil, err
	}
	return &serialLine{p: p}, nil
}
