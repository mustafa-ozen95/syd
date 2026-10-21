<?php

declare(strict_types=1);

namespace App\ConsoleModule\Sources;

use App\ConsoleModule\Models\ConsoleJob;

/**
 * TFTP sunucusunun (junos-tftp, compose: tftp) ilerleme dosyasından bu işin
 * görüntü aktarımı. Loader kurulum sırasında hiçbir şey yazmıyor; ne kadar
 * indiğini yalnız sunucu biliyor; ekran belirsiz bir bekleme yerine
 * ilerlemeyi gösterir.
 */
class TftpProgress
{
    /** @return array{giden: int, boyut: int, yuzde: int, hiz: int, kalan: ?int, bitti: bool, hata: string}|null */
    public function icin(ConsoleJob $is): ?array
    {
        $yol = (string) config('konsol.tftp_durum');
        $dosya = basename((string) parse_url((string) $is->goruntu_adres, PHP_URL_PATH));

        if ($dosya === '' || ! is_file($yol) || ! is_readable($yol)) {
            return null;
        }

        $veri = json_decode((string) file_get_contents($yol, false, null, 0, 1 << 20), true);
        $bulunan = null;

        /* Bu işten sonra başlamış, aynı dosyanın en son aktarımı. */
        foreach ((array) ($veri['aktarimlar'] ?? []) as $a) {
            $basladi = strtotime((string) ($a['basladi'] ?? '')) ?: 0;

            if (($a['dosya'] ?? null) === $dosya && $basladi >= ($is->created_at?->getTimestamp() ?? PHP_INT_MAX)) {
                $bulunan = $a + ['_basladi' => $basladi];
            }
        }

        if ($bulunan === null) {
            return null;
        }

        $giden = (int) ($bulunan['giden'] ?? 0);
        $boyut = (int) ($bulunan['boyut'] ?? 0);
        $son = strtotime((string) ($bulunan['guncellendi'] ?? '')) ?: $bulunan['_basladi'];
        $sure = max(1, $son - $bulunan['_basladi']);
        $hiz = intdiv($giden, $sure);

        return [
            'giden' => $giden,
            'boyut' => $boyut,
            'yuzde' => $boyut > 0 ? min(100, intdiv($giden * 100, $boyut)) : 0,
            'hiz' => $hiz,
            'kalan' => $hiz > 0 && $boyut > $giden ? intdiv($boyut - $giden, $hiz) : null,
            'bitti' => (bool) ($bulunan['bitti'] ?? false),
            'hata' => (string) ($bulunan['hata'] ?? ''),
        ];
    }
}
