package main

import (
	"context"
	"errors"
	"fmt"
	"os/exec"
	"regexp"
	"runtime"
	"strings"
	"time"
)

/*
 * KONSOLDAN KURULUM AKIŞI — elle kurulumun aynısı:
 *
 *   cihaz ne durumdaysa loader'a indirilir (çalışıyorsa yeniden başlatılır,
 *   açılışta art arda boşluk), loader'da adres verilir,
 *   `install --format tftp://...` yazılır, cihaz açılınca sürüm doğrulanır,
 *   istenmişse yedek `load override terminal` ile yüklenip commit edilir.
 *
 * Komutların metni panelden gelir (iş tanımı); burada yalnız istemler
 * beklenir. İstemler cihazın son satırına bakılarak tanınır.
 */

var (
	// Junos'un loader istemi "loader>"; FreeBSD'nin kendi istemi "OK".
	reLoader   = regexp.MustCompile(`(loader>|(^|\n)OK)\s*$`)
	reUboot    = regexp.MustCompile(`(^|\n)\s*=>\s*$`)
	reDb       = regexp.MustCompile(`db>\s*$`)
	reMount    = regexp.MustCompile(`mountroot>\s*$`)
	reLogin    = regexp.MustCompile(`login:\s*$`)
	rePassword = regexp.MustCompile(`(?i)password:\s*$`)
	reShell    = regexp.MustCompile(`%\s*$`)
	reConf     = regexp.MustCompile(`#\s*$`)
	// Junos kurucusu düşünce açılan kabuk: satır başında yalnız "#"
	// (yapılandırma modunda önünde "root" vardır). "exit" yeniden başlatır
	// (kurucu: "A shell has been started. Type exit<cr> to reboot").
	reInstaller = regexp.MustCompile(`(^|\n)#\s*$`)
	// Kurucunun kendi hata yazısı: beklemek boşuna.
	reInstallerError = regexp.MustCompile(`(?i)installation is unsuccessful|installer exited with status`)
	reCli            = regexp.MustCompile(`[\w.@:-]+>\s*$`)
	reYesNo          = regexp.MustCompile(`\[yes,no\][^\n]*$`)
	reWrongLogin     = regexp.MustCompile(`(?i)login incorrect`)
	// Açılışın ya da kapanışın başladığını gösteren yazılar: boşluk ancak
	// bunlardan sonra gönderilir (giriş istemine boşluk yazılmasın).
	reBoot = regexp.MustCompile(`(?i)u-boot|hit any key|bootstrap loader|loading /boot|kernel data=|booting \[|space bar|copyright \(c\)|rebooting|syncing disks|shutdown now|uptime:|power-on|board:|dram:`)
	// EX2300/QFX5100 gibi yeni platformlar konsolu Enter ile açar.
	reActivate = regexp.MustCompile(`(?i)press enter to activate this console\.?\s*$`)
	// EX2300/QFX5100'ün açılış menüsü: otomatik seçilmez, operatöre söylenir.
	reMenu = regexp.MustCompile(`(?i)choice:\s*$`)
	// EX2300'ün loader'ı boşlukla durmaz ("Autoboot in 3 seconds... (press
	// Ctrl-C to interrupt)").
	reAutoboot = regexp.MustCompile(`(?i)press ctrl-c to interrupt`)
	// Menüde loader istemine götüren madde ve "daha fazla" maddesi; numara
	// ekrandan okunur.
	reMenuLoaderItem = regexp.MustCompile(`(?i)(\d+)\.\s*[^\n\d]{0,30}?(\[b\]oot prompt|boot \[p\]rompt|loader \[?p\]?rompt|escape to loader)`)
	reMenuMore       = regexp.MustCompile(`(?i)(\d+)\.\s*\[m\]ore options`)
	reLoadPrompt     = regexp.MustCompile(`\[Type \^D at a new line to end input\]`)
	reLoaded         = regexp.MustCompile(`load complete`)
	reCommit         = regexp.MustCompile(`commit complete`)
	reError          = regexp.MustCompile(`(?i)(syntax error|error:|failed|missing mandatory|load complete \(\d+ errors?\))`)
	reLoaderError    = regexp.MustCompile(`(?i)(not found|unknown command|invalid|error)`)
)

