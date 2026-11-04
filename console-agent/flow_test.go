package main

import (
	"context"
	"io"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeDevice konsoldan EX-2200 gibi davranır: yazılanı yankılar, satır
// gelince durumuna göre cevap verir.
type fakeDevice struct {
	t              *testing.T
	reader         *io.PipeReader
	writer         *io.PipeWriter
	mu             sync.Mutex
	state          string // cli | onay | uboot | geri-sayim | loader | kuruluyor | login | kabuk | conf | yukleme
	line           string
	password       string
	loaded         []string
	loaderSettings map[string]string
	installed      string
	speed          int
	replySpeed     int
	closed         bool
	installFailure bool
	// installerFailure: loader görüntüyü açar ama kurucu paketi indiremez
	// (ör. TFTP blok boyu yüzünden).
	installerFailure bool
	// loadReply: ^D sonrası cihazın cevabı; boşsa "load complete".
	loadReply string
	// setMode: "load set terminal" girişi; patched o girişin satırları,
	// patchReply onun ^D cevabı. missingIf: cihazda olmayan arayüz.
	setMode    bool
	patched    []string
	patchReply string
	missingIf  string
	committed  bool
	uboot      []string // U-Boot'ta yazılanlar
	pingFails  bool
	cliFail    bool // request system software add hata verir
	cliNoAddr  bool // cihazın adresi yok (sıfır yapılandırmalı EX2300)
	// Ağ: cabled kablonun takılı olduğu arayüz ("me0", "irb"); addr commit
	// edilmiş adresler; ifConf arayüzlerin "set" satırları; pending
	// commit bekleyen satırlar; rootSet commit'te root parolası kondu,
	// newPassword o parolanın düz hâli (girişte sorulur).
	cabled      string
	terse       string
	addr        map[string]string
	ifConf      map[string][]string
	route       []string
	pending     []string
	rootSet     bool
	newPassword string
	// askEmpty: parolasız cihaz da "Password:" sorar (EX2300 fabrika ayarı).
	askEmpty   bool
	ztpNoise   bool // show version isteminden sonra ZTP satırı düşer
	ztpDeleted bool // delete chassis auto-image-upgrade
	ztpStopped bool // request system autoinstallation stop
	// ex4200: CLI yankısı sonunda boşluk ("no-more \r\n") ve istemden önce
	// Virtual Chassis satırı ("{master:0}"), sahada görüldüğü gibi.
	ex4200     bool
	configured bool // configure kipine girildi
	// staleLogin: "root"tan önce eski Enter'ların cevabı olan bir "login:"
	// daha düşer, asıl cevap biraz sonra gelir (EX2300'de görüldü).
	staleLogin bool
	out        chan string
}

func newFake(t *testing.T, state string) *fakeDevice {
	r, w := io.Pipe()
	c := &fakeDevice{t: t, reader: r, writer: w, state: state, loaderSettings: map[string]string{}, addr: map[string]string{}, ifConf: map[string][]string{}, speed: 115200, replySpeed: 115200, out: make(chan string, 4096)}
	// Tek yazar: çıktı sırası bozulmasın.
	go func() {
		for s := range c.out {
			if _, err := w.Write([]byte(s)); err != nil {
				return
			}
		}
	}()
	return c
}

func (c *fakeDevice) Read(p []byte) (int, error) { return c.reader.Read(p) }
func (c *fakeDevice) Close() error {
	c.mu.Lock()
	c.closed = true
	c.mu.Unlock()
	return c.writer.Close()
}
func (c *fakeDevice) SetSpeed(h int) error {
	c.mu.Lock()
	c.speed = h
	c.mu.Unlock()
	return nil
}

// emit kilit altında çağrılır.
func (c *fakeDevice) emit(s string) {
	if c.speed != c.replySpeed {
		s = strings.Repeat("\xfe\x80", len(s)/2+1)
	}
	c.out <- s
}

func (c *fakeDevice) after(d time.Duration, f func()) { time.AfterFunc(d, f) }

// powerOn2300 EX2300 gibi açar (bir EX2300 konsolunda görülen sıra):
// U-Boot, FreeBSD/armv6 loader, menü çizilir ve "Autoboot"
// geri sayımı yalnız Ctrl-C ile durur; durmazsa Junos açılır.
func (c *fakeDevice) powerOn2300() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.state = "acilis"
	c.emitLocked("\r\nU-Boot 2016.01 (Jan 03 2024)\r\nDRAM:  2 GiB\r\nstarting USB...\r\nConsoles: U-Boot console\r\n\r\nFreeBSD/armv6 U-Boot loader, Revision 1.2\r\n")
	c.after(300*time.Millisecond, func() {
		c.mu.Lock()
		defer c.mu.Unlock()
		c.state = "ex-autoboot"
		c.emitLocked("\x1b[m\x1b[H\x1b[J/\x08-\x08\\\x08|\x08\x1b[H\x1b[KAutoboot in 3 seconds... (press Ctrl-C to interrupt)")
		c.after(2*time.Second, func() {
			c.mu.Lock()
			defer c.mu.Unlock()
			if c.state == "ex-autoboot" {
				c.state = "acilis"
				c.emitLocked("\r\nBooting from Junos volume ...\r\n")
			}
		})
	})
}

// powerOnNoKernel çekirdeği olmayan EX4550 gibi açar (yarım kalan
// "install --format" sonrası konsolda görülen sıra): loader çekirdeği
// bulamaz, yalnız Enter'la durur; durmazsa watchdog yeniden başlatır.
func (c *fakeDevice) powerOnNoKernel() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.state = "acilis"
	c.emitLocked("\r\nU-Boot 2010.03 (Oct 21 2012 - 03:06:55)\r\nBoard: EX4550-32F 5.21\r\nDRAM:  Initializing\r\n")
	c.after(300*time.Millisecond, func() {
		c.mu.Lock()
		defer c.mu.Unlock()
		c.state = "bootseq"
		c.emitLocked("FreeBSD/PowerPC U-Boot bootstrap loader, Revision 2.4\r\nbootsequencing is enabled\r\nnew boot device = disk0s2:\r\n|/-\\|can't load '/kernel'\r\ncan't load '/kernel.old'\r\nPress Enter to stop auto bootsequencing and to enter loader prompt.\r\n")
		c.after(2*time.Second, func() {
			c.mu.Lock()
			if c.state != "bootseq" {
				c.mu.Unlock()
				return
			}
			c.emitLocked("Watchdog timeout\r\n")
			c.mu.Unlock()
			c.powerOnNoKernel()
		})
	})
}

