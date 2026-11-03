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
        'ad', 'platform', 'adres', 'gorunen_adres', 'port', 'kullanici', 'parola', 'seri_port', 'seri_portlar', 'port_bilgileri', 'portlar_at',
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
     * PC'nin seri portları (kablo kimlikleri; eski kayıtlarda COM adı). Liste
     * yoksa eski tek port; o da yoksa boş (ajan USB-seri dönüştürücüyü
     * kendisi bulur).
     *
     * @return list<string>
     */
    public function portlar(): array
    {
        return array_values(array_filter((array) ($this->seri_portlar ?: [$this->seri_port]), fn ($p): bool => (string) $p !== ''));
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
        $algilanan = array_map(fn (string $p): string => strtoupper(($this->port_bilgileri ?? [])[$p]['com'] ?? $p), $portlar);
        $adlar = $this->windowsMu()
            ? array_map(fn (int $n): string => "COM{$n}", range(1, 10))
            : array_map(fn (int $n): string => "/dev/ttyUSB{$n}", range(0, 9));
        $elle = array_filter($adlar, fn (string $ad): bool => ! in_array(strtoupper($ad), $algilanan, true));

        return [...$portlar, ...array_values($elle)];
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
