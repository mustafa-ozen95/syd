package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"
)

// Panelden gelen iş. Alan adları panelin API'siyle (konsol.isler) aynı.
type Job struct {
	UUID            string   `json:"uuid"`
	SerialPort      string   `json:"serial_port"`
	DeviceIP        string   `json:"device_ip"`
	LoaderCommands  []string `json:"loader_commands"`
	InstallCommand  string   `json:"install_command"`
	InstallMinutes  int      `json:"install_minutes"`
	ExpectedVersion string   `json:"expected_version"`
	Password        string   `json:"password"`
	Configuration   bool     `json:"configuration"`
	LoadCommand     string   `json:"load_command"`
	DeleteFirst     bool     `json:"delete_first"`
}

// Panelin "iş artık yok" yanıtı: iptal edildi ya da silindi.
var errConflict = errors.New("iş artık bu ajanın değil")

type Panel struct {
	address string
	token   string
	hc      *http.Client
}

func newPanel(address, token, caFile string) (*Panel, error) {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	if caFile != "" {
		pem, err := os.ReadFile(caFile)
		if err != nil {
			return nil, err
		}
		// Sistemin kökleri KORUNUR, dosyadaki sertifika EKLENİR: panel
		// kendinden imzalıyken de, sonra gerçek sertifikaya geçince de çalışır.
		pool, err := x509.SystemCertPool()
		if err != nil || pool == nil {
			pool = x509.NewCertPool()
		}
		if !pool.AppendCertsFromPEM(pem) {
			return nil, fmt.Errorf("%s içinde sertifika yok", caFile)
		}
		tr.TLSClientConfig = &tls.Config{RootCAs: pool, MinVersion: tls.VersionTLS12}
	}
	return &Panel{address: address, token: token, hc: &http.Client{Transport: tr, Timeout: 20 * time.Second}}, nil
}

func (p *Panel) request(ctx context.Context, method, path string, body any) (*http.Response, error) {
	var r io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, err
		}
		r = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, p.address+path, r)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+p.token)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "console-agent/"+version)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := p.hc.Do(req)
	if err != nil {
		return nil, err
	}
	switch {
	case resp.StatusCode == http.StatusConflict || resp.StatusCode == http.StatusNotFound:
		resp.Body.Close()
		return nil, errConflict
	case resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden:
		resp.Body.Close()
		return nil, fmt.Errorf("panel jetonu reddetti (%d)", resp.StatusCode)
	case resp.StatusCode >= 300:
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 300))
		resp.Body.Close()
		return nil, fmt.Errorf("panel %d: %s", resp.StatusCode, bytes.TrimSpace(b))
	}
	return resp, nil
}

func (p *Panel) jobs(ctx context.Context) ([]Job, error) {
	resp, err := p.request(ctx, http.MethodGet, "/api/v1/console/jobs", nil)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	var v struct {
		Data []Job `json:"data"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&v); err != nil {
		return nil, err
	}
	return v.Data, nil
}

// Yapılandırma metni. Diske yazılmaz.
func (p *Panel) configuration(ctx context.Context, uuid string) (string, error) {
	resp, err := p.request(ctx, http.MethodGet, "/api/v1/console/jobs/"+uuid+"/configuration", nil)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	b, err := io.ReadAll(io.LimitReader(resp.Body, 8<<20))
	return string(b), err
}

func (p *Panel) notify(ctx context.Context, uuid, stage, message string) error {
	resp, err := p.request(ctx, http.MethodPost, "/api/v1/console/jobs/"+uuid+"/event", map[string]string{"stage": stage, "message": message})
	if err != nil {
		return err
	}
	resp.Body.Close()
	return nil
}

// OutputResponse: panelden konsola elle yazılanlar ve iptal isteği.
type OutputResponse struct {
	Inputs    []string `json:"inputs"`
	Cancelled bool     `json:"cancelled"`
}

func (p *Panel) output(ctx context.Context, uuid string, text []byte) (*OutputResponse, error) {
	resp, err := p.request(ctx, http.MethodPost, "/api/v1/console/jobs/"+uuid+"/output", map[string]string{"text": string(text)})
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	v := &OutputResponse{}
	if err := json.NewDecoder(resp.Body).Decode(v); err != nil {
		return nil, err
	}
	return v, nil
}