// powerOn kapalı cihazı açar: U-Boot geri sayımı kimse durdurmadan
// geçer, loader'ın kısa penceresi gelir.
func (c *fakeDevice) powerOn() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.state = "acilis"
	c.emitLocked("\r\nU-Boot 1.1.6 (Mar 1 2012)\r\nDRAM: 512 MB\r\nFlash: 8 MB\r\nNet: eTSEC1\r\nHit any key to stop autoboot:  0 \r\n")
	c.after(400*time.Millisecond, func() {
		c.mu.Lock()
		defer c.mu.Unlock()
		c.emitLocked("FreeBSD/PowerPC U-Boot bootstrap loader, Revision 2.4\r\nLoading /boot/defaults/loader.conf\r\n")
		c.after(300*time.Millisecond, func() {
			c.mu.Lock()
			defer c.mu.Unlock()
			c.state = "geri-sayim-loader"
			c.emitLocked("Hit [Enter] to boot immediately, or space bar for command prompt.\r\nBooting [/kernel] in 1 second... ")
		})
	})
}

func (c *fakeDevice) Write(p []byte) (int, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, b := range p {
		c.handleByte(b)
	}
	return len(p), nil
}

func (c *fakeDevice) handleByte(b byte) {
	switch c.state {
	case "geri-sayim-uboot":
		c.state = "uboot"
		c.emitLocked("\r\n=> ")
		return
	case "geri-sayim-loader":
		c.state = "loader"
		c.emitLocked("\r\nType '?' for a list of commands.\r\nloader> ")
		return
	case "kuruluyor", "acilis", "kapali-cihaz":
		return
	case "bootseq":
		if b == '\r' {
			c.state = "loader"
			c.emitLocked("\r\nType '?' for a list of commands.\r\nloader> ")
		}
		return // boşluk bu bekleyişi durdurmaz
	case "ex-autoboot":
		if b == 0x03 {
			c.state = "ex-menu"
			c.emitLocked("\x1b[H\x1b[JMain Menu\r\n\r\n1. Boot [J]unos volume\r\n2. Boot Junos volume in [S]afe mode\r\n3. [R]eboot\r\n4. [B]oot menu\r\n5. [M]ore options\r\n\r\nChoice: ")
		}
		return // boşluk bu geri sayımı durdurmaz
	case "yukleme":
		if b == 0x04 {
			c.state = "conf"
			if c.setMode && c.patchReply != "" {
				c.setMode = false
				c.emitLocked(c.patchReply)
				return
			}
			c.setMode = false
			if c.loadReply != "" {
				c.emitLocked(c.loadReply)
				return
			}
			c.emitLocked("\r\nload complete\r\n\r\n[edit]\r\nroot# ")
			return
		}
	}
	if b == '\r' || b == '\n' {
		line := strings.TrimSpace(c.line)
		c.line = ""
		if c.ex4200 && c.state == "cli" && line != "" {
			c.emitLocked(" ")
		}
		c.emitLocked("\r\n")
		c.lineReceived(line)
		return
	}
	c.line += string(b)
	c.emitLocked(string(b))
}

func (c *fakeDevice) emitLocked(s string) { c.emit(s) }