type state int

const (
	stSilent state = iota
	stLoader
	stUboot
	stDb
	stMount
	stYesNo
	stLogin
	stPassword
	stShell
	stInstaller
	stConf
	stCli
	stActivate
	stMenu
)

var stateNames = map[state]string{
	stSilent: "sessiz", stLoader: "loader", stUboot: "U-Boot", stDb: "db (çekirdek hata ayıklayıcı)",
	stMount: "mountroot", stYesNo: "onay sorusu", stLogin: "giriş", stPassword: "parola sorusu",
	stShell: "kabuk", stInstaller: "kurulum kabuğu (yarım kalmış kurulum)", stConf: "yapılandırma modu", stCli: "CLI",
	stActivate: "konsolu etkinleştirme sorusu", stMenu: "açılış menüsü",
}

// Sıra önemli: "loader>" ve "db>" CLI istemine de benzer.
var statePatterns = []struct {
	d  state
	re *regexp.Regexp
}{
	{stLoader, reLoader}, {stActivate, reActivate}, {stMenu, reMenu}, {stUboot, reUboot}, {stDb, reDb}, {stMount, reMount}, {stYesNo, reYesNo},
	{stLogin, reLogin}, {stPassword, rePassword}, {stShell, reShell}, {stInstaller, reInstaller}, {stConf, reConf}, {stCli, reCli},
}

var (
	errMissedLoader = errors.New("loader kaçırıldı: cihaz açılıp giriş istemine geldi")
	errPassword     = errors.New("cihaza girilemedi: root parolası yok ya da yanlış")
)

type Notifier func(stage, message string)

type Flow struct {
	k      *Console
	job    Job
	notify Notifier
	// configuration panelden yedek metnini alır; diske yazılmaz.
	configuration func(ctx context.Context) (string, error)
	// addressInUse kurulum adresine başka bir cihazın cevap verip
	// vermediğini söyler (testte sahte).
	addressInUse func(ip string) bool
	// Bekleme süreleri (testte kısaltılır).
	bootTimeout    time.Duration
	installTimeout time.Duration
	manualTimeout  time.Duration
}

func (a *Flow) send(s string) error { return a.k.Send(s) }

// detectState Enter gönderip son istemi tanır.
func (a *Flow) detectState(ctx context.Context) (state, error) {
	pos := a.k.Position()
	if err := a.send("\r"); err != nil {
		return stSilent, err
	}
	patterns := make([]*regexp.Regexp, len(statePatterns))
	for i, d := range statePatterns {
		patterns[i] = d.re
	}
	i, err := a.k.Wait(ctx, pos, 4*time.Second, patterns...)
	if errors.Is(err, errTimeout) {
		return stSilent, nil
	}
	if err != nil {
		return stSilent, err
	}
	return statePatterns[i].d, nil
}

