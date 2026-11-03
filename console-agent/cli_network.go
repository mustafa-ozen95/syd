package main

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strconv"
	"strings"
	"time"
)

/*
 * CLI KURULUMUNDA GEÇİCİ ADRES
 *
 * `request system software add http://...` görüntüyü cihazın kendi ağından
 * çeker. Sıfır yapılandırmalı cihazın adresi yoktur ("fetch-secure: ...:
 * Can't assign requested address", EX2300'de görüldü). Loader ve U-Boot'taki
 * gibi geçici bir adres komutu CLI'de yok: adres yapılandırmaya yazılıp
 * commit edilir, kurulumdan sonra geri alınır.
 *
 * Kablonun hangi porta takılı olduğu konsoldan bilinemez: yönetim portları
 * (me0/em0/fxp0/vme) önce, sonra ön portların L3 arayüzü (irb.0/vlan.0)
 * denenir; her denemeden sonra cihazdan sunucuya ping atılır, tutmayanın
 * ayarı geri alınır.
 *
 * Junos root parolası olmayan yapılandırmada HİÇBİR commit'i kabul etmez;
 * öyle bir cihaza işteki root parolası konur (panel özetini gönderir).
 *
 * Ajan kurulum sırasında yeniden başlarsa (Resume) bu bilgi kaybolur ve
 * geçici adres cihazda kalır; yedek yüklenen işte zaten "load override"
 * siler.
 */

type cliNetwork struct {
	ifname string   // "me0", "irb"...
	saved  []string // arayüzün ve varsayılan rotanın eski "set" satırları
}

// rePingReceived Junos ping özeti: "5 packets transmitted, 5 packets received".
var rePingReceived = regexp.MustCompile(`(\d+) packets received`)

// prepareCliNetwork cihaz kurulum sunucusuna ulaşmıyorsa işteki adresi verir.
func (a *Flow) prepareCliNetwork(ctx context.Context) error {
	if a.job.ImageServer == "" {
		return nil
	}
	if a.devicePing(ctx) {
		a.notify("bilgi", "Cihaz kurulum sunucusuna ("+a.job.ImageServer+") ulaşıyor; adres verilmedi.")
		return nil
	}
	if a.job.CliAddress == "" {
		a.notify("uyari", "Cihaz kurulum sunucusuna ulaşmıyor ve işte sabit adres yok (DHCP); adres verilmeden deneniyor.")
		return nil
	}
	ip, _, _ := strings.Cut(a.job.CliAddress, "/")
	if a.addressInUse(ip) {
		return fmt.Errorf("kurulum adresi %s ağda başka bir cihazda kullanılıyor; işteki adresi değiştirin", ip)
	}

	rootLine := ""
	if m := reCount.FindStringSubmatch(a.output(ctx, "show configuration system root-authentication | count")); m == nil || m[1] == "0" {
		if a.job.RootHash == "" {
			return errors.New("cihaza adres verilemiyor: cihazda root parolası yok ve Junos parolasız yapılandırmada commit kabul etmiyor. İşi \"root parolası\" alanı doldurularak yeniden açın; ajan bu parolayı cihaza koyar")
		}
		rootLine = `set system root-authentication encrypted-password "` + a.job.RootHash + `"`
	}

	candidates := cliCandidates(a.output(ctx, "show interfaces terse | no-more"))
	if len(candidates) == 0 {
		return errors.New("cihaza adres verilemiyor: yönetim portu (me0/em0/fxp0/vme) ya da irb.0/vlan.0 bulunamadı")
	}
	for _, ifname := range candidates {
		saved := append(a.showSet(ctx, "interfaces "+ifname, "set interfaces "+ifname+" unit 0 family inet"),
			a.showSet(ctx, "routing-options static", "set routing-options static route 0.0.0.0/0 ")...)
		lines := []string{}
		if rootLine != "" {
			lines = append(lines, rootLine)
		}
		// ZTP DHCP'den adres ve görüntü aramayı sürdürüp ayarı ezmesin.
		lines = append(lines,
			"delete chassis auto-image-upgrade",
			"delete interfaces "+ifname+" unit 0 family inet",
			"set interfaces "+ifname+" unit 0 family inet address "+a.job.CliAddress)
		if a.job.CliGateway != "" {
			lines = append(lines,
				"delete routing-options static route 0.0.0.0/0",
				"set routing-options static route 0.0.0.0/0 next-hop "+a.job.CliGateway)
		}
		if err := a.commitLines(ctx, lines); err != nil {
			return fmt.Errorf("cihaza adres verilemedi (%s): %w", ifname, err)
		}
		if rootLine != "" {
			rootLine = ""
			a.notify("bilgi", "Cihaza işteki root parolası kondu (Junos parolasız yapılandırmada commit kabul etmiyor).")
		}
		a.cliNet = &cliNetwork{ifname: ifname, saved: saved}
		if a.devicePing(ctx) {
			a.notify("kurulum", "Cihaza geçici adres verildi: "+a.job.CliAddress+" ("+ifname+"); kurulum sunucusuna ulaşıyor.")
			return nil
		}
		a.notify("bilgi", ifname+" üzerinden kurulum sunucusuna ulaşılamadı; ayarı geri alınıyor.")
		a.restoreCliNetwork(ctx)
	}
	return fmt.Errorf("cihaza %s adresi verildi ama kurulum sunucusuna (%s) ulaşılamadı (denenen: %s). Kablonun takılı olduğu portu ve ağ geçidini kontrol edin",
		a.job.CliAddress, a.job.ImageServer, strings.Join(candidates, ", "))
}