func (c *fakeDevice) lineReceived(s string) {
	switch c.state {
	case "ex-menu":
		if s == "5" {
			c.state = "ex-menu2"
			c.emitLocked("\x1b[H\x1b[JMore Options\r\n\r\n1. Recover [J]unos volume\r\n2. Recovery mode - [C]LI\r\n3. Check [F]ile system\r\n4. Enable [V]erbose boot\r\n5. [B]oot prompt\r\n6. [M]ain menu\r\n\r\nChoice: ")
			return
		}
		c.emitLocked("Choice: ")
	case "ex-menu2":
		if s == "5" {
			c.state = "loader"
			c.emitLocked("\r\nType '?' for a list of commands, 'help' for more detailed help.\r\nloader> ")
			return
		}
		c.emitLocked("Choice: ")
	case "cli":
		switch s {
		case "":
			c.emitLocked("root@sw1> ")
		case "request system reboot":
			c.state = "onay"
			c.emitLocked("Reboot the system ? [yes,no] (no) ")
		case "show version | no-more":
			c.emitLocked("Hostname: sw1\r\nModel: ex2200-24t-4g\r\nJUNOS Base OS boot [12.3R12.4]\r\nroot> ")
			if c.ztpNoise {
				c.ztpNoise = false
				c.emitLocked("\r\nAuto Image Upgrade: DHCP Client Bound interfaces: vme.0\r\n")
			}
		case "show interfaces terse " + c.missingIf + " | no-more":
			c.emitLocked("error: device " + c.missingIf + " not found\r\n\r\nroot@sw1> ")
		case "request system software add http://198.51.100.250:8069/jinstall-host-qfx-5-17.2R1.13-signed.tgz reboot":
			c.installed = "cli"
			if c.cliFail {
				c.emitLocked("Fetching package...\r\nerror: could not fetch package\r\nroot@sw1> ")
				return
			}
			if c.cliNoAddr && !c.reachable() {
				c.emitLocked("fetch-secure: http://198.51.100.250:*: Can't assign requested address\r\nERROR: Cannot fetch file: jinstall-host-qfx-5-17.2R1.13-signed.tgz\r\n\r\n{master:0}\r\nroot> ")
				return
			}
			c.state = "kuruluyor"
			c.emitLocked("Installing package ...\r\nRebooting ...\r\nShutdown NOW!\r\n")
			c.after(300*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.state = "login"
				c.emitLocked("\r\nqfx5100 (ttyd0)\r\n\r\nlogin: ")
			})
		case "show configuration | no-more":
			if c.ex4200 {
				c.emitLocked("## Last commit: 2016-01-20 07:07:07 UTC by root\r\nversion 12.3R12.4;\r\npoe {\r\n    interface all;\r\n}\r\n\r\n{master:0}\r\nroot> ")
				return
			}
			c.emitLocked("## Last commit: 2026-11-02 10:00:00 UTC by root\r\nsystem {\r\n    host-name sw1;\r\n}\r\n\r\nroot@sw1> ")
		case "ping 198.51.100.250 count 5 rapid":
			if c.reachable() {
				c.emitLocked("PING 198.51.100.250: 56 data bytes\r\n!!!!!\r\n5 packets transmitted, 5 packets received, 0% packet loss\r\n\r\nroot> ")
				return
			}
			c.emitLocked("ping: sendto: Can't assign requested address\r\n5 packets transmitted, 0 packets received, 100% packet loss\r\n\r\nroot> ")
		case "show interfaces terse | no-more":
			c.emitLocked(c.terse + "\r\n\r\nroot> ")
		case "show configuration routing-options static | display set | no-more":
			c.emitLocked(strings.Join(c.route, "\r\n") + "\r\n\r\nroot> ")
		case "show configuration system root-authentication | count":
			n := "0"
			if c.password != "" || c.rootSet {
				n = "1"
			}
			c.emitLocked("Count: " + n + " lines\r\n\r\nroot@sw1> ")
		case "request system autoinstallation stop":
			c.ztpStopped = true
			if c.ex4200 {
				c.emitLocked("warning: autoinstallation subsystem not running - not needed by configuration.\r\n\r\n{master:0}\r\nroot> ")
				return
			}
			c.emitLocked("root> ")
		case "show configuration interfaces me0 | display set | no-more", "show configuration interfaces irb | display set | no-more":
			n := strings.Fields(s)[3]
			c.emitLocked(strings.Join(c.ifConf[n], "\r\n") + "\r\n\r\nroot> ")
		case "configure":
			c.configured = true
			c.state = "conf"
			c.emitLocked("Entering configuration mode\r\n\r\n[edit]\r\nroot# ")
		case "exit":
			c.state = "login"
			c.emitLocked("\r\nAmnesiac (ttyu0)\r\n\r\nlogin: ")
		default:
			c.emitLocked("unknown command.\r\nroot> ")
		}
	case "onay":
		if s == "yes" {
			c.state = "acilis"
			c.emitLocked("Shutdown NOW!\r\n*** FINAL System shutdown message ***\r\n")
			c.after(200*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.state = "geri-sayim-uboot"
				c.emitLocked("\r\nU-Boot 1.1.6 (Mar 1 2012)\r\nDRAM: 512 MB\r\nHit any key to stop autoboot:  3 ")
			})
		}
	case "uboot":
		if strings.HasPrefix(s, "setenv ") || s == "saveenv" {
			c.uboot = append(c.uboot, s)
			c.emitLocked("=> ")
			return
		}
		if strings.HasPrefix(s, "ping ") {
			c.uboot = append(c.uboot, s)
			if c.pingFails {
				c.emitLocked("ping failed; host " + strings.TrimPrefix(s, "ping ") + " is not alive\r\n=> ")
			} else {
				c.emitLocked("Using eTSEC0 device\r\nhost " + strings.TrimPrefix(s, "ping ") + " is alive\r\n=> ")
			}
			return
		}
		if s == "boot" {
			c.state = "acilis"
			c.emitLocked("FreeBSD/PowerPC U-Boot bootstrap loader, Revision 2.4\r\nLoading /boot/defaults/loader.conf\r\n")
			c.after(200*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.state = "geri-sayim-loader"
				c.emitLocked("Hit [Enter] to boot immediately, or space bar for command prompt.\r\nBooting [/kernel] in 1 second... ")
			})
		} else {
			c.emitLocked("Unknown command '" + s + "'\r\n=> ")
		}
	case "loader":
		switch {
		case s == "":
			c.emitLocked("loader> ")
		case strings.HasPrefix(s, "set "):
			kv := strings.SplitN(strings.TrimPrefix(s, "set "), "=", 2)
			c.loaderSettings[kv[0]] = kv[1]
			c.emitLocked("loader> ")
		case strings.HasPrefix(s, "install --format tftp://"):
			c.installed = strings.TrimPrefix(s, "install --format ")
			if c.installFailure {
				c.emitLocked("can't load file '" + c.installed + "': No such file or directory\r\nloader> ")
				return
			}
			if c.installerFailure {
				c.installerFailure = false // yeniden açılışta düzelmiş say
				c.state = "kurucu"
				c.emitLocked("/kernel text=0x1b4\r\nDownloading /j.tgz from 192.0.2.5 ...\r\n  512B  00:01\r\ntar: Error opening archive: truncated gzip input\r\nERROR: cannot unpack installation package\r\n*** The installer exited with status 3 ***\r\n*** The installation is unsuccessful!!! ***\r\nA shell has been started. Type exit<cr> to reboot:\r\n# ")
				return
			}
			c.state = "kuruluyor"
			c.emitLocked("Formatting...\r\nInstalling...\r\n")
			c.after(300*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.state = "login"
				c.emitLocked("Copyright (c) 1996-2017, Juniper Networks\r\nAmnesiac (ttyu0)\r\n\r\nlogin: ")
			})
		default:
			c.emitLocked("not found\r\nloader> ")
		}
	case "login":
		if s == "root" && c.staleLogin {
			c.staleLogin = false
			c.emitLocked("\r\nFreeBSD/arm (Amnesiac) (ttyu0)\r\n\r\nlogin: ")
			c.after(300*time.Millisecond, func() {
				c.mu.Lock()
				defer c.mu.Unlock()
				c.lineReceived("root")
			})
			return
		}
		if s == "root" {
			if c.password != "" || c.askEmpty {
				c.state = "parola"
				c.emitLocked("Password:")
				return
			}
			c.state = "kabuk"
			c.emitLocked("--- JUNOS 12.3R12.4 built\r\nroot@:RE:0% ")
		} else if s == "" {
			c.emitLocked("login: ")
		}
	case "parola":
		if s == c.password {
			c.state = "cli"
			c.emitLocked("root@sw1> ")
		} else {
			c.state = "login"
			c.emitLocked("Login incorrect\r\nlogin: ")
		}
	case "kabuk":
		switch s {
		case "cli":
			c.state = "cli"
			c.emitLocked("root> ")
		case "exit":
			c.state = "login"
			c.emitLocked("login: ")
		default:
			c.emitLocked("root@:RE:0% ")
		}
	case "conf":
		switch s {
		case "load override terminal":
			c.state = "yukleme"
			c.emitLocked("[Type ^D at a new line to end input]\r\n")
		case "load set terminal":
			c.state = "yukleme"
			c.setMode = true
			c.emitLocked("[Type ^D at a new line to end input]\r\n")
		case "delete chassis auto-image-upgrade":
			c.ztpDeleted = true
			c.emitLocked("\r\n[edit]\r\nroot# ")
		case "exit configuration-mode":
			c.state = "cli"
			c.emitLocked("Exiting configuration mode\r\n\r\nroot> ")
		case "rollback 0":
			c.ztpDeleted = false
			c.pending = nil
			c.emitLocked("load complete\r\n\r\n[edit]\r\nroot# ")
		case "commit and-quit":
			// Fabrika ayarı (root parolası yok, yapılandırma yüklenmedi):
			// Junos hiçbir commit'i kabul etmez.
			if c.password == "" && len(c.loaded) == 0 && !c.rootSet && !c.pendingRoot() {
				c.emitLocked("[edit]\r\n  'system'\r\n    Missing mandatory statement: 'root-authentication'\r\nerror: commit failed: (missing statements)\r\n\r\n[edit]\r\nroot# ")
				return
			}
			c.committed = true
			c.applyPending()
			c.state = "cli"
			c.emitLocked("commit complete\r\nExiting configuration mode\r\n\r\nroot@sw1> ")
		default:
			if strings.HasPrefix(s, "set ") || strings.HasPrefix(s, "delete ") {
				c.pending = append(c.pending, s)
			}
			c.emitLocked("[edit]\r\nroot# ")
		}
	case "yukleme":
		if c.setMode {
			c.patched = append(c.patched, s)
			return
		}
		c.loaded = append(c.loaded, s)
	case "kurucu":
		if s == "exit" {
			c.state = "acilis"
			c.after(100*time.Millisecond, c.powerOn2300)
			return
		}
		if s == "" {
			c.emitLocked("# ")
			return
		}
		c.emitLocked("sh: " + s + ": not found\r\n# ")
	}
}