// dropToLoader cihazı hangi durumdaysa loader istemine getirir.
func (a *Flow) dropToLoader(ctx context.Context) error {
	for attempt := 0; attempt < 6; attempt++ {
		start := a.k.Position()
		d, err := a.detectState(ctx)
		if err != nil {
			return err
		}
		a.notify("bilgi", "Konsolda görülen: "+stateNames[d]+".")

		switch d {
		case stLoader:
			return nil
		case stYesNo:
			if err := a.send("no\r"); err != nil {
				return err
			}
			continue
		case stActivate:
			continue // detectState zaten Enter gönderiyor
		case stMenu:
			if choice := menuChoice(clean(a.k.Text(start))); choice != "" {
				a.notify("bilgi", "Açılış menüsü: "+choice+" seçiliyor.")
				pos := a.k.Position()
				if err := a.send(choice + "\r"); err != nil {
					return err
				}
				err = a.waitBootFrom(ctx, a.bootTimeout, pos)
				break
			}
			a.notify("uyari", "Cihaz açılış menüsünde (Choice:) ama loader maddesi okunamadı. Menüden loader'a ([B]oot prompt) inmek için konsola elle yazın ya da cihazın elektriğini çekip takın.")
			err = a.waitBoot(ctx, a.manualTimeout)
		case stInstaller:
			a.notify("bilgi", "Önceki kurulum yarım kalmış (kurulum kabuğu); cihaz exit ile yeniden başlatılıyor.")
			if err := a.send("exit\r"); err != nil {
				return err
			}
			err = a.waitBoot(ctx, a.bootTimeout)
		case stConf:
			pos := a.k.Position()
			if err := a.send("exit configuration-mode\r"); err != nil {
				return err
			}
			// Commit edilmemiş değişiklik varsa sorar; atılır.
			i, err := a.k.Wait(ctx, pos, 10*time.Second, reCli, reYesNo)
			if err == nil && i == 1 {
				err = a.send("yes\r")
			}
			if err != nil && !errors.Is(err, errTimeout) {
				return err
			}
			continue
		case stShell:
			if err := a.command(ctx, "cli", 20*time.Second, reCli); err != nil && !errors.Is(err, errTimeout) {
				return err
			}
			continue
		case stPassword:
			// Yarım kalmış bir giriş: boş geçilir, giriş istemi yeniden gelir.
			if err := a.send("\r"); err != nil {
				return err
			}
			time.Sleep(3 * time.Second)
			continue
		case stLogin:
			if err := a.login(ctx); err != nil {
				if !errors.Is(err, errPassword) {
					return err
				}
				a.notify("uyari", "Cihaza girilemedi (root parolası yok ya da yanlış). Cihazın elektriğini çekip takın; açılışı bekliyorum, loader'a kendim gireceğim.")
				err = a.waitBoot(ctx, a.manualTimeout)
				break
			}
			continue
		case stCli:
			a.notify("bilgi", "Cihaz çalışıyor; loader'a inmek için yeniden başlatılıyor.")
			if err := a.reboot(ctx); err != nil {
				return err
			}
			err = a.waitBoot(ctx, a.bootTimeout)
		case stUboot:
			a.notify("bilgi", "U-Boot isteminde; loader açılıyor.")
			if err := a.send("boot\r"); err != nil {
				return err
			}
			err = a.waitBoot(ctx, a.bootTimeout)
		case stDb:
			a.notify("bilgi", "Cihaz çekirdek hata ayıklayıcısında (db>); yeniden başlatılıyor.")
			if err := a.send("reset\r"); err != nil {
				return err
			}
			err = a.waitBoot(ctx, a.bootTimeout)
		default: // sessiz, mountroot
			a.notify("uyari", "Cihaza elektrik verin (açıksa çekip takın). Cihaz konsoldan cevap vermiyor ("+stateNames[d]+"); açılışı bekliyorum, loader'a kendim gireceğim.")
			// Sorulurken gelen (belki bozuk hızda) çıktı da sayılır.
			err = a.waitBootFrom(ctx, a.manualTimeout, start)
		}

		switch {
		case err == nil:
			return nil
		case errors.Is(err, errMenu):
			// Menüye Enter yazılmasın (varsayılan maddeyi açabilir): yalnız
			// operatörün loader'a inmesi beklenir.
			a.notify("uyari", "Açılış menüsünde loader maddesi okunamadı. Menüden loader'a ([B]oot prompt) konsoldan elle inin; loader istemini görünce devam ederim.")
			if _, err := a.k.Wait(ctx, a.k.Position(), a.manualTimeout, reLoader); err != nil {
				return fmt.Errorf("loader istemi gelmedi: %w", err)
			}
			return nil
		case errors.Is(err, errMissedLoader):
			a.notify("bilgi", "Loader kaçırıldı; yeniden deneniyor.")
		default:
			return err
		}
	}
	return errors.New("cihaz birkaç denemede loader'a indirilemedi")
}

