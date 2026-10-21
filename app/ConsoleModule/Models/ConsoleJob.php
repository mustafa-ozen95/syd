<?php

declare(strict_types=1);

namespace App\ConsoleModule\Models;

use App\Enums\BadgeVariant;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Konsol kablosundan bir loader kurulumu. */
class ConsoleJob extends Model
{
    protected $table = 'konsol_isleri';

    /** Ajanın üzerinde çalışabildiği durumlar. */
    public const ACIK = ['bekliyor', 'calisiyor'];

    /** @var array<string, array{0: string, 1: BadgeVariant}> durum => [etiket, renk] */
    public const DURUMLAR = [
        'bekliyor' => ['Ajan bekleniyor', BadgeVariant::Gray],
        'calisiyor' => ['Çalışıyor', BadgeVariant::Blue],
        'tamam' => ['Tamam', BadgeVariant::Green],
        'hata' => ['Hata', BadgeVariant::Red],
        'iptal' => ['Kapatıldı', BadgeVariant::Gray],
    ];

    public const ADRES_TURLERI = [
        'sabit' => 'Sabit adres',
        'dhcp' => 'DHCP',
    ];

    public const YAPILANDIRMA_TURLERI = [
        'yok' => 'Yükleme (fabrika ayarında kalsın)',
        'son_yedek' => 'Cihazın en son yedeği',
        'yedek' => 'Seçilen yedek',
    ];

    /** Ajanın bildirebileceği adımlar. */
    public const ASAMALAR = [
        'basladi' => 'Başladı',
        'port' => 'Konsol',
        'bilgi' => 'Bilgi',
        'uyari' => 'Sizden bekleniyor',
        'loader' => 'Loader',
        'acilis' => 'Açılış',
        'kurulum' => 'Kurulum',
        'dogrulama' => 'Doğrulama',
        'yapilandirma' => 'Yapılandırma',
        'tamam' => 'Bitti',
        'hata' => 'Hata',
    ];

    protected $fillable = [
        'pc_id', 'ad', 'goruntu_etiket', 'goruntu_adres', 'beklenen_surum',
        'yapilandirma_turu', 'yedek_cihaz', 'yedek_dosya', 'adres_turu', 'cihaz_ip', 'gecit', 'root_parola',
        'durum', 'asama', 'son_mesaj', 'ajan_token_id', 'olusturan_id', 'cikti_bayt',
    ];

    protected $hidden = ['root_parola'];

    protected function casts(): array
    {
        return [
            'root_parola' => 'encrypted',
            'cikti_bayt' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /* Adreste uuid görünür; sıralı birincil anahtar dışarı çıkmaz. */
        static::creating(function (self $is): void {
            $is->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function acikMi(): bool
    {
        return in_array($this->durum, self::ACIK, true);
    }

    public function durumEtiketi(): string
    {
        return self::DURUMLAR[$this->durum][0] ?? $this->durum;
    }

    public function durumRengi(): BadgeVariant
    {
        return self::DURUMLAR[$this->durum][1] ?? BadgeVariant::Gray;
    }

    /** Switch'in loader'daki adresi, ekranda. */
    public function adresOzeti(): string
    {
        if ($this->adres_turu === 'dhcp') {
            return 'Adres: DHCP';
        }

        /* Adres sütunlarından önce açılan işler PC kaydındakini kullanır. */
        return 'Adres: '.($this->cihaz_ip ?? $this->pc?->cihaz_ip).' · gw '.($this->gecit ?? $this->pc?->gecit);
    }

    public function yapilandirmaOzeti(): string
    {
        return match ($this->yapilandirma_turu) {
            'son_yedek' => "En son yedek · {$this->yedek_cihaz}",
            'yedek' => "Yedek · {$this->yedek_cihaz} · {$this->yedek_dosya}",
            default => 'Yapılandırma yüklenmez',
        };
    }

    /**
     * Kapatır: sonuç yazılır, root parolası silinir (yalnız iş sürerken
     * gerekiyordu).
     */
    public function kapat(string $durum, ?string $mesaj): void
    {
        $this->forceFill([
            'durum' => $durum,
            'son_mesaj' => $mesaj === null ? $this->son_mesaj : mb_substr($mesaj, 0, 500),
            'root_parola' => null,
        ])->save();
    }

    /** @param  Builder<$this>  $query */
    public function scopeAcik(Builder $query): void
    {
        $query->whereIn('durum', self::ACIK);
    }

    /** @return HasMany<ConsoleEvent, $this> */
    public function olaylar(): HasMany
    {
        return $this->hasMany(ConsoleEvent::class, 'is_id');
    }

    /** @return HasMany<ConsoleOutput, $this> */
    public function ciktilar(): HasMany
    {
        return $this->hasMany(ConsoleOutput::class, 'is_id');
    }

    /** @return HasMany<ConsoleInput, $this> */
    public function girdiler(): HasMany
    {
        return $this->hasMany(ConsoleInput::class, 'is_id');
    }

    /** @return BelongsTo<ConsolePc, $this> */
    public function pc(): BelongsTo
    {
        return $this->belongsTo(ConsolePc::class, 'pc_id');
    }

    /** @return BelongsTo<User, $this> */
    public function olusturan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'olusturan_id');
    }

    /** @return BelongsTo<ApiToken, $this> */
    public function ajan(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class, 'ajan_token_id');
    }
}
