<?php

declare(strict_types=1);

namespace App\ConsoleModule\Models;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Konsol kablosunun takılı olduğu PC; ajan burada çalışır (bkz. Uzak\SitePc). */
class ConsolePc extends Model
{
    protected $table = 'konsol_pcleri';

    public const PLATFORMLAR = [
        'windows' => ['etiket' => 'Windows (WinRM)', 'port' => 5985, 'dizin' => 'C:\\Console-Agent'],
        'linux' => ['etiket' => 'Linux (SSH)', 'port' => 22, 'dizin' => '/opt/console-agent'],
    ];

    protected $fillable = [
        'ad', 'platform', 'adres', 'port', 'kullanici', 'parola', 'seri_port', 'seri_portlar', 'port_bilgileri', 'portlar_at',
        'cihaz_ip', 'gecit', 'dizin', 'ssh_parmak_izi', 'jeton_id', 'son_kurulum_at', 'ajan_mesaj',
        'ajan_deneme_sayisi', 'ajan_sonraki_deneme_at',
    ];

    protected $hidden = ['parola'];

    protected function casts(): array
    {
        return [
            'parola' => 'encrypted',
            'port' => 'integer',
            'son_kurulum_at' => 'immutable_datetime',
            'seri_portlar' => 'array',
            'port_bilgileri' => 'array',
            'portlar_at' => 'immutable_datetime',
            'ajan_deneme_sayisi' => 'integer',
            'ajan_sonraki_deneme_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        /* Adreste ve formda uuid görünür; sıralı birincil anahtar dışarı çıkmaz. */
        static::creating(function (self $pc): void {
            $pc->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function windowsMu(): bool
    {
        return $this->platform === 'windows';
    }

    /**
     * Switch'e loader'da verilecek adres ve ağ maskesi: ['198.51.100.120', '255.255.255.0'].
     *
     * @return array{0: string, 1: string}
     */
    public function cihazAdresi(): array
    {
        return self::adresCoz((string) $this->cihaz_ip);
    }

    /**
     * "198.51.100.120/24" → ['198.51.100.120', '255.255.255.0'].
     *
     * @return array{0: string, 1: string}
     */
    public static function adresCoz(string $cidr): array
    {
        [$ip, $onek] = explode('/', $cidr, 2) + [1 => '24'];
        $onek = max(0, min(32, (int) $onek));

        return [$ip, long2ip($onek === 0 ? 0 : (0xFFFFFFFF << (32 - $onek)) & 0xFFFFFFFF)];
    }

    /**
     * KONSOL KABLOSU STANDARDI: yalnız USB'den bağlanan seri aygıt.
     *
     * Switch'in konsoluna giden kablo her zaman bir USB-seri dönüştürücüdür
     * (CH340, FTDI, Prolific, CP210x, Cisco/Juniper USB konsolu). Kasanın
     * yerleşik "İletişim Bağlantı Noktası" (ACPI\PNP0501) ve PCI seri kartlar
     * hiçbir zaman konsol değildir; Teknik PC'de COM1 hep algılanıp işler
     * ona açılıyordu. Windows'ta kimlik USB\ ya da FTDIBUS\ ile başlar,
     * Linux'ta /dev/serial/by-id, /dev/ttyUSB*, /dev/ttyACM*. Eski kayıtların
     * düz "COM9"u kablo sayılır (o zaman yalnız kablolar yazılıyordu).
     */
    public static function konsolKablosuMu(string $kimlik): bool
    {
        return preg_match('#^(USB|FTDIBUS)\\\\#i', $kimlik) === 1
            || preg_match('#^COM\d+$#i', $kimlik) === 1
            || preg_match('#^/dev/(serial/by-id/|ttyUSB\d|ttyACM\d)#', $kimlik) === 1;
    }

    /**
     * PC'nin konsol kabloları (kablo kimlikleri; eski kayıtlarda COM adı).
     * Liste yoksa eski tek port. Standarda uymayan (yerleşik) portlar
     * eski bir taramadan kalmış olsa da sayılmaz.
     *
     * @return list<string>
     */
    public function portlar(): array
    {
        return array_values(array_filter((array) ($this->seri_portlar ?: [$this->seri_port]),
            fn ($p): bool => (string) $p !== '' && self::konsolKablosuMu((string) $p)));
    }

    /**
     * İş formunda seçilebilen portlar: algılananlar, ardından on düz ad —
     * Windows'ta COM1–COM10, Linux'ta /dev/ttyUSB0–9 (USB-seri kabloların
     * adı). Algılama yalnız o an takılı kabloyu görür; kablo sonradan takılır
     * ya da işletim sistemi onu geç tanırsa elle seçilebilsin. Algılanan bir
     * portun adı ikinci kez listelenmez. Ajan düz adı olduğu gibi açar.
     *
     * @return list<string>
     */
    public function secilebilirPortlar(): array
    {
        $portlar = $this->portlar();
        /* Taramada görülen her portun adı (yerleşikler dahil) elle listeye girmez. */
        $algilanan = array_map('strtoupper', [...$portlar, ...array_column($this->port_bilgileri ?? [], 'com')]);
        $adlar = $this->windowsMu()
            ? array_map(fn (int $n): string => "COM{$n}", range(1, 10))
            : array_map(fn (int $n): string => "/dev/ttyUSB{$n}", range(0, 9));
        $elle = array_filter($adlar, fn (string $ad): bool => ! in_array(strtoupper($ad), $algilanan, true));

        return [...$portlar, ...array_values($elle)];
    }

    /**
     * Yeni işte önceden seçili port: algılanan TEK konsol kablosu. İki kablo
     * takılıysa ya da hiç yoksa boş: o zaman seçmek şart.
     */
    public function varsayilanPort(): string
    {
        $kablolar = $this->portlar();

        return count($kablolar) === 1 ? $kablolar[0] : '';
    }

    /** Ekranda port: "COM9 · USB Serial Port"; algılanmamışsa olduğu gibi. */
    public function portEtiketi(string $port): string
    {
        $bilgi = ($this->port_bilgileri ?? [])[$port] ?? null;

        return $bilgi === null ? $port : trim($bilgi['com'].' · '.$bilgi['ad'], ' ·');
    }

    /** Ajan kurulu mu: panel kurduğunda jeton bağlanır, kaldırınca çözülür. */
    public function ajanKuruluMu(): bool
    {
        return $this->jeton_id !== null;
    }

    /** @return HasMany<ConsoleJob, $this> */
    public function isler(): HasMany
    {
        return $this->hasMany(ConsoleJob::class, 'pc_id');
    }

    /** @return BelongsTo<ApiToken, $this> */
    public function jeton(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'jeton_id');
    }
}