// reachable: cihaz kurulum sunucusuna ulaşıyor mu (kablolu arayüzde adres var).
func (c *fakeDevice) reachable() bool { return c.cabled == "" || c.addr[c.cabled] != "" }

func (c *fakeDevice) pendingRoot() bool {
	for _, l := range c.pending {
		if strings.HasPrefix(l, "set system root-authentication ") {
			return true
		}
	}
	return false
}

// applyPending commit: bekleyen set/delete satırları cihazın durumuna işlenir.
func (c *fakeDevice) applyPending() {
	for _, l := range c.pending {
		f := strings.Fields(l)
		switch {
		case strings.HasPrefix(l, "set system root-authentication "):
			c.rootSet = true
			c.password = c.newPassword
		case strings.HasPrefix(l, "delete interfaces ") && strings.HasSuffix(l, " unit 0 family inet"):
			delete(c.addr, f[2])
			c.ifConf[f[2]] = nil
		case strings.HasPrefix(l, "set interfaces ") && strings.Contains(l, " family inet address "):
			c.addr[f[2]] = f[len(f)-1]
			c.ifConf[f[2]] = append(c.ifConf[f[2]], l)
		case strings.HasPrefix(l, "set interfaces "):
			c.ifConf[f[2]] = append(c.ifConf[f[2]], l)
		case l == "delete routing-options static route 0.0.0.0/0":
			c.route = nil
		case strings.HasPrefix(l, "set routing-options static route 0.0.0.0/0 "):
			c.route = append(c.route, l)
		}
	}
	c.pending = nil
}

func newTestFlow(t *testing.T, c *fakeDevice, job Job) (*Flow, *[]string) {
	k := newConsole(c)
	var mu sync.Mutex
	events := []string{}
	a := &Flow{
		k:   k,
		job: job,
		notify: func(stage, message string) {
			mu.Lock()
			events = append(events, stage+": "+message)
			mu.Unlock()
		},
		configuration:  func(context.Context) (string, error) { return "system {\n    host-name sw1;\n}", nil },
		addressInUse:   func(string) bool { return false },
		bootTimeout:    10 * time.Second,
		installTimeout: 10 * time.Second,
		manualTimeout:  5 * time.Second,
	}
	return a, &events
}

var sampleJob = Job{
	UUID:            "u1",
	DeviceIP:        "198.51.100.120",
	LoaderCommands:  []string{"set ipaddr=198.51.100.120", "set netmask=255.255.255.0", "set gatewayip=198.51.100.1", "set serverip=198.51.100.250"},
	InstallCommand:  "install --format tftp://198.51.100.250/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz",
	ExpectedVersion: "12.3R12.4",
	Configuration:   true,
	LoadCommand:     "load override terminal",
}

func TestInstallFromRunningDevice(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	job := sampleJob
	job.Password = "gizli"
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v\nkonsol: %q", err, *events, a.k.Text(0))
	}
	if c.loaderSettings["ipaddr"] != "198.51.100.120" || c.loaderSettings["serverip"] != "198.51.100.250" {
		t.Errorf("loader ayarları: %v", c.loaderSettings)
	}
	if c.installed != "tftp://198.51.100.250/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz" {
		t.Errorf("kurulan: %q", c.installed)
	}
	if strings.Join(c.loaded, "\n") != "system {\nhost-name sw1;\n}" {
		t.Errorf("yüklenen: %q", c.loaded)
	}
	if c.state != "login" {
		t.Errorf("oturum açık kaldı: %s", c.state)
	}
}

// Eski bir "login:" istemi parola yanlış sayılmaz: parolasız cihaza girilir.
func TestStaleLoginPromptIsNotWrongPassword(t *testing.T) {
	c := newFake(t, "login")
	c.staleLogin = true
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true}
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()
	if err := a.reachCli(ctx); err != nil {
		t.Fatalf("CLI'ye girilemedi: %v\nolaylar: %v\nkonsol: %q", err, *events, a.k.Text(0))
	}
	if c.state != "cli" {
		t.Fatalf("durum: %s", c.state)
	}
}

func TestDeviceAtLoader(t *testing.T) {
	c := newFake(t, "loader")
	job := sampleJob
	job.Configuration = false
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()
	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
}

func TestLoaderRejectsInstall(t *testing.T) {
	c := newFake(t, "loader")
	c.installFailure = true
	a, _ := newTestFlow(t, c, sampleJob)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()
	err := a.Install(ctx)
	if err == nil || !strings.Contains(err.Error(), "No such file") {
		t.Fatalf("hata beklenirdi, gelen: %v", err)
	}
}

func TestRefusesWhenAddressInUse(t *testing.T) {
	c := newFake(t, "loader")
	a, _ := newTestFlow(t, c, sampleJob)
	a.addressInUse = func(string) bool { return true }
	err := a.Install(context.Background())
	if err == nil || !strings.Contains(err.Error(), "kullanılıyor") {
		t.Fatalf("hata beklenirdi, gelen: %v", err)
	}
	if c.installed != "" {
		t.Fatal("adres kullanımdayken kurulum başladı")
	}
}

