<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Junos\ConfigTree;
use App\ConsoleModule\Junos\PatchBuilder;
use App\Models\ConfigSablonu;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Seçilen yedek + formdaki özelleştirme → yedeğin üstüne uygulanacak yama.
 * Formun ön değerleri, ekrandaki önizleme ve işe yazılan yama buradan çıkar.
 * Yedek ya listedeki bir cihazın dosyasıdır ya da bilgisayardan yüklenen
 * metin ($metin verilirse o geçerlidir).
 */
class Ozellestirme
{
    public function __construct(private ConfigurationSource $yedekler, private PatchBuilder $uretici) {}

    /** @return array<string, mixed>|null yedek yoksa null */
    public function alanlar(string $cihaz, string $dosya, ?string $metin = null): ?array
    {
        $t = $this->agac($cihaz, $dosya, $metin);

        return $t === null ? null : $this->uretici->alanlar($t);
    }

    /**
     * @param  array<string, mixed>  $oz  OzellestirmeAlanlari::ozellestirme()
     * @return list<string>
     */
    public function yama(string $cihaz, string $dosya, array $oz, ?string $metin = null): array
    {
        $t = $this->agac($cihaz, $dosya, $metin);

        return $t === null ? [] : $this->uretici->satirlar($t, $oz);
    }

    /** @param  array<string, mixed>  $oz */
    public function ozet(string $cihaz, string $dosya, array $oz, ?string $metin = null): string
    {
        $t = $this->agac($cihaz, $dosya, $metin);

        return $t === null ? '' : $this->uretici->ozet($t, $oz);
    }

    /**
     * Bu kişi bu kaynakta özelleştirebilir mi: config şablonunda iş açan
     * herkes; cihaz yedeğinde ya da bilgisayardan yüklenen dosyada yalnız
     * "Yedekte özelleştirme" (konsol:ozellestir) yetkisi olan.
     */
    public static function serbestMi(?User $kim, string $cihaz, bool $yuklendi): bool
    {
        if ($kim !== null && Gate::forUser($kim)->allows('konsol:ozellestir')) {
            return true;
        }

        return ! $yuklendi && $cihaz !== '' && ConfigSablonu::query()->where('ad', $cihaz)->exists();
    }

    private function agac(string $cihaz, string $dosya, ?string $metin): ?ConfigTree
    {
        $metin ??= $cihaz === '' ? null : $this->yedekler->icerik($cihaz, $dosya !== '' ? $dosya : null);

        return $metin === null ? null : ConfigTree::parse($metin);
    }
}
