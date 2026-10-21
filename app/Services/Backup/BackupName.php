<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Yedek dosyasının ADINDAN okunan bilgiler.
 *
 * Cihazlar dosyayı şu biçimde bırakıyor:
 *
 *     sw12-34.ornek.test_juniper.conf.gz_20181006_160347
 *
 * Yani tarih dosyanın ADINDA — dosya sisteminin `mtime`'ı değil. İkisi
 * ayrışıyor: yedek bir yerden bir yere kopyalandığında `mtime` kopyalama
 * anına kayıyor ve 2018 yedeği "bugün" görünüyor. Sıralama ve gösterim bu
 * yüzden addaki damgadan yapılır; damga yoksa `mtime`'a düşülür.
 */
final class BackupName
{
    /**
     * Addaki son 8+6 haneli damgayı unix zamana çevirir.
     *
     * "Son"u aramak önemli: cihaz adında da rakam olabiliyor
     * (`sw12-34`), baştaki eşleşme alınsaydı yıl diye `1234` okunurdu.
     */
    public static function zaman(string $ad): ?int
    {
        if (preg_match('/(\d{8})[_-]?(\d{6})(?!.*\d{8})/', $ad, $e)) {
            return self::damgadan($e[1].$e[2]);
        }

        if (preg_match('/(\d{8,14})(?!.*\d{8})/', $ad, $e)) {
            return self::damgadan(str_pad($e[1], 14, '0'));
        }

        return null;
    }

    /** Cihaz adı: ilk alt çizgiye kadar olan kısım. */
    public static function cihaz(string $ad): string
    {
        $parca = explode('_', $ad, 2);

        return $parca[0] !== '' ? $parca[0] : $ad;
    }

    /**
     * Listede rozet olarak gösterilen uzantı (GZ, CONF …).
     *
     * DAMGA ÖNCE ATILIR. Cihazlar damgayı uzantının ARDINA ekliyor
     * (`...conf.gz_20260201_182602`); doğrudan `pathinfo` sorulunca uzantı
     * "gz_20260201_182602" çıkıyor ve rozete "GZ_2" diye düşüyordu.
     */
    public static function tur(string $ad): string
    {
        $ad = (string) preg_replace('/[_-]?\d{8}([_-]?\d{6})?$/', '', $ad);

        $uzanti = pathinfo($ad, PATHINFO_EXTENSION);

        return $uzanti === '' ? '?' : mb_strtoupper(mb_substr($uzanti, 0, 4));
    }

    /**
     * 20181006160347 → unix zaman.
     *
     * Akla yatmayan değerler (1990 öncesi, gelecek yıl sonrası, 13. ay)
     * ELENİR: bozuk bir damga "1970" ya da "2087" diye listelenip sıralamayı
     * bozuyordu.
     */
    private static function damgadan(string $damga): ?int
    {
        if (mb_strlen($damga) < 8) {
            return null;
        }

        $yil = (int) mb_substr($damga, 0, 4);
        $ay = (int) mb_substr($damga, 4, 2);
        $gun = (int) mb_substr($damga, 6, 2);

        if ($yil < 1990 || $yil > (int) date('Y') + 1 || $ay < 1 || $ay > 12 || $gun < 1 || $gun > 31) {
            return null;
        }

        $zaman = @mktime(
            (int) mb_substr($damga, 8, 2),
            (int) mb_substr($damga, 10, 2),
            (int) mb_substr($damga, 12, 2),
            $ay,
            $gun,
            $yil,
        );

        return $zaman === false ? null : $zaman;
    }
}
