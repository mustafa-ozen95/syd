<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Config şablonu: sıfır switch'e yüklenecek hazır yapılandırma (Kurulum
 * sayfası). Konsol'da yedek klasörlerinin ÜSTÜNDE, cihaz gibi listelenir
 * (ConsoleModule\Sources\SablonluKaynak).
 */
class ConfigSablonu extends Model
{
    protected $table = 'config_sablonlari';

    protected $fillable = ['ad', 'dosya', 'metin', 'boyut', 'sira'];

    protected $hidden = ['metin'];

    protected function casts(): array
    {
        return [
            'metin' => 'encrypted',
            'boyut' => 'integer',
            'sira' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        /* Adreste uuid görünür; sıralı birincil anahtar dışarı çıkmaz. */
        static::creating(function (self $s): void {
            $s->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @param  Builder<$this>  $query */
    public function scopeSirali(Builder $query): void
    {
        $query->orderBy('sira')->orderBy('ad');
    }
}
