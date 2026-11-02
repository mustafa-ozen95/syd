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

/** Konsol kablosundan bir loader kurulumu ya da yalnız yedek yükleme. */
class ConsoleJob extends Model
{
    protected $table = 'konsol_isleri';

    /** Ajanın üzerinde çalışabildiği durumlar. */
    public const ACIK = ['bekliyor', 'calisiyor'];

    /** Bir seri portta tek açık iş: o kablo bir switch'e takılı. */
    public static function portMesgul(string $port): string
    {
        return $port === ''
            ? 'Bu PC\'de açık bir iş var; konsol kablosu bir tane. Önce onu kapatın ya da PC\'ye başka bir seri port tanımlayın.'
            : "Bu PC'de {$port} üzerinde açık bir iş var. Önce onu kapatın ya da başka bir port seçin.";
    }

    /** @var array<string, array{0: string, 1: BadgeVariant}> durum => [etiket, renk] */
    public const DURUMLAR = [
        'bekliyor' => ['Ajan bekleniyor', BadgeVariant::Gray],
        'calisiyor' => ['Çalışıyor', BadgeVariant::Blue],
        'tamam' => ['Tamam', BadgeVariant::Green],
        'hata' => ['Hata', BadgeVariant::Red],
        'iptal' => ['Kapatıldı', BadgeVariant::Gray],
    ];

    /** İşin ne yaptığı. "yedek": Junos'u kurulu cihaza yalnız yedek yüklenir. */
    public const ISLEMLER = [
        'kurulum' => 'Junos kur (sonra istenirse yedeği yükle)',
        'yedek' => 'Yalnız yedeği yükle (Junos kurulu cihaz)',
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
        'pc_id', 'seri_port', 'acik_port', 'islem', 'ad', 'goruntu_etiket', 'goruntu_adres', 'beklenen_surum',
        'yapilandirma_turu', 'yedek_cihaz', 'yedek_dosya', 'yedek_metni', 'gecici_goruntu', 'yama', 'yama_ozeti', 'uplink_arayuz', 'adres_turu', 'cihaz_ip', 'gecit', 'root_parola',
        'durum', 'asama', 'son_mesaj', 'ajan_token_id', 'olusturan_id', 'cikti_bayt',
    ];

    protected $hidden = ['root_parola', 'yama', 'yedek_metni'];

    protected function casts(): array
    {
        return [
            'root_parola' => 'encrypted',
            'yama' => 'encrypted',
            'yedek_metni' => 'encrypted',
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

    public function yalnizYedekMi(): bool
    {
        return $this->islem === 'yedek';
    }

    /** Kurulan görüntü ya da "Yalnız yedek", ekranda. */
    public function islemOzeti(): string
    {
        return $this->yalnizYedekMi() ? 'Yalnız yedek yükleme' : (string) $this->goruntu_etiket;
    }

    /** Switch'in loader'daki adresi, ekranda. */
    public function adresOzeti(): string
    {
        if ($this->yalnizYedekMi()) {
            return 'Adres verilmez';
        }

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
            'dosya' => "Bilgisayardan yüklenen yedek · {$this->yedek_cihaz}",
            default => 'Yapılandırma yüklenmez',
        }.($this->yama_ozeti ? " · özelleştirildi: {$this->yama_ozeti}" : '');
    }

    /**
     * Kapatır: sonuç yazılır, root parolası silinir (yalnız iş sürerken
     * gerekiyordu).
     */
    public function kapat(string $durum, ?string $mesaj): void
    {
        $this->forceFill([
            'durum' => $durum,
            /* Port boşalır: aynı porta yeni iş açılabilsin (unique pc_id + acik_port). */
            'acik_port' => null,
            'son_mesaj' => $mesaj === null ? $this->son_mesaj : mb_substr($mesaj, 0, 500),
            'root_parola' => null,
            /* Yama parola özetleri taşır; yalnız iş sürerken gerekiyordu. */
            'yama' => null,
            /* Yüklenen yedek parola özetleri ve sırlar taşır; iş bitince gerekmez. */
            'yedek_metni' => null,
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