// command bir satır yazar ve desenlerden birini bekler.
func (a *Flow) command(ctx context.Context, line string, timeout time.Duration, patterns ...*regexp.Regexp) error {
	pos := a.k.Position()
	if err := a.send(line + "\r"); err != nil {
		return err
	}
	_, err := a.k.Wait(ctx, pos, timeout, patterns...)
	return err
}

// login giriş isteminden root olarak kabuğa ya da CLI'ye geçer.
func (a *Flow) login(ctx context.Context) error {
	pos := a.k.Position()
	if err := a.send("root\r"); err != nil {
		return err
	}
	i, err := a.k.Wait(ctx, pos, 15*time.Second, rePassword, reShell, reCli, reWrongLogin, reLogin)
	if err != nil {
		return fmt.Errorf("giriş: %w", err)
	}
	if i == 0 {
		pos = a.k.Position()
		if err := a.send(a.job.Password + "\r"); err != nil {
			return err
		}
		i, err = a.k.Wait(ctx, pos, 30*time.Second, reShell, reCli, reWrongLogin, reLogin)
		if err != nil {
			return fmt.Errorf("giriş: %w", err)
		}
		i++
	}
	switch i {
	case 1: // kabuk
		if err := a.command(ctx, "cli", 20*time.Second, reCli); err != nil {
			return fmt.Errorf("cli açılmadı: %w", err)
		}
		return nil
	case 2:
		return nil
	default:
		return errPassword
	}
}

func (a *Flow) reboot(ctx context.Context) error {
	if err := a.command(ctx, "request system reboot", 20*time.Second, reYesNo); err != nil {
		return fmt.Errorf("yeniden başlatma sorusu gelmedi: %w", err)
	}
	return a.send("yes\r")
}

var errMenu = errors.New("açılış menüsünde loader maddesi okunamadı")

// menuChoice menüde loader istemine götüren maddenin numarası; o yoksa
// "daha fazla seçenek"in numarası. İkisi de yoksa boş.
func menuChoice(text string) string {
	for _, re := range []*regexp.Regexp{reMenuLoaderItem, reMenuMore} {
		if len(text) > 4000 {
			text = text[len(text)-4000:]
		}
		if m := re.FindAllStringSubmatch(text, -1); len(m) > 0 {
			return m[len(m)-1][1]
		}
	}
	return ""
}

// garbleSample hızın tutup tutmadığına karar vermek için gereken bayt.
const garbleSample = 24

// waitBoot açılış başlayınca art arda boşluk göndererek loader
// istemini yakalar. U-Boot'ta durulursa "boot" ile loader'a geçilir.
//
// Cihaz kapalıyken hız bulunamaz; ajan varsayılan hızda bekler. Cihaz başka
// hızda açılırsa (ör. elektrik ajan hazır olduktan sonra verildiyse) gelen
// baytlar bozuk görünür: okunur metin
// görülene kadar sıradaki hıza geçilir.
func (a *Flow) waitBoot(ctx context.Context, timeout time.Duration) error {
	return a.waitBootFrom(ctx, timeout, a.k.Position())
}

