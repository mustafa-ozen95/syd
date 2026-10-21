<?php

declare(strict_types=1);

namespace App\ConsoleModule\Sources;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\Services\Backup\BackupStore;

/**
 * SYD: cihazların FTP ile bıraktığı yedekler (yedek dizini, salt-okunur).
 * Junos yedeği gzip'li gelir; switch'e düz metin verilir.
 */
class BackupStoreSource implements ConfigurationSource
{
    /** Bir yapılandırmanın açılmış hâli bundan büyük olamaz (bozuk/kötü dosyaya karşı). */
    private const EN_BUYUK = 8 * 1024 * 1024;

    public function __construct(private BackupStore $depo) {}

    public function cihazlar(): array
    {
        return $this->depo->klasorler()
            ->filter(fn (array $k): bool => $k['adet'] > 0)
            ->pluck('ad')->map(fn ($ad): string => (string) $ad)->values()->all();
    }

    public function yedekler(string $cihaz): array
    {
        if (! in_array($cihaz, $this->cihazlar(), true)) {
            return [];
        }

        return $this->depo->dosyalar($cihaz)
            ->map(fn (array $d): array => ['dosya' => $d['ad'], 'zaman' => $d['zaman']])
            ->values()->all();
    }

    public function icerik(string $cihaz, ?string $dosya = null): ?string
    {
        $yedekler = $this->yedekler($cihaz);
        $secilen = $dosya ?? ($yedekler[0]['dosya'] ?? null);

        if ($secilen === null || ! in_array($secilen, array_column($yedekler, 'dosya'), true)) {
            return null;
        }

        $bilgi = $this->depo->dosya($cihaz.'/'.$secilen);

        if ($bilgi === null) {
            return null;
        }

        $ham = (string) file_get_contents($bilgi->getPathname(), false, null, 0, self::EN_BUYUK);

        if (str_starts_with($ham, "\x1f\x8b")) {
            $acik = @gzdecode($ham, self::EN_BUYUK);

            return $acik === false ? null : $acik;
        }

        return $ham;
    }
}
