<?php

declare(strict_types=1);

namespace App\ConsoleModule\Sources;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\Models\ConfigSablonu;

/**
 * Yedek kaynağının önüne Kurulum sayfasındaki config şablonlarını koyar:
 * Konsol'daki cihaz listesinde şablonlar en üstte, tek "yedek"leriyle
 * görünür. Aynı adlı bir yedek klasörü varsa şablon geçerlidir.
 */
class SablonluKaynak implements ConfigurationSource
{
    public function __construct(private ConfigurationSource $yedekler) {}

    public function cihazlar(): array
    {
        $sablonlar = ConfigSablonu::query()->sirali()->pluck('ad')->all();

        return [...$sablonlar, ...array_values(array_diff($this->yedekler->cihazlar(), $sablonlar))];
    }

    public function yedekler(string $cihaz): array
    {
        $s = $this->sablon($cihaz);

        return $s === null ? $this->yedekler->yedekler($cihaz) : [['dosya' => $s->dosya, 'zaman' => $s->updated_at?->getTimestamp()]];
    }

    public function icerik(string $cihaz, ?string $dosya = null): ?string
    {
        $s = $this->sablon($cihaz);

        return $s === null ? $this->yedekler->icerik($cihaz, $dosya) : $s->metin;
    }

    private function sablon(string $ad): ?ConfigSablonu
    {
        return $ad === '' ? null : ConfigSablonu::query()->where('ad', $ad)->first();
    }
}
