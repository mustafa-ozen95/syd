<?php

declare(strict_types=1);

namespace App\ConsoleModule\Remote;

use App\ConsoleModule\Models\ConsolePc;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use Carbon\CarbonImmutable;

/**
 * KONSOL PC'LERİ — konsol kablosunun takılı olduğu, ajanın çalıştığı
 * makineler; panel onlara Windows'ta WinRM, Linux'ta SSH ile bağlanıp ajanı
 * kurar, durumunu okur, kaldırır. Ajan anlıktır (bkz. SyncAgentsCommand).
 *
 * Ajan dışarıdan bağlantı kabul etmez (panele kendisi bağlanır): güvenlik
 * duvarına kural eklenmez.
 *
 * Ajan jetonu her kurulumda YENİDEN üretilir, eskisi iptal edilir; yalnız
 * PC'nin adresinden kabul edilir. PC'ye betikle değil kabuğun ortamında
 * (WinRM) ya da standart girdide (SSH) gider; komut satırına düşmez.
 */
class SitePc
{
    private const GOREV = 'Konsol Ajani';

    private const SERVIS = 'konsol-ajan';

    public function __construct(
        private WinRm $winrm,
        private Ssh $ssh,
        private UserPermissionResolver $yetki,
    ) {}

    public function sina(ConsolePc $pc): string
    {
        return 'Bağlantı tamam: '.($pc->windowsMu()
            ? $this->calistir($pc, '"$env:COMPUTERNAME · " + (Get-CimInstance Win32_OperatingSystem).Caption')
            : $this->calistir($pc, 'echo "$(hostname) · $(. /etc/os-release && echo "$PRETTY_NAME") · $(id -un)"'));
    }

    /**
     * Ajanı kurar ya da günceller: PC dosyayı panelden yeni jetonla indirir,
     * sağlamayı doğrular, ajan.json'ı yazar, açılışta başlayan bir görev/servis
     * kurar ve başlatır.
     */
    public function kur(ConsolePc $pc, User $kim): string
    {
        if (! $this->yetki->allows($kim, 'konsol', 'ajan')) {
            throw new RemoteError('Ajan jetonu sizin adınıza üretilir; "Konsol kurulum: Ajan olarak bağlan" yetkiniz olmalı.');
        }

        $platform = $pc->windowsMu() ? 'windows' : 'linux';
        $sha = $this->ajanOzeti($platform);
        $pem = $this->kendindenImzaliSertifika();
        $panel = rtrim((string) config('app.url'), '/');
        $sir = ApiToken::mint();
        $jeton = ApiToken::create([
            'name' => "Konsol ajanı · {$pc->ad} ({$pc->adres})",
            'user_id' => $kim->id,
            'created_by' => $kim->id,
            'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'],
            'abilities' => ['konsol' => ['view', 'ajan']],
            /* Panel PC'yi NAT arkasından görebilir (bkz. gorunen_adres). */
            'allowed_ips' => array_values(array_unique(array_filter([$pc->adres, $pc->gorunen_adres]))),
            'expires_at' => CarbonImmutable::now()->addDays(max(1, (int) config('api.default_lifetime_days'))),
        ]);

        $ajanAyari = (string) json_encode([
            'panel' => $panel,
            'jeton' => $sir['plain'],
            'seri_port' => (string) $pc->seri_port,
        ] + ($pem !== null ? ['ca_dosyasi' => 'panel.pem'] : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $adres = "{$panel}/api/v1/konsol/ajan/{$platform}";

        try {
            $sonuc = $pc->windowsMu()
                ? $this->calistir($pc, $this->windowsKur($pc, $adres, $sha, $pem), ['KONSOL_JETON' => $sir['plain'], 'KONSOL_AYAR' => $ajanAyari], 100)
                : $this->calistir($pc, $this->linuxKur($pc, $adres, $sha, $sir['plain'], $ajanAyari, $pem), [$sir['plain']], 100);
        } catch (RemoteError $e) {
            $jeton->revoke();

            throw $e;
        }

        if ($pc->jeton_id !== null && $pc->jeton_id !== $jeton->id) {
            ApiToken::query()->whereKey($pc->jeton_id)->whereNull('revoked_at')->first()?->revoke();
        }

        $pc->forceFill(['jeton_id' => $jeton->id, 'son_kurulum_at' => now()])->save();

        return $sonuc;
    }

    /**
     * Ajanı PC'den TAMAMEN kaldırır: görev/servis, güvenlik duvarı kuralları,
     * dizin (ajan.json, panel.pem, görüntü önbelleği, kayıt) ve jeton. PC
     * işten önceki hâline döner.
     */
    public function kaldir(ConsolePc $pc): string
    {
        $sonuc = $pc->windowsMu()
            ? $this->calistir($pc, $this->onEk($pc).<<<'PS'
                if (Get-ScheduledTask -TaskName $gorev -ErrorAction SilentlyContinue) {
                    Stop-ScheduledTask -TaskName $gorev -ErrorAction SilentlyContinue
                    Unregister-ScheduledTask -TaskName $gorev -Confirm:$false
                }
                Get-Process -Name 'konsol-ajan' -ErrorAction SilentlyContinue | Stop-Process -Force
                Start-Sleep -Seconds 1
                if (Test-Path $d) { Remove-Item -Recurse -Force $d }
                "Kaldırıldı: görev ve $d silindi."
                PS, [], 100)
            : $this->calistir($pc, 'd='.$this->kabukTirnak($pc->dizin).'
'.<<<'SH'
                case "$d" in /|/opt|/usr|/etc|/var|/home|/root|"") echo "Dizin kaldırılamaz: $d" >&2; exit 1 ;; esac
                systemctl disable --now konsol-ajan 2>/dev/null || true
                rm -f /etc/systemd/system/konsol-ajan.service
                systemctl daemon-reload
                rm -rf "$d"
                echo "Kaldırıldı: servis ve $d silindi."
                SH, [], 100);

        $pc->jeton?->revoke();
        $pc->forceFill(['jeton_id' => null])->save();

        return $sonuc;
    }

