<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Yüklenen Junos görüntüsü sağlam mı: gzip akışı SONUNA KADAR açılır,
 * verildiyse Juniper'ın yayımladığı sağlama (MD5, SHA-1 ya da SHA-256)
 * karşılaştırılır.
 *
 * Neden yükleme anında: yarım kalmış bir dosya ancak kurulumda ortaya
 * çıkıyordu; switch loader'a inmiş, disk formatlanmış, kurucu "truncated
 * gzip input" ile düşmüş oluyordu. Juniper'ın görüntülerinin hepsi gzip
 * (jinstall-*.tgz, junos-install-*.tgz, jinstall-host-qfx-*.tgz).
 *
 * Kurulum sayfasındaki yükleme (JunosImageRequest) ve iş formundaki
 * bilgisayardan yükleme (IsRequest) aynı kuralı kullanır.
 */
class JunosGoruntusu implements ValidationRule
{
    /** Formdaki sağlama alanının biçimi. */
    public const SAGLAMA = '/^([0-9a-fA-F]{32}|[0-9a-fA-F]{40}|[0-9a-fA-F]{64})$/';

    private const ALGORITMALAR = [32 => 'md5', 40 => 'sha1', 64 => 'sha256'];

    private const PARCA = 1 << 20;

    public function __construct(private ?string $beklenen = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $beklenen = strtolower(trim((string) $this->beklenen));
        $algoritma = preg_match(self::SAGLAMA, $beklenen) === 1 ? self::ALGORITMALAR[strlen($beklenen)] : null;
        $sonuc = self::denetle((string) $value->getRealPath(), $algoritma);

        if ($sonuc['hata'] !== null) {
            $fail($sonuc['hata']);
        } elseif ($algoritma !== null && ! hash_equals($beklenen, (string) $sonuc['ozet'])) {
            $fail('Dosyanın '.strtoupper($algoritma)." sağlaması tutmadı (beklenen {$beklenen}, dosya {$sonuc['ozet']}). Dosya bozuk ya da eksik inmiş; yeniden indirin.");
        }
    }

    /**
     * @return array{hata: ?string, ozet: ?string}
     */
    public static function denetle(string $yol, ?string $algoritma): array
    {
        $dosya = @fopen($yol, 'rb');
        if ($dosya === false) {
            return ['hata' => 'Dosya okunamadı.', 'ozet' => null];
        }

        try {
            if (fread($dosya, 2) !== "\x1f\x8b") {
                return ['hata' => 'Bu bir Junos görüntüsü değil: Juniper görüntüleri gzip (.tgz) olur.', 'ozet' => null];
            }
            rewind($dosya);

            $acici = inflate_init(ZLIB_ENCODING_GZIP);
            $ozet = $algoritma !== null ? hash_init($algoritma) : null;
            $bitti = false;

            while (! feof($dosya)) {
                $parca = (string) fread($dosya, self::PARCA);
                $ozet !== null && hash_update($ozet, $parca);
                /* Akış bittikten sonra gelen bayt (dolgu) yalnız sağlamaya girer. */
                if (! $bitti && $parca !== '') {
                    if (@inflate_add($acici, $parca, ZLIB_SYNC_FLUSH) === false) {
                        return ['hata' => 'Dosya bozuk: gzip akışı açılamadı. Yeniden indirip yükleyin.', 'ozet' => null];
                    }
                    $bitti = inflate_get_status($acici) === ZLIB_STREAM_END;
                }
            }

            if (! $bitti) {
                return ['hata' => 'Dosya eksik: gzip akışı yarıda bitiyor (yükleme ya da indirme tamamlanmamış). Yeniden indirip yükleyin.', 'ozet' => null];
            }

            return ['hata' => null, 'ozet' => $ozet !== null ? hash_final($ozet) : null];
        } finally {
            fclose($dosya);
        }
    }
}