func TestWrongPasswordWaitsForManualBoot(t *testing.T) {
	c := newFake(t, "login")
	c.password = "dogru"
	job := sampleJob
	job.Password = "yanlis"
	a, events := newTestFlow(t, c, job)
	a.manualTimeout = 1 * time.Second
	err := a.Install(context.Background())
	if err == nil {
		t.Fatal("hata beklenirdi")
	}
	seen := false
	for _, o := range *events {
		if strings.HasPrefix(o, "uyari: ") && strings.Contains(o, "elektriğini") {
			seen = true
		}
	}
	if !seen {
		t.Fatalf("operatöre uyarı yok: %v", *events)
	}
}

func TestSpeedDetected(t *testing.T) {
	c := newFake(t, "cli")
	c.replySpeed = 9600
	k := newConsole(c)
	speed, reply, err := detectSpeed(context.Background(), k)
	if err != nil || !reply || speed != 9600 {
		t.Fatalf("hız %d cevap %v hata %v", speed, reply, err)
	}
}

// Cihaz iş açıldıktan sonra açılıyor ve 9600'de konuşuyor; ajan 115200'de
// bekliyordu. Bozuk karakterleri görüp hızı değiştirmeli ve loader'ı
// yakalamalı.
func TestPoweredOffDeviceBootsAtOtherSpeed(t *testing.T) {
	c := newFake(t, "kapali-cihaz")
	c.replySpeed = 9600
	job := sampleJob
	job.Configuration = false
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if _, reply, err := detectSpeed(ctx, a.k); err != nil || reply {
		t.Fatalf("kapalı cihaz cevap vermemeli: %v %v", reply, err)
	}
	if a.k.speed != 115200 {
		t.Fatalf("varsayılan hız 115200 olmalı: %d", a.k.speed)
	}
	// Operatörün yapacağı gibi: "elektriği verin" uyarısı görülünce.
	a.manualTimeout = 20 * time.Second
	var once sync.Once
	orig := a.notify
	a.notify = func(stage, message string) {
		orig(stage, message)
		if strings.Contains(message, "elektrik") {
			once.Do(func() { go c.powerOn() })
		}
	}

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if a.k.speed != 9600 {
		t.Errorf("hız: %d", a.k.speed)
	}
	if c.installed == "" {
		t.Errorf("kurulmadı; olaylar: %v", *events)
	}
}

// EX2300: boşluk işe yaramaz, Ctrl-C ve menüden loader'a inilir.
func TestEx2300MenuToLoader(t *testing.T) {
	c := newFake(t, "kapali-cihaz")
	c.replySpeed = 9600
	job := sampleJob
	job.Configuration = false
	a, events := newTestFlow(t, c, job)
	a.manualTimeout = 20 * time.Second
	var once sync.Once
	orig := a.notify
	a.notify = func(stage, message string) {
		orig(stage, message)
		if strings.Contains(message, "elektrik") {
			once.Do(func() { go c.powerOn2300() })
		}
	}
	ctx, stop := context.WithTimeout(context.Background(), 40*time.Second)
	defer stop()
	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v\nkonsol: %q", err, *events, a.k.Text(0))
	}
	if c.installed == "" {
		t.Errorf("kurulmadı; olaylar: %v", *events)
	}
}

// EX4550, diski boşalmış: loader yalnız Enter'la durur.
func TestNoKernelEnterToLoader(t *testing.T) {
	c := newFake(t, "kapali-cihaz")
	c.replySpeed = 9600
	job := sampleJob
	job.Configuration = false
	a, events := newTestFlow(t, c, job)
	a.manualTimeout = 20 * time.Second
	var once sync.Once
	orig := a.notify
	a.notify = func(stage, message string) {
		orig(stage, message)
		if strings.Contains(message, "elektrik") {
			once.Do(func() { go c.powerOnNoKernel() })
		}
	}
	ctx, stop := context.WithTimeout(context.Background(), 40*time.Second)
	defer stop()
	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v\nkonsol: %q", err, *events, a.k.Text(0))
	}
	if c.installed == "" {
		t.Errorf("kurulmadı; olaylar: %v", *events)
	}
}

func TestMenuChoice(t *testing.T) {
	parent := "Main Menu\n\n1. Boot [J]unos volume\n2. Boot Junos volume in [S]afe mode\n3. [R]eboot\n4. [B]oot menu\n5. [M]ore options\n\nChoice: "
	more := "More Options\n\n1. Recover [J]unos volume\n4. Enable [V]erbose boot\n5. [B]oot prompt\n6. [M]ain menu\n\nChoice: "
	if s := menuChoice(parent); s != "5" {
		t.Errorf("ana menü: %q", s)
	}
	if s := menuChoice(more); s != "5" {
		t.Errorf("seçenekler: %q", s)
	}
	if s := menuChoice("1. Boot [J]unos volume\nChoice: "); s != "" {
		t.Errorf("bilinmeyen menü: %q", s)
	}
}

// Kurucu düşerse 4 saat beklenmez; sonraki işte cihaz kurulum kabuğunda
// bulunur, "exit" ile yeniden başlatılıp kurulur.
func TestInstallerFailureAndShellRecovery(t *testing.T) {
	c := newFake(t, "loader")
	c.installerFailure = true
	job := sampleJob
	job.Configuration = false
	a, _ := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 40*time.Second)
	defer stop()
	err := a.Install(ctx)
	if err == nil || !strings.Contains(err.Error(), "installation is unsuccessful") {
		t.Fatalf("kurucu hatası beklenirdi, gelen: %v", err)
	}

	// Aynı konsol (ikinci okuyucu çıktıyı bölüşürdü), yeni olay listesi.
	var mu sync.Mutex
	events := &[]string{}
	a.notify = func(stage, message string) {
		mu.Lock()
		*events = append(*events, stage+": "+message)
		mu.Unlock()
	}
	c.installed = ""
	if err := a.Install(ctx); err != nil {
		t.Fatalf("kabuktan dönüş: %v\nolaylar: %v\nkonsol: %q", err, *events, a.k.Text(0))
	}
	if c.installed == "" || !strings.Contains(strings.Join(*events, "\n"), "kurulum kabuğu") {
		t.Errorf("kurulmadı ya da kabuk tanınmadı; olaylar: %v", *events)
	}
}

