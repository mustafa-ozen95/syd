<?php

declare(strict_types=1);

namespace App\ConsoleModule\Models;

use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Konsol kablosunun takılı olduğu PC; ajan burada çalışır (bkz. Uzak\SitePc). */
class ConsolePc extends Model
{
    protected $table = 'konsol_pcleri';

    public const PLATFORMLAR = [
        'windows' => ['etiket' => 'Windows (WinRM)', 'port' => 5985, 'dizin' => 'C:\\Konsol-Ajan'],
        'linux' => ['etiket' => 'Linux (SSH)', 'port' => 22, 'dizin' => '/opt/konsol-ajan'],
    ];

    protected $fillable = [
        'ad', 'platform', 'adres', 'gorunen_adres', 'port', 'kullanici', 'parola', 'seri_port',
        'cihaz_ip', 'gecit', 'dizin', 'ssh_parmak_izi', 'jeton_id', 'son_kurulum_at', 'ajan_mesaj',
    ];

    protected $hidden = ['parola'];

    protected function casts(): array
    {
        return [
            'parola' => 'encrypted',
            'port' => 'integer',
            'son_kurulum_at' => 'immutable_datetime',
        ];
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
