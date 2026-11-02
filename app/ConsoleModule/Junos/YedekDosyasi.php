<?php

declare(strict_types=1);

namespace App\ConsoleModule\Junos;

use Illuminate\Http\UploadedFile;

/**
 * Operatörün bilgisayarından seçtiği yedek: düz metin ya da gzip
 * (yedek klasöründeki .gz'ler gibi). Junos yapılandırması değilse (system
 * bloğu yok) null: yanlış dosya switch'e `load override` ile gitmesin.
 */
final class YedekDosyasi
{
    public const EN_BUYUK = 8 * 1024 * 1024;

    public static function metin(UploadedFile $dosya): ?string
    {
        $ham = (string) @file_get_contents($dosya->getRealPath(), false, null, 0, self::EN_BUYUK + 1);

        if (str_starts_with($ham, "\x1f\x8b")) {
            $ham = (string) @gzdecode($ham, self::EN_BUYUK);
        }
        if ($ham === '' || strlen($ham) > self::EN_BUYUK || ! mb_check_encoding($ham, 'UTF-8')) {
            return null;
        }

        return ConfigTree::parse($ham)->varMi('system') ? $ham : null;
    }
}