func (a *Flow) waitBootFrom(ctx context.Context, timeout time.Duration, pos int64) error {
	end := time.Now().Add(timeout)
	booted := false
	ubootCount := 0
	speedOk, speedChanges := false, 0
	ctrlc, menu, spaceOK := 0, 0, true
	for time.Now().Before(end) {
		if !speedOk && !booted {
			raw := a.k.Text(pos)
			if score, count := readability(raw); count >= garbleSample {
				if score >= 0.8 {
					speedOk = true
				} else if speedChanges < 2*len(speeds) {
					speedChanges++
					old := a.k.speed
					fresh, err := a.k.NextSpeed()
					if err != nil {
						return fmt.Errorf("konsol hızı değiştirilemedi: %w", err)
					}
					a.notify("bilgi", fmt.Sprintf("Konsolda bozuk karakterler: %d baud tutmuyor, %d deneniyor.", old, fresh))
					pos = a.k.Position()
					continue
				}
			}
		}
		text := clean(a.k.Text(pos))
		switch {
		case reLoader.MatchString(text):
			return nil
		case reUboot.MatchString(text) && ubootCount < 3:
			// Boşluk U-Boot'un geri sayımını da durdurur; "boot" ile loader
			// açılır, boşluk loader'ın yazısı görülünce yeniden başlar.
			ubootCount++
			booted = false
			pos = a.k.Position()
			if err := a.send("boot\r"); err != nil {
				return err
			}
			continue
		case booted && reLogin.MatchString(text):
			return errMissedLoader
		case reAutoboot.MatchString(text) && ctrlc < 5:
			if ctrlc == 0 {
				a.notify("acilis", "Autoboot geri sayımı görüldü; Ctrl-C gönderiliyor.")
			}
			ctrlc++
			booted, spaceOK = true, false // boşluk menünün istemine yazılmasın
			pos = a.k.Position()
			if err := a.send("\x03"); err != nil {
				return err
			}
			continue
		case reMenu.MatchString(text):
			choice := menuChoice(text)
			if choice == "" || menu >= 4 {
				return errMenu
			}
			menu++
			a.notify("bilgi", "Açılış menüsü: "+choice+" seçiliyor.")
			pos = a.k.Position()
			if err := a.send(choice + "\r"); err != nil {
				return err
			}
			continue
		case reActivate.MatchString(text):
			pos = a.k.Position()
			if err := a.send("\r"); err != nil {
				return err
			}
			continue
		}
		if !booted && reBoot.MatchString(text) {
			booted = true
			a.notify("acilis", "Açılış görüldü; loader için boşluk gönderiliyor.")
		}
		if booted && spaceOK {
			if err := a.send(" "); err != nil {
				return err
			}
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(150 * time.Millisecond):
		}
	}
	if booted {
		return errors.New("açılış görüldü ama loader istemi gelmedi")
	}
	return errors.New("cihaz açılmadı: konsolda hiçbir şey görülmedi")
}

// Install bütün akış.
func (a *Flow) Install(ctx context.Context) error {
	a.notify("loader", "Cihaz loader'a indiriliyor.")
	if err := a.dropToLoader(ctx); err != nil {
		return err
	}
	// Yakalarken yazılan boşluklar satırda kalmasın: boş satırla temiz istem.
	time.Sleep(500 * time.Millisecond)
	if err := a.command(ctx, "", 5*time.Second, reLoader); err != nil {
		return fmt.Errorf("loader istemi: %w", err)
	}
	a.notify("loader", "Loader istemindeyim.")

	if a.job.DeviceIP != "" && a.addressInUse(a.job.DeviceIP) {
		return fmt.Errorf("kurulum adresi %s ağda başka bir cihazda kullanılıyor; işteki adresi değiştirin ya da DHCP seçin", a.job.DeviceIP)
	}

	for _, line := range a.job.LoaderCommands {
		pos := a.k.Position()
		if err := a.command(ctx, line, 15*time.Second, reLoader); err != nil {
			return fmt.Errorf("loader komutu (%s): %w", line, err)
		}
		if reply := replyLines(a.k.Text(pos), line); reLoaderError.MatchString(reply) {
			return fmt.Errorf("loader komutu reddetti (%s): %s", line, reply)
		}
	}

	a.notify("kurulum", "Kurulum başladı: "+a.job.InstallCommand)
	pos := a.k.Position()
	if err := a.send(a.job.InstallCommand + "\r"); err != nil {
		return err
	}
	if err := a.watchInstall(ctx, pos); err != nil {
		return err
	}
	a.notify("kurulum", "Cihaz yeni yazılımla açıldı.")

	if err := a.login(ctx); err != nil {
		return fmt.Errorf("kurulumdan sonra giriş: %w", err)
	}
	if err := a.verifyVersion(ctx); err != nil {
		return err
	}
	if a.job.Configuration {
		if err := a.loadConfiguration(ctx); err != nil {
			return err
		}
	}
	// Console oturumu açık bırakılmaz.
	_ = a.command(ctx, "exit", 10*time.Second, reLogin, reShell)
	if reShell.MatchString(clean(a.k.Text(a.k.Position() - 100))) {
		_ = a.command(ctx, "exit", 10*time.Second, reLogin)
	}
	return nil
}

