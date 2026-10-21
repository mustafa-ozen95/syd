<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kullanıcı rolü.
 *
 * Rol bir KAYITTIR, koda gömülü bir dize değil: slug'lar merkezdekiyle ve
 * kardeş panellerdekiyle birebir aynı (ör. admin) ve
 * merkez rol değişikliğini bu slug üzerinden bildiriyor.
 *
 * ROL YETKİ TAŞIR ve CANLI okunur (kardeş panellerdeki yapı): `yetkiler`
 * ne diyorsa o roldeki herkes için o geçerli. Kişinin satırları yalnızca
 * İSTİSNADIR (bkz. UserPermissionResolver). Yönetici rolü yetki listesi
 * tutmaz: her kontrolü geçer.
 */
class Role extends Model
{
    protected $table = 'roller';

    /** Ekranda seçilebilen rozet renkleri (css: .badge--*). */
    public const ROZETLER = ['gray', 'blue', 'green', 'cyan', 'purple', 'orange', 'red'];

    protected $fillable = ['slug', 'label', 'aciklama', 'badge', 'yonetici', 'yerlesik', 'sira', 'yetkiler'];

    protected function casts(): array
    {
        return ['yonetici' => 'boolean', 'yerlesik' => 'boolean', 'yetkiler' => 'array'];
    }

    /**
     * Rolün bu kaynakta verdiği aksiyonlar.
     *
     * @return list<string>
     */
    public function aksiyonlar(string $kaynak): array
    {
        return array_values((array) (($this->yetkiler ?? [])[$kaynak] ?? []));
    }

    /** Rolün bu kaynakta bu aksiyonu verip vermediği. */
    public function verir(string $kaynak, string $aksiyon): bool
    {
        return in_array($aksiyon, $this->aksiyonlar($kaynak), true);
    }

    /**
     * Silinebilir mi? Yerleşik roller merkezle aynı slug'ı taşır; kullanıcısı
     * olan rol silinirse kişi rolsüz kalır ve hiçbir yere giremez.
     */
    public function silinebilirMi(): bool
    {
        return ! $this->yerlesik && ! $this->kullanicilar()->exists();
    }

    /** @return HasMany<User, $this> */
    public function kullanicilar(): HasMany
    {
        return $this->hasMany(User::class, 'rol_id');
    }

    public function scopeSirali(Builder $query): void
    {
        $query->orderBy('sira')->orderBy('label');
    }
}
