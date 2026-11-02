<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Junos\ConfigTree;
use App\ConsoleModule\Junos\PatchBuilder;

/**
 * Seçilen yedek + formdaki özelleştirme → yedeğin üstüne uygulanacak yama.
 * Formun ön değerleri, ekrandaki önizleme ve işe yazılan yama buradan çıkar.
 */
class Ozellestirme
{
    public function __construct(private ConfigurationSource $yedekler, private PatchBuilder $uretici) {}

    /** @return array<string, mixed>|null yedek yoksa null */
    public function alanlar(string $cihaz, string $dosya): ?array
    {
        $t = $this->agac($cihaz, $dosya);

        return $t === null ? null : $this->uretici->alanlar($t);
    }

    /**
     * @param  array<string, mixed>  $oz  OzellestirmeAlanlari::ozellestirme()
     * @return list<string>
     */
    public function yama(string $cihaz, string $dosya, array $oz): array
    {
        $t = $this->agac($cihaz, $dosya);

        return $t === null ? [] : $this->uretici->satirlar($t, $oz);
    }

    /** @param  array<string, mixed>  $oz */
    public function ozet(string $cihaz, string $dosya, array $oz): string
    {
        $t = $this->agac($cihaz, $dosya);

        return $t === null ? '' : $this->uretici->ozet($t, $oz);
    }

    private function agac(string $cihaz, string $dosya): ?ConfigTree
    {
        $metin = $cihaz === '' ? null : $this->yedekler->icerik($cihaz, $dosya !== '' ? $dosya : null);

        return $metin === null ? null : ConfigTree::parse($metin);
    }
}
