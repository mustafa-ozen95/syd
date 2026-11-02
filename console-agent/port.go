package main

import (
	"fmt"
	"regexp"
	"slices"
	"strings"

	"go.bug.st/serial"
	"go.bug.st/serial/enumerator"
)

// Panel seri portu COM numarasıyla değil KABLONUN KİMLİĞİYLE verir: Windows
// kablo başka USB girişine takılınca ona yeni COM numarası veriyor (sahada
// COM3 → COM9 oldu, iş olmayan porta gitti).
//
//	COM9                                       eski kayıt: olduğu gibi açılır
//	/dev/serial/by-id/usb-FTDI_…-if00-port0     Linux: udev bağı, olduğu gibi açılır
//	FTDIBUS\VID_0403+PID_6001+A50285BIA\0000   Windows aygıt örneği: o anki COM
//	                                           adı kayıt defterinden okunur
var reVidPid = regexp.MustCompile(`(?i)VID_([0-9A-F]{4})[&+]PID_([0-9A-F]{4})`)

// resolvePort kablo kimliğini şu an açılacak port adına çevirir.
func resolvePort(id string) (string, error) {
	if !strings.Contains(id, `\`) {
		return id, nil
	}
	present, _ := serial.GetPortsList()

	// Kayıt defteri çıkarılmış aygıtların COM adını da saklar: yalnız şu an
	// var olan port kabul edilir.
	if name, err := portNameFromRegistry(id); err == nil && slices.Contains(present, name) {
		return name, nil
	}

	// Seri numarası olmayan kablolarda (Prolific, CH340) kimlik USB girişine
	// bağlıdır; başka girişe takılınca değişir. Aynı model (VID/PID) tek
	// kablo takılıysa o alınır; birden çoksa hangisi olduğu bilinemez.
	if m := reVidPid.FindStringSubmatch(id); m != nil {
		if details, err := enumerator.GetDetailedPortsList(); err == nil {
			var same []string
			for _, d := range details {
				if d.IsUSB && strings.EqualFold(d.VID, m[1]) && strings.EqualFold(d.PID, m[2]) {
					same = append(same, d.Name)
				}
			}
			if len(same) == 1 {
				return same[0], nil
			}
			if len(same) > 1 {
				return "", fmt.Errorf("konsol kablosu bulunamadı (%s); aynı modelden %d kablo var: %s. Kabloyu panelde algılandığı USB girişine takın ya da PC'yi \"Sına\" ile yeniden okutun", id, len(same), strings.Join(same, ", "))
			}
		}
	}
	return "", fmt.Errorf("konsol kablosu PC'ye takılı değil (%s). Kabloyu takın; başka bir kablo kullanılacaksa PC'yi panelde \"Sına\" ile yeniden okutun", id)
}