// restoreCliNetwork geçici adresi kaldırır, arayüzün eski ayarını geri yazar.
// Kurulum bitmiştir: geri alınamazsa yalnız uyarılır.
func (a *Flow) restoreCliNetwork(ctx context.Context) {
	n := a.cliNet
	if n == nil {
		return
	}
	a.cliNet = nil
	lines := []string{"delete interfaces " + n.ifname + " unit 0 family inet"}
	if a.job.CliGateway != "" {
		lines = append(lines, "delete routing-options static route 0.0.0.0/0")
	}
	lines = append(lines, n.saved...)
	if err := a.commitLines(ctx, lines); err != nil {
		a.notify("uyari", "Geçici adres ("+n.ifname+") geri alınamadı: "+err.Error())
		return
	}
	a.notify("bilgi", "Geçici adres kaldırıldı; "+n.ifname+" eski ayarına döndü.")
}

// cliCandidates adres verilebilecek arayüzler, denenme sırasıyla.
func cliCandidates(terse string) []string {
	var out []string
	for _, n := range []string{"me0", "em0", "fxp0", "vme"} {
		if regexp.MustCompile(`(?m)^` + n + `(\.\d+)?\s`).MatchString(terse) {
			out = append(out, n)
		}
	}
	// Ön portların L3 arayüzü yalnız tanımlıysa (fabrika ayarında irb.0 var).
	for _, n := range []string{"irb", "vlan"} {
		if regexp.MustCompile(`(?m)^` + n + `\.0\s`).MatchString(terse) {
			out = append(out, n)
		}
	}
	return out
}

// devicePing cihazdan kurulum sunucusuna ping; bir cevap yeter.
func (a *Flow) devicePing(ctx context.Context) bool {
	m := rePingReceived.FindStringSubmatch(a.output(ctx, "ping "+a.job.ImageServer+" count 5 rapid"))
	if m == nil {
		return false
	}
	n, _ := strconv.Atoi(m[1])
	return n > 0
}

// output CLI komutunun çıktısı (temizlenmiş).
func (a *Flow) output(ctx context.Context, line string) string {
	pos := a.k.Position()
	_ = a.run(ctx, line, time.Minute, reCli)
	return clean(a.k.Text(pos))
}

// run komutu yazar, yankısının bitmesini, sonra istemi bekler. Junos 80
// sütunu aşan satırı kaydırarak yeniden çizer ("root> ... add http://…",
// EX2300'de görüldü): yankı sürerken ekranın sonu bir an istem gibi
// görünür; istem ancak satırın sonu yansıdıktan sonra aranır.
func (a *Flow) run(ctx context.Context, line string, timeout time.Duration, prompt *regexp.Regexp) error {
	pos := a.k.Position()
	if err := a.send(line + "\r"); err != nil {
		return err
	}
	tail := line
	if len(tail) > 12 {
		tail = tail[len(tail)-12:]
	}
	from := pos
	end := time.Now().Add(10 * time.Second)
	for time.Now().Before(end) {
		if i := strings.LastIndex(a.k.Text(pos), tail); i >= 0 {
			from = pos + int64(i+len(tail))
			break
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(20 * time.Millisecond):
		}
	}
	_, err := a.k.Wait(ctx, from, timeout, prompt)
	if errors.Is(err, errTimeout) {
		// Araya ZTP satırı düştüyse istem son satır değildir: yeniden çizdir.
		from = a.k.Position()
		if err := a.send("\r"); err != nil {
			return err
		}
		_, err = a.k.Wait(ctx, from, timeout, prompt)
	}
	return err
}

// showSet yapılandırmanın bir bölümünün, önekle başlayan "set" satırları.
// Komut kısa tutulur (bölümün üstü sorulur, süzülür): kaydırılan satırın
// yankısı çıktıya karışmasın.
func (a *Flow) showSet(ctx context.Context, section, prefix string) []string {
	var out []string
	for _, l := range strings.Split(a.output(ctx, "show configuration "+section+" | display set | no-more"), "\n") {
		if l = strings.TrimSpace(l); strings.HasPrefix(l, prefix) {
			out = append(out, l)
		}
	}
	return out
}

// commitLines satırları yapılandırma kipinde yazıp commit eder. Bir satır ya
// da commit reddedilirse aday atılır ve CLI'ye dönülür.
func (a *Flow) commitLines(ctx context.Context, lines []string) error {
	if err := a.command(ctx, "configure", 20*time.Second, reConf); err != nil {
		return fmt.Errorf("yapılandırma kipine girilemedi: %w", err)
	}
	for _, l := range lines {
		pos := a.k.Position()
		if err := a.run(ctx, l, 20*time.Second, reConf); err != nil {
			a.abortConfig(ctx)
			return err
		}
		if out := replyLines(a.k.Text(pos), l); reError.MatchString(out) {
			a.abortConfig(ctx)
			return fmt.Errorf("%s: %s", l, lastLines(out, 3))
		}
	}
	pos := a.k.Position()
	if err := a.send("commit and-quit\r"); err != nil {
		return err
	}
	i, err := a.k.Wait(ctx, pos, 3*time.Minute, reCommit, reError)
	if err != nil || i != 0 {
		out := lastLines(a.k.Text(pos), 4)
		a.abortConfig(ctx)
		return fmt.Errorf("commit reddedildi: %s", out)
	}
	_, _ = a.k.Wait(ctx, pos, time.Minute, reCli)
	return nil
}

func (a *Flow) abortConfig(ctx context.Context) {
	_ = a.command(ctx, "rollback 0", 20*time.Second, reConf)
	_ = a.command(ctx, "exit configuration-mode", 20*time.Second, reCli)
}