    public function baslat(ConsolePc $pc): string
    {
        return $pc->windowsMu()
            ? $this->calistir($pc, $this->onEk($pc).'Start-ScheduledTask -TaskName $gorev; Start-Sleep -Seconds 3; "Görev: " + (Get-ScheduledTask -TaskName $gorev).State')
            : $this->calistir($pc, 'systemctl start '.self::SERVIS.'; sleep 3; echo "Servis: $(systemctl is-active '.self::SERVIS.' || true)"');
    }

    public function durdur(ConsolePc $pc): string
    {
        return $pc->windowsMu()
            ? $this->calistir($pc, $this->onEk($pc).'Stop-ScheduledTask -TaskName $gorev -ErrorAction SilentlyContinue; '
                .'Get-Process -Name "konsol-ajan" -ErrorAction SilentlyContinue | Stop-Process -Force; "Durduruldu."')
            : $this->calistir($pc, 'systemctl stop '.self::SERVIS.' || true; echo "Durduruldu."');
    }

    /**
     * Görev/servis, sürüm, seri portlar ve kaydın son satırları.
     *
     * @return array{gorev: string, surum: string, portlar: list<string>, kayit: list<string>}
     */
    public function durum(ConsolePc $pc): array
    {
        $metin = $pc->windowsMu()
            ? $this->calistir($pc, $this->onEk($pc).<<<'PS'
                $t = Get-ScheduledTask -TaskName $gorev -ErrorAction SilentlyContinue
                $log = Join-Path $d 'ajan.log'
                "@@GOREV"; if ($t) { [string]$t.State } else { 'kurulu değil' }
                "@@SURUM"; if (Test-Path $exe) { [string](& $exe -surum) }
                "@@PORTLAR"; Get-CimInstance Win32_PnPEntity | Where-Object { $_.Name -match '\(COM\d+\)' } | ForEach-Object { $_.Name }
                "@@KAYIT"; if (Test-Path $log) { Get-Content -Encoding UTF8 -Tail 40 $log }
                PS)
            : $this->calistir($pc, 'd='.$this->kabukTirnak($pc->dizin)."\n".<<<'SH'
                echo @@GOREV; systemctl is-active konsol-ajan 2>/dev/null || true
                echo @@SURUM; [ -x "$d/konsol-ajan" ] && "$d/konsol-ajan" -surum || true
                echo @@PORTLAR; for p in /dev/serial/by-id/*; do [ -e "$p" ] && echo "$(readlink -f "$p") · $(basename "$p")"; done; ls /dev/ttyUSB* /dev/ttyACM* 2>/dev/null || true
                echo @@KAYIT; [ -f "$d/ajan.log" ] && tail -n 40 "$d/ajan.log" || true
                SH);

        $bolum = ['GOREV' => [], 'SURUM' => [], 'PORTLAR' => [], 'KAYIT' => []];
        $simdiki = null;

        foreach (preg_split('/\r?\n/', $metin) ?: [] as $satir) {
            if (preg_match('/^@@([A-Z]+)$/', trim($satir), $m) && isset($bolum[$m[1]])) {
                $simdiki = $m[1];
            } elseif ($simdiki !== null && trim($satir) !== '') {
                $bolum[$simdiki][] = rtrim($satir);
            }
        }

        return [
            'gorev' => $bolum['GOREV'][0] ?? '',
            'surum' => $bolum['SURUM'][0] ?? '',
            'portlar' => array_values(array_unique($bolum['PORTLAR'])),
            'kayit' => $bolum['KAYIT'],
        ];
    }

    /** Panelin dağıttığı ajanın sağlaması (imaj derlenirken üretilir). */
    public function ajanOzeti(string $platform): string
    {
        $dosya = rtrim((string) config('konsol.ajan_dizini'), '/').'/SHA256SUMS';
        $ad = (string) config("konsol.ajan_dosyalari.{$platform}.dosya");

        foreach (is_file($dosya) ? (array) file($dosya, FILE_IGNORE_NEW_LINES) : [] as $satir) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(\S+)$/', trim((string) $satir), $m) && $m[2] === $ad) {
                return $m[1];
            }
        }

        throw new RemoteError('Ajan bu imajda derlenmemiş ('.$platform.').');
    }