// Yalnız yedek: kurulu cihaza girilir, loader'a inilmez, yedek yüklenir.
func TestLoadOnlyFromLogin(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	job := Job{Password: "gizli", Configuration: true, LoadCommand: "load override terminal", LoadOnly: true}
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.LoadOnly(ctx); err != nil {
		t.Fatalf("yükleme: %v\nolaylar: %v\nkonsol: %q", err, *events, a.k.Text(0))
	}
	if c.installed != "" || len(c.loaderSettings) != 0 {
		t.Errorf("kurulum yapılmamalıydı: %q %v", c.installed, c.loaderSettings)
	}
	if strings.Join(c.loaded, "\n") != "system {\nhost-name sw1;\n}" {
		t.Errorf("yüklenen: %q", c.loaded)
	}
	if c.state != "login" {
		t.Errorf("oturum açık kaldı: %s", c.state)
	}
}

// Biri konsolda CLI'de bırakmışsa parola gerekmez.
func TestLoadOnlyFromCli(t *testing.T) {
	c := newFake(t, "cli")
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true}
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.LoadOnly(ctx); err != nil {
		t.Fatalf("yükleme: %v\nolaylar: %v", err, *events)
	}
	if len(c.loaded) != 3 {
		t.Errorf("yüklenen: %q", c.loaded)
	}
}

func TestLoadOnlyWrongPassword(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	job := Job{Password: "yanlis", Configuration: true, LoadCommand: "load override terminal", LoadOnly: true}
	a, _ := newTestFlow(t, c, job)
	err := a.LoadOnly(context.Background())
	if err == nil || !strings.Contains(err.Error(), "root parolası") {
		t.Fatalf("parola hatası beklendi: %v", err)
	}
	if c.state == "acilis" || c.installed != "" {
		t.Errorf("cihaz yeniden başlatılmamalıydı: %s", c.state)
	}
}

// Yedekte "failed" geçen bir satır cihaz tarafından yankılanır; hata
// sayılıp yükleme geri alınmamalı.
func TestLoadIgnoresEchoedFailedWord(t *testing.T) {
	c := newFake(t, "cli")
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true}
	a, events := newTestFlow(t, c, job)
	a.configuration = func(context.Context) (string, error) {
		return "interfaces {\n    ge-0/0/1 {\n        description \"failed over\";\n    }\n}", nil
	}
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.LoadOnly(ctx); err != nil {
		t.Fatalf("yükleme geri alınmamalıydı: %v\nolaylar: %v", err, *events)
	}
}

// Junos'un kendi hata iletisi ise yüklemeyi geri aldırır.
func TestLoadSyntaxErrorRollsBack(t *testing.T) {
	c := newFake(t, "cli")
	c.loadReply = "\r\nterminal:2:(5) syntax error: hostname\r\nload complete (1 errors)\r\n\r\n[edit]\r\nroot# "
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true}
	a, _ := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	err := a.LoadOnly(ctx)
	if err == nil || !strings.Contains(err.Error(), "geri alındı") || !strings.Contains(err.Error(), "syntax error") {
		t.Fatalf("geri alma beklendi: %v", err)
	}
}

// Ajan kurulum sürerken yeniden başladı: cihaz loader'a İNDİRİLMEZ, kurulum
// komutu yeniden gönderilmez; kurulum bitince giriş ve yapılandırma yapılır.
func TestResumeWaitsForRunningInstall(t *testing.T) {
	c := newFake(t, "kuruluyor")
	c.after(300*time.Millisecond, func() {
		c.mu.Lock()
		defer c.mu.Unlock()
		c.state = "login"
		c.emitLocked("Copyright (c) 1996-2017, Juniper Networks\r\nAmnesiac (ttyu0)\r\n\r\nlogin: ")
	})
	job := sampleJob
	job.ResumeStage = "kurulum"
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.Resume(ctx); err != nil {
		t.Fatalf("devam: %v\nolaylar: %v", err, *events)
	}
	if c.installed != "" || len(c.loaderSettings) != 0 {
		t.Errorf("kurulum yeniden başlatılmamalıydı: kurulan=%q loader=%v", c.installed, c.loaderSettings)
	}
	if len(c.loaded) == 0 {
		t.Error("yapılandırma yüklenmeliydi")
	}
}

// Kurulum ajan kapalıyken bitmiş: cihaz giriş isteminde sessiz bekliyor.
func TestResumeAfterInstallFinished(t *testing.T) {
	c := newFake(t, "login")
	job := sampleJob
	job.ResumeStage = "kurulum"
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.Resume(ctx); err != nil {
		t.Fatalf("devam: %v\nolaylar: %v", err, *events)
	}
	if c.installed != "" {
		t.Errorf("kurulum yeniden başlatılmamalıydı: %q", c.installed)
	}
}

// Yapılandırma aşamasında kalan iş: yalnız doğrulama ve yükleme yapılır.
func TestResumeFromConfiguration(t *testing.T) {
	c := newFake(t, "cli")
	job := sampleJob
	job.ResumeStage = "yapilandirma"
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.Resume(ctx); err != nil {
		t.Fatalf("devam: %v\nolaylar: %v", err, *events)
	}
	if c.installed != "" || len(c.loaded) == 0 {
		t.Errorf("yalnız yükleme beklendi: kurulan=%q yüklenen=%q", c.installed, c.loaded)
	}
}

// Özelleştirme yedekle aynı adaya "load set terminal" ile eklenir, sonra tek commit.
func TestLoadAppliesPatchBeforeCommit(t *testing.T) {
	c := newFake(t, "cli")
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true, Patch: true}
	a, events := newTestFlow(t, c, job)
	a.patch = func(context.Context) (string, error) {
		return "set system host-name piga\ndelete system archival\n", nil
	}
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.LoadOnly(ctx); err != nil {
		t.Fatalf("yükleme: %v\nolaylar: %v", err, *events)
	}
	if strings.Join(c.patched, "\n") != "set system host-name piga\ndelete system archival" || !c.committed {
		t.Errorf("yama: %q commit=%v", c.patched, c.committed)
	}
}

// Yamada hata varsa yedek de commit edilmez: ikisi birden geri alınır.
func TestPatchErrorRollsBack(t *testing.T) {
	c := newFake(t, "cli")
	c.patchReply = "\r\nterminal:1:(5) syntax error: hostname\r\nload complete (1 errors)\r\n\r\n[edit]\r\nroot# "
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true, Patch: true}
	a, _ := newTestFlow(t, c, job)
	a.patch = func(context.Context) (string, error) { return "set system hostname piga", nil }
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	err := a.LoadOnly(ctx)
	if err == nil || !strings.Contains(err.Error(), "özelleştirme hatalı") || c.committed {
		t.Fatalf("geri alma beklendi: %v commit=%v", err, c.committed)
	}
}