// watchInstall install komutundan cihazın giriş istemine gelmesine kadar.
// Loader istemi geri gelirse kurulum başarısızdır (loader hata verince
// isteme döner).
func (a *Flow) watchInstall(ctx context.Context, pos int64) error {
	end := time.Now().Add(a.installTimeout)
	began := time.Now()
	lastNotice := time.Now()
	for {
		remaining := time.Until(end)
		if remaining <= 0 {
			return fmt.Errorf("kurulum %s içinde bitmedi", a.installTimeout)
		}
		waitFor := 30 * time.Second
		if remaining < waitFor {
			waitFor = remaining
		}
		i, err := a.k.Wait(ctx, pos, waitFor, reLoader, reUboot, reLogin, reActivate, reInstallerError)
		switch {
		case err == nil && i == 4:
			return fmt.Errorf("Junos kurucusu başarısız: %s", lastLines(a.k.Text(pos), 8))
		case err == nil && i == 3:
			pos = a.k.Position()
			if err := a.send("\r"); err != nil {
				return err
			}
		case err == nil && i == 0:
			return fmt.Errorf("loader kurulumu yapmadı: %s", lastLines(a.k.Text(pos), 6))
		case err == nil && i == 1:
			return errors.New("kurulum sırasında cihaz U-Boot istemine düştü")
		case err == nil:
			return nil
		case errors.Is(err, errTimeout):
			if time.Since(lastNotice) >= 5*time.Minute {
				lastNotice = time.Now()
				a.notify("kurulum", fmt.Sprintf("Kurulum sürüyor (%d dk).", int(time.Since(began).Minutes())))
			}
		default:
			return err
		}
	}
}

func (a *Flow) verifyVersion(ctx context.Context) error {
	pos := a.k.Position()
	if err := a.command(ctx, "show version | no-more", 60*time.Second, reCli); err != nil {
		return fmt.Errorf("show version: %w", err)
	}
	output := clean(a.k.Text(pos))
	if a.job.ExpectedVersion == "" {
		a.notify("dogrulama", "Sürüm: "+versionLine(output))
		return nil
	}
	if !strings.Contains(output, a.job.ExpectedVersion) {
		return fmt.Errorf("cihazda beklenen sürüm (%s) görülmedi: %s", a.job.ExpectedVersion, versionLine(output))
	}
	a.notify("dogrulama", "Sürüm doğrulandı: "+a.job.ExpectedVersion)
	return nil
}