    private function windowsKur(ConsolePc $pc, string $adres, string $sha, ?string $pem): string
    {
        /*
         * Kendinden imzalı panel: indirme yalnız O sertifikayla yapılır
         * (DER'in SHA-256'sı karşılaştırılır), ajan da panel.pem'e güvenir.
         *
         * Denetim C# ile derlenir: PowerShell betik bloğu .NET'in TLS iş
         * parçacığında çalışamıyor ("There is no Runspace available").
         */
        $sabitle = $pem === null ? '' : <<<PS
            [IO.File]::WriteAllText((Join-Path \$d 'panel.pem'), @'
            {$pem}
            '@)
            Add-Type -TypeDefinition @'
            using System; using System.Net.Security; using System.Security.Cryptography; using System.Security.Cryptography.X509Certificates;
            public static class KonsolSabit {
                public static string Beklenen;
                public static bool Dogrula(object s, X509Certificate c, X509Chain z, SslPolicyErrors h) {
                    if (h == SslPolicyErrors.None) return true;
                    using (var sha = SHA256.Create()) { return BitConverter.ToString(sha.ComputeHash(c.GetRawCertData())).Replace("-", "").ToLowerInvariant() == Beklenen; }
                }
                public static readonly RemoteCertificateValidationCallback Geri = Dogrula;
            }
            '@
            [KonsolSabit]::Beklenen = '{$this->derOzeti($pem)}'
            [Net.ServicePointManager]::ServerCertificateValidationCallback = [KonsolSabit]::Geri

            PS;

        return $this->onEk($pc).<<<PS
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            New-Item -ItemType Directory -Force -Path \$d | Out-Null
            # Dizini yalnız SYSTEM ve Yöneticiler okusun: ajan.json jeton taşır (SID: dil bağımsız).
            icacls \$d /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
            {$sabitle}\$yeni = Join-Path \$d 'konsol-ajan.exe.yeni'
            Invoke-WebRequest -UseBasicParsing -Uri '{$adres}' -Headers @{ Authorization = 'Bearer ' + \$env:KONSOL_JETON } -OutFile \$yeni
            [Net.ServicePointManager]::ServerCertificateValidationCallback = \$null
            \$h = (Get-FileHash -Algorithm SHA256 \$yeni).Hash.ToLower()
            if (\$h -ne '{$sha}') { Remove-Item -Force \$yeni; throw "İndirilen dosyanın sağlaması tutmadı: \$h" }
            if (Get-ScheduledTask -TaskName \$gorev -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName \$gorev -ErrorAction SilentlyContinue }
            Get-Process -Name 'konsol-ajan' -ErrorAction SilentlyContinue | Stop-Process -Force
            Start-Sleep -Seconds 1
            Move-Item -Force \$yeni \$exe
            [IO.File]::WriteAllText((Join-Path \$d 'ajan.json'), \$env:KONSOL_AYAR, (New-Object Text.UTF8Encoding \$false))
            \$eylem = New-ScheduledTaskAction -Execute \$exe -WorkingDirectory \$d
            \$tetik = New-ScheduledTaskTrigger -AtStartup
            \$secenek = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
            \$kim = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
            Register-ScheduledTask -TaskName \$gorev -Action \$eylem -Trigger \$tetik -Settings \$secenek -Principal \$kim -Force | Out-Null
            Start-ScheduledTask -TaskName \$gorev
            Start-Sleep -Seconds 3
            "Kuruldu: " + (& \$exe -surum) + " · görev " + (Get-ScheduledTask -TaskName \$gorev).State
            PS;
    }

    /**
     * Jeton betiğin içinde ama betik uzak makinede STANDART GİRDİDEN okunur;
     * curl'e başlık dosya tanıtıcısıyla verilir, süreç listesinde görünmez.
     */
    private function linuxKur(ConsolePc $pc, string $adres, string $sha, string $jeton, string $ajanAyari, ?string $pem): string
    {
        $sabitle = $pem === null ? '' : "cat > \"\$d/panel.pem\" <<'KONSOL_PANEL_PEM'\n{$pem}\nKONSOL_PANEL_PEM\n";
        $cacert = $pem === null ? '' : '--cacert "$d/panel.pem" ';

        $d = $this->kabukTirnak($pc->dizin);
        $j = $this->kabukTirnak($jeton);
        $u = $this->kabukTirnak($adres);
        $servis = self::SERVIS;

        return <<<SH
            d={$d}
            JETON={$j}
            install -d -m 700 "\$d"
            {$sabitle}curl -fsS --proto =https {$cacert}-H @<(printf 'Authorization: Bearer %s' "\$JETON") -o "\$d/konsol-ajan.yeni" {$u}
            echo "{$sha}  \$d/konsol-ajan.yeni" | sha256sum -c --quiet || { rm -f "\$d/konsol-ajan.yeni"; echo "İndirilen dosyanın sağlaması tutmadı" >&2; exit 1; }
            chmod 755 "\$d/konsol-ajan.yeni"
            systemctl stop {$servis} 2>/dev/null || true
            mv -f "\$d/konsol-ajan.yeni" "\$d/konsol-ajan"
            umask 077
            cat > "\$d/ajan.json" <<'KONSOL_AJAN_JSON'
            {$ajanAyari}
            KONSOL_AJAN_JSON
            cat > /etc/systemd/system/{$servis}.service <<KONSOL_BIRIM
            [Unit]
            Description=Konsol kurulum ajani (SYD)
            After=network-online.target
            Wants=network-online.target

            [Service]
            ExecStart=\$d/konsol-ajan
            WorkingDirectory=\$d
            Restart=always
            RestartSec=30

            [Install]
            WantedBy=multi-user.target
            KONSOL_BIRIM
            systemctl daemon-reload
            systemctl enable -q {$servis}
            systemctl restart {$servis}
            sleep 3
            echo "Kuruldu: \$("\$d/konsol-ajan" -surum) · servis \$(systemctl is-active {$servis} || true)"
            SH;
    }

    /** Panelin sertifikası, YALNIZ kendinden imzalıysa (PEM). */
    public function kendindenImzaliSertifika(): ?string
    {
        $yol = (string) config('konsol.panel_sertifikasi');
        $pem = is_file($yol) ? (string) file_get_contents($yol) : '';
        $bilgi = $pem !== '' ? openssl_x509_parse($pem) : false;

        if ($bilgi === false || ($bilgi['subject'] ?? null) !== ($bilgi['issuer'] ?? false)) {
            return null;
        }

        /* Yalnız ilk sertifika; özel anahtar ya da başka bir şey sızmasın. */
        return preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m) ? $m[0] : null;
    }