// Cihazda olmayan uplink arayüzü commit sonrası uyarı verir.
func TestMissingUplinkWarns(t *testing.T) {
	c := newFake(t, "cli")
	c.missingIf = "xe-0/1/0"
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true, Patch: true, UplinkInterface: "xe-0/1/0"}
	a, events := newTestFlow(t, c, job)
	a.patch = func(context.Context) (string, error) {
		return "set interfaces xe-0/1/0 unit 0 family inet address 10.0.0.230/30", nil
	}
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.LoadOnly(ctx); err != nil {
		t.Fatalf("yükleme: %v", err)
	}
	if !strings.Contains(strings.Join(*events, "\n"), "uyari: Uplink arayüzü xe-0/1/0 cihazda yok") {
		t.Errorf("uyarı beklendi: %v", *events)
	}
}

// EX2300/EX3400: ağ ayarı U-Boot'ta yazılır, sonra "boot"; kurulum sürer.
func TestUbootNetworkSetupBeforeBoot(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	job := sampleJob
	job.Password = "gizli"
	job.UbootCommands = []string{"setenv ipaddr 198.51.100.120", "setenv netmask 255.255.255.0", "setenv serverip 198.51.100.250", "saveenv", "ping 198.51.100.250"}
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if strings.Join(c.uboot, "|") != strings.Join(job.UbootCommands, "|") {
		t.Errorf("U-Boot'a yazılan: %q", c.uboot)
	}
	if c.installed == "" {
		t.Error("kurulum yapılmalıydı")
	}
}

// U-Boot'tan sunucuya ping gitmezse saatlik kurulum başlatılmaz.
func TestUbootPingFailureStops(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	c.pingFails = true
	job := sampleJob
	job.Password = "gizli"
	job.UbootCommands = []string{"setenv serverip 198.51.100.250", "ping 198.51.100.250"}
	a, _ := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	err := a.Install(ctx)
	if err == nil || !strings.Contains(err.Error(), "ulaşılamıyor") || c.installed != "" {
		t.Fatalf("ping hatası beklendi: %v kurulan=%q", err, c.installed)
	}
}

var cliJob = Job{
	CliInstall:      true,
	InstallCommand:  "request system software add http://198.51.100.250:8069/jinstall-host-qfx-5-17.2R1.13-signed.tgz reboot",
	ExpectedVersion: "12.3R12.4",
	Configuration:   true,
	LoadCommand:     "load override terminal",
}

// QFX5100/EX4600: loader yok; Junos CLI'den kurulur, cihaz yeniden açılınca
// girilir, sürüm doğrulanır, yapılandırma yüklenir.
func TestCliInstall(t *testing.T) {
	c := newFake(t, "cli")
	a, events := newTestFlow(t, c, cliJob)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.CliInstall(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if c.installed != "cli" || len(c.loaderSettings) != 0 || len(c.loaded) == 0 {
		t.Errorf("CLI kurulumu: kurulan=%q loader=%v yüklenen=%q", c.installed, c.loaderSettings, c.loaded)
	}
}

func TestCliInstallFailureStops(t *testing.T) {
	c := newFake(t, "cli")
	c.cliFail = true
	a, _ := newTestFlow(t, c, cliJob)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	err := a.CliInstall(ctx)
	if err == nil || !strings.Contains(err.Error(), "could not fetch") {
		t.Fatalf("hata beklendi: %v", err)
	}
}

// Adres verilemeyen (işte DHCP) cihazda ham "Can't assign" yerine ne
// yapılacağı söylenir.
func TestCliInstallWithoutAddressExplains(t *testing.T) {
	c := newFake(t, "cli")
	c.cliNoAddr = true
	c.cabled = "me0"
	a, _ := newTestFlow(t, c, cliJob)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	err := a.CliInstall(ctx)
	if err == nil || !strings.Contains(err.Error(), "sabit adres ve ağ geçidi verin") {
		t.Fatalf("açıklayıcı hata beklendi: %v", err)
	}
}

// Sıfır yapılandırmalı cihaz: sunucuya ulaşmıyor; ajan root parolasını koyar,
// önce yönetim portunu dener (kablo orada değil, geri alınır), ön portların
// irb.0'ında ulaşır, kurar, sonra geçici adresi kaldırıp eski ayarı geri yazar.
func TestCliInstallGivesTemporaryAddress(t *testing.T) {
	c := newFake(t, "login")
	c.askEmpty = true
	c.cliNoAddr = true
	c.cabled = "irb"
	c.newPassword = "yeni"
	c.terse = "Interface  Admin Link Proto\r\nge-0/0/0  up  up\r\nme0  up  down\r\nme0.0  up  down  inet\r\nirb.0  up  up  inet"
	c.ifConf["irb"] = []string{"set interfaces irb unit 0 family inet dhcp vendor-id Juniper-ex2300-24t"}
	job := cliJob
	job.Configuration = false
	job.Password = "yeni"
	job.RootHash = "$1$abcdefgh$0123456789abcdefghijkl"
	job.CliAddress = "198.51.100.120/24"
	job.CliGateway = "198.51.100.1"
	job.ImageServer = "198.51.100.250"
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 60*time.Second)
	defer stop()

	if err := a.CliInstall(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v\nkonsol: %q", err, *events, a.k.Text(0))
	}
	if c.installed != "cli" || !c.rootSet {
		t.Fatalf("kurulan=%q root=%v", c.installed, c.rootSet)
	}
	if len(c.addr) != 0 || len(c.route) != 0 {
		t.Errorf("geçici adres kaldı: %v %v", c.addr, c.route)
	}
	if strings.Join(c.ifConf["irb"], "|") != "set interfaces irb unit 0 family inet dhcp vendor-id Juniper-ex2300-24t" {
		t.Errorf("irb eski ayarına dönmedi: %q", c.ifConf["irb"])
	}
	joined := strings.Join(*events, "\n")
	for _, w := range []string{"me0 üzerinden kurulum sunucusuna ulaşılamadı", "geçici adres verildi: 198.51.100.120/24 (irb)", "root parolası kondu", "Geçici adres kaldırıldı"} {
		if !strings.Contains(joined, w) {
			t.Errorf("olay yok: %q\n%s", w, joined)
		}
	}
}

