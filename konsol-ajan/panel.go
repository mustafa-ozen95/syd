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
type Is struct {
	UUID            string   `json:"uuid"`
	SeriPort        string   `json:"seri_port"`
	CihazIP         string   `json:"cihaz_ip"`
	LoaderKomutlari []string `json:"loader_komutlari"`
	KurulumKomutu   string   `json:"kurulum_komutu"`
	KurulumDakika   int      `json:"kurulum_dakika"`
	BeklenenSurum   string   `json:"beklenen_surum"`
	Parola          string   `json:"parola"`
	Yapilandirma    bool     `json:"yapilandirma"`
	YuklemeKomutu   string   `json:"yukleme_komutu"`
	OnceSil         bool     `json:"once_sil"`
}

// Panelin "iş artık yok" yanıtı: iptal edildi ya da silindi.
var errCakisma = errors.New("iş artık bu ajanın değil")

type Panel struct {
	adres string
	jeton string
	hc    *http.Client
}

func yeniPanel(adres, jeton, caDosyasi string) (*Panel, error) {
	tr := http.DefaultTransport.(*http.Transport).Clone()
	if caDosyasi != "" {
		pem, err := os.ReadFile(caDosyasi)
		if err != nil {
			return nil, err
		}
		// Sistemin kökleri KORUNUR, dosyadaki sertifika EKLENİR: panel
		// kendinden imzalıyken de, sonra gerçek sertifikaya geçince de çalışır.
		havuz, err := x509.SystemCertPool()
		if err != nil || havuz == nil {
			havuz = x509.NewCertPool()
		}
		if !havuz.AppendCertsFromPEM(pem) {
			return nil, fmt.Errorf("%s içinde sertifika yok", caDosyasi)
		}
		tr.TLSClientConfig = &tls.Config{RootCAs: havuz, MinVersion: tls.VersionTLS12}
	}
	return &Panel{adres: adres, jeton: jeton, hc: &http.Client{Transport: tr, Timeout: 20 * time.Second}}, nil
}

func (p *Panel) istek(ctx context.Context, yontem, yol string, govde any) (*http.Response, error) {
	var r io.Reader
	if govde != nil {
		b, err := json.Marshal(govde)
		if err != nil {
			return nil, err
		}
		r = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, yontem, p.adres+yol, r)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+p.jeton)
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "konsol-ajan/"+surum)
	if govde != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	yanit, err := p.hc.Do(req)
	if err != nil {
		return nil, err
	}
	switch {
	case yanit.StatusCode == http.StatusConflict || yanit.StatusCode == http.StatusNotFound:
		yanit.Body.Close()
		return nil, errCakisma
	case yanit.StatusCode == http.StatusUnauthorized || yanit.StatusCode == http.StatusForbidden:
		yanit.Body.Close()
		return nil, fmt.Errorf("panel jetonu reddetti (%d)", yanit.StatusCode)
	case yanit.StatusCode >= 300:
		b, _ := io.ReadAll(io.LimitReader(yanit.Body, 300))
		yanit.Body.Close()
		return nil, fmt.Errorf("panel %d: %s", yanit.StatusCode, bytes.TrimSpace(b))
	}
	return yanit, nil
}

func (p *Panel) isler(ctx context.Context) ([]Is, error) {
	yanit, err := p.istek(ctx, http.MethodGet, "/api/v1/konsol/isler", nil)
	if err != nil {
		return nil, err
	}
	defer yanit.Body.Close()
	var v struct {
		Data []Is `json:"data"`
	}
	if err := json.NewDecoder(yanit.Body).Decode(&v); err != nil {
		return nil, err
	}
	return v.Data, nil
}

// Yapılandırma metni. Diske yazılmaz.
func (p *Panel) yapilandirma(ctx context.Context, uuid string) (string, error) {
	yanit, err := p.istek(ctx, http.MethodGet, "/api/v1/konsol/isler/"+uuid+"/yapilandirma", nil)
	if err != nil {
		return "", err
	}
	defer yanit.Body.Close()
	b, err := io.ReadAll(io.LimitReader(yanit.Body, 8<<20))
	return string(b), err
}

func (p *Panel) bildir(ctx context.Context, uuid, asama, mesaj string) error {
	yanit, err := p.istek(ctx, http.MethodPost, "/api/v1/konsol/isler/"+uuid+"/olay", map[string]string{"asama": asama, "mesaj": mesaj})
	if err != nil {
		return err
	}
	yanit.Body.Close()
	return nil
}

// CiktiYaniti: panelden konsola elle yazılanlar ve iptal isteği.
type CiktiYaniti struct {
	Girdiler []string `json:"girdiler"`
	Iptal    bool     `json:"iptal"`
}

func (p *Panel) cikti(ctx context.Context, uuid string, metin []byte) (*CiktiYaniti, error) {
	yanit, err := p.istek(ctx, http.MethodPost, "/api/v1/konsol/isler/"+uuid+"/cikti", map[string]string{"metin": string(metin)})
	if err != nil {
		return nil, err
	}
	defer yanit.Body.Close()
	v := &CiktiYaniti{}
	if err := json.NewDecoder(yanit.Body).Decode(v); err != nil {
		return nil, err
	}
	return v, nil
}