    private function derOzeti(string $pem): string
    {
        $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem), true);

        return hash('sha256', (string) $der);
    }

    private function onEk(ConsolePc $pc): string
    {
        $d = str_replace("'", "''", $pc->dizin);

        return "\$d = '{$d}'\n\$gorev = '".self::GOREV."'\n\$exe = Join-Path \$d 'konsol-ajan.exe'\n";
    }

    private function kabukTirnak(string $deger): string
    {
        return "'".str_replace("'", "'\\''", $deger)."'";
    }

    /**
     * @param  array<string, string>|list<string>  $sirlar  Windows'ta kabuğun ortam değişkenleri,
     *                                                      Linux'ta yalnız çıktıdan silinecek değerler
     */
    private function calistir(ConsolePc $pc, string $betik, array $sirlar = [], ?int $sure = null): string
    {
        if ($pc->windowsMu()) {
            $sonuc = $this->winrm->powershell(
                ['adres' => $pc->adres, 'port' => $pc->port, 'kullanici' => $pc->kullanici, 'parola' => (string) $pc->parola],
                $betik, $sirlar, $sure,
            );
        } else {
            $sonuc = $this->ssh->bash(
                ['adres' => $pc->adres, 'port' => $pc->port, 'kullanici' => $pc->kullanici, 'parola' => (string) $pc->parola,
                    'parmak_izi' => $pc->ssh_parmak_izi],
                $betik, array_values($sirlar), $sure,
            );

            /* İlk bağlantı: anahtarın izi saklanır, sonrakiler buna göre doğrulanır. */
            if ($pc->ssh_parmak_izi === null && ($sonuc['parmak_izi'] ?? null) !== null) {
                $pc->forceFill(['ssh_parmak_izi' => $sonuc['parmak_izi']])->save();
            }
        }

        if ($sonuc['kod'] !== 0) {
            throw new RemoteError('PC komutu reddetti (kod '.$sonuc['kod'].'): '.mb_substr($sonuc['hata'] ?: $sonuc['cikti'], 0, 400));
        }

        return $sonuc['cikti'];
    }
}