// Parolasız cihaza parola konamazsa (işte parola yok) açıkça söylenir.
func TestCliAddressNeedsRootPassword(t *testing.T) {
	c := newFake(t, "cli")
	c.cliNoAddr = true
	c.cabled = "me0"
	c.terse = "me0.0  up  up  inet"
	job := cliJob
	job.CliAddress = "198.51.100.120/24"
	job.ImageServer = "198.51.100.250"
	a, _ := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	err := a.CliInstall(ctx)
	if err == nil || !strings.Contains(err.Error(), "root parolası") || c.installed != "" {
		t.Fatalf("root parolası hatası beklendi: %v (kurulan %q)", err, c.installed)
	}
}

// Sunucuya zaten ulaşan cihazın ayarına dokunulmaz.
func TestCliInstallReachableKeepsConfig(t *testing.T) {
	c := newFake(t, "cli")
	job := cliJob
	job.CliAddress = "198.51.100.120/24"
	job.ImageServer = "198.51.100.250"
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 30*time.Second)
	defer stop()

	if err := a.CliInstall(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if len(c.addr) != 0 || !strings.Contains(strings.Join(*events, "\n"), "adres verilmedi") {
		t.Errorf("adres verilmemeliydi: %v %v", c.addr, *events)
	}
}

// İstemden sonra konsola ZTP satırı düşse de komut tamamlanır.
func TestZtpNoiseAfterPrompt(t *testing.T) {
	c := newFake(t, "cli")
	c.ztpNoise = true
	job := Job{Configuration: true, LoadCommand: "load override terminal", LoadOnly: true}
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 60*time.Second)
	defer stop()

	if err := a.LoadOnly(ctx); err != nil {
		t.Fatalf("ZTP gürültüsünde yükleme: %v\nolaylar: %v", err, *events)
	}
}

// Fabrika ayarında bırakılan cihaz: root parolası yok, commit reddedilir;
// aday geri alınır, ZTP bu açılış için durdurulur, kurulum başarılı biter.
func TestFactoryInstallStopsZtpWithoutCommit(t *testing.T) {
	c := newFake(t, "loader")
	job := sampleJob
	job.Configuration = false
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 60*time.Second)
	defer stop()

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if c.committed || c.ztpDeleted || !c.ztpStopped || c.configured {
		t.Fatalf("commit=%v silindi=%v durduruldu=%v configure=%v\nolaylar: %v\nkonsol: %q", c.committed, c.ztpDeleted, c.ztpStopped, c.configured, *events, a.k.Text(0))
	}
	/* Parolasız yapılandırmada commit denenmez: konsolda kırmızı hata kalmaz. */
	if strings.Contains(a.k.Text(0), "commit failed") {
		t.Errorf("commit denendi: %q", a.k.Text(0))
	}
	if !strings.Contains(strings.Join(*events, "\n"), "ZTP bu açılış için durduruldu") {
		t.Errorf("olaylar: %v", *events)
	}
}

// Parolalı cihaz: ZTP kalıcı kapatılır (delete + commit).
func TestInstallWithPasswordDeletesZtp(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	job := sampleJob
	job.Password = "gizli"
	job.Configuration = false
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 60*time.Second)
	defer stop()

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if !c.committed || !c.ztpDeleted || c.ztpStopped {
		t.Fatalf("commit=%v silindi=%v durduruldu=%v\nolaylar: %v\nkonsol: %q", c.committed, c.ztpDeleted, c.ztpStopped, *events, a.k.Text(0))
	}
	if !strings.Contains(strings.Join(*events, "\n"), "ZTP (otomatik kurulum) kapatıldı") {
		t.Errorf("olaylar: %v", *events)
	}
}

// Yedek yüklenirken ZTP aynı commit'te silinir.
func TestLoadedBackupDropsZtp(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	job := sampleJob
	job.Password = "gizli"
	a, events := newTestFlow(t, c, job)
	ctx, stop := context.WithTimeout(context.Background(), 60*time.Second)
	defer stop()

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	text := a.k.Text(0)
	if !c.committed || !c.ztpDeleted || strings.Index(text, "delete chassis auto-image-upgrade") > strings.Index(text, "commit and-quit") {
		t.Fatalf("ZTP commit'ten önce silinmeli: commit=%v silindi=%v\nkonsol: %q", c.committed, c.ztpDeleted, text)
	}
}

// Kurulum bitince teslim anındaki yapılandırma panele gider.
// EX4200 (12.3R12.4, sahada): yankı boşlukla biter, istemden önce
// "{master:0}" durur. Yapılandırma ikisine takılmadan alınmalı.
func TestFinalConfigSentOnEx4200(t *testing.T) {
	c := newFake(t, "loader")
	c.ex4200 = true
	job := sampleJob
	job.Configuration = false
	a, events := newTestFlow(t, c, job)
	var got string
	a.finalConfig = func(_ context.Context, text string) error { got = text; return nil }
	ctx, stop := context.WithTimeout(context.Background(), 60*time.Second)
	defer stop()

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if got != "## Last commit: 2016-01-20 07:07:07 UTC by root\nversion 12.3R12.4;\npoe {\n    interface all;\n}" {
		t.Fatalf("gönderilen: %q\nolaylar: %v", got, *events)
	}
	/* Konsolda commit hatası yok; ZTP'nin çalışmadığı doğru söylenir. */
	if strings.Contains(a.k.Text(0), "commit failed") || c.configured {
		t.Errorf("commit denendi: %q", a.k.Text(0))
	}
	if olaylar := strings.Join(*events, "\n"); !strings.Contains(olaylar, "ZTP bu açılışta çalışmıyordu") || strings.Contains(olaylar, "durduruldu") {
		t.Errorf("olaylar: %v", *events)
	}
}

func TestFinalConfigSentAfterInstall(t *testing.T) {
	c := newFake(t, "login")
	c.password = "gizli"
	job := sampleJob
	job.Password = "gizli"
	a, events := newTestFlow(t, c, job)
	var got string
	a.finalConfig = func(_ context.Context, text string) error { got = text; return nil }
	ctx, stop := context.WithTimeout(context.Background(), 60*time.Second)
	defer stop()

	if err := a.Install(ctx); err != nil {
		t.Fatalf("kurulum: %v\nolaylar: %v", err, *events)
	}
	if got != "## Last commit: 2026-11-02 10:00:00 UTC by root\nsystem {\n    host-name sw1;\n}" {
		t.Fatalf("gönderilen: %q", got)
	}
	if c.state != "login" {
		t.Errorf("oturum açık kaldı: %s", c.state)
	}
}