func (a *Flow) loadConfiguration(ctx context.Context) error {
	text, err := a.configuration(ctx)
	if err != nil {
		return fmt.Errorf("yapılandırma alınamadı: %w", err)
	}
	a.notify("yapilandirma", fmt.Sprintf("Yapılandırma yükleniyor (%d satır).", strings.Count(text, "\n")+1))

	if err := a.command(ctx, "configure", 20*time.Second, reConf); err != nil {
		return fmt.Errorf("configure: %w", err)
	}
	if a.job.DeleteFirst {
		if err := a.command(ctx, "delete", 10*time.Second, reYesNo); err != nil {
			return fmt.Errorf("delete: %w", err)
		}
		if err := a.command(ctx, "yes", 20*time.Second, reConf); err != nil {
			return fmt.Errorf("delete: %w", err)
		}
	}
	if err := a.command(ctx, a.job.LoadCommand, 20*time.Second, reLoadPrompt); err != nil {
		return fmt.Errorf("%s: %w", a.job.LoadCommand, err)
	}

	pos := a.k.Position()
	for _, line := range strings.Split(strings.ReplaceAll(text, "\r", ""), "\n") {
		if err := a.sendLine(ctx, line); err != nil {
			return err
		}
	}
	if err := a.send("\x04"); err != nil {
		return err
	}
	if _, err := a.k.Wait(ctx, pos, 3*time.Minute, reLoaded, reConf); err != nil {
		return fmt.Errorf("yükleme bitmedi: %w", err)
	}
	if reply := clean(a.k.Text(pos)); reError.MatchString(reply) {
		_ = a.command(ctx, "rollback 0", 20*time.Second, reConf)
		_ = a.command(ctx, "exit configuration-mode", 20*time.Second, reCli)
		return fmt.Errorf("yapılandırma hatalı, geri alındı: %s", errorLines(reply))
	}

	a.notify("yapilandirma", "Yüklendi; commit ediliyor.")
	pos = a.k.Position()
	if err := a.send("commit and-quit\r"); err != nil {
		return err
	}
	i, err := a.k.Wait(ctx, pos, 10*time.Minute, reCommit, reError)
	if err != nil {
		return fmt.Errorf("commit: %w", err)
	}
	if i == 1 {
		_ = a.command(ctx, "rollback 0", 20*time.Second, reConf)
		_ = a.command(ctx, "exit configuration-mode", 20*time.Second, reCli)
		return fmt.Errorf("commit reddedildi, geri alındı: %s", errorLines(clean(a.k.Text(pos))))
	}
	if _, err := a.k.Wait(ctx, pos, time.Minute, reCli); err != nil {
		return fmt.Errorf("commit sonrası: %w", err)
	}
	a.notify("yapilandirma", "Yapılandırma commit edildi.")
	return nil
}

// sendLine bir satırı yazar ve cihazın yankısını bekler: düşük hızda
// yapıştırılan metin cihazın girişini taşırmasın.
func (a *Flow) sendLine(ctx context.Context, line string) error {
	pos := a.k.Position()
	if err := a.send(line + "\n"); err != nil {
		return err
	}
	expected := int64(len(line))
	end := time.Now().Add(5 * time.Second)
	for a.k.Position()-pos < expected && time.Now().Before(end) {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(10 * time.Millisecond):
		}
	}
	return nil
}

// replyLines komutun yankısından sonra gelenler (istem hariç).
func replyLines(output, line string) string {
	s := clean(output)
	if i := strings.Index(s, line); i >= 0 {
		s = s[i+len(line):]
	}
	s = reLoader.ReplaceAllString(s, "")
	return strings.TrimSpace(s)
}

func lastLines(output string, n int) string {
	lines := strings.Split(strings.TrimSpace(clean(output)), "\n")
	if len(lines) > n {
		lines = lines[len(lines)-n:]
	}
	return strings.Join(lines, " | ")
}

func errorLines(output string) string {
	var found []string
	for _, s := range strings.Split(output, "\n") {
		if reError.MatchString(s) {
			found = append(found, strings.TrimSpace(s))
		}
		if len(found) == 5 {
			break
		}
	}
	return strings.Join(found, " | ")
}

var reJunos = regexp.MustCompile(`(?m)^(JUNOS|Junos:)[^\n]*`)

func versionLine(output string) string {
	if m := reJunos.FindString(output); m != "" {
		return strings.TrimSpace(m)
	}
	return lastLines(output, 3)
}

// pingReplies: ICMP için yetki gerekmesin diye sistemin ping'i. Windows'ta
// "ulaşılamıyor" yanıtında da çıkış kodu 0 olabildiği için TTL aranır.
func pingReplies(ip string) bool {
	var cmd *exec.Cmd
	if runtime.GOOS == "windows" {
		cmd = exec.Command("ping", "-n", "2", "-w", "1000", ip)
	} else {
		cmd = exec.Command("ping", "-c", "2", "-W", "1", ip)
	}
	output, _ := cmd.CombinedOutput()
	return strings.Contains(strings.ToLower(string(output)), "ttl=")
}
