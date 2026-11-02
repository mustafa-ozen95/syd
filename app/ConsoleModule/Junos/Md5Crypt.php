<?php

declare(strict_types=1);

namespace App\ConsoleModule\Junos;

/**
 * Junos'un `encrypted-password` biçimi ($1$, md5-crypt). Junos 12.3 EX'te
 * $5$/$6$ her modelde kabul edilmiyor; $1$ hepsinde çalışıyor. Düz metin
 * yalnız burada, bellekte durur; saklanan ve cihaza giden yalnız özettir.
 */
final class Md5Crypt
{
    public const DESEN = '/^\$1\$[.\/0-9A-Za-z]{1,8}\$[.\/0-9A-Za-z]{22}$/';

    public static function ozetMi(string $deger): bool
    {
        return preg_match(self::DESEN, $deger) === 1;
    }

    /** Düz metni özete çevirir; zaten özetse olduğu gibi döner. */
    public static function ozet(string $deger): string
    {
        if (self::ozetMi($deger)) {
            return $deger;
        }

        $abc = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $tuz = '';
        foreach (str_split(random_bytes(8)) as $b) {
            $tuz .= $abc[ord($b) % 64];
        }

        return crypt($deger, '$1$'.$tuz.'$');
    }
}
