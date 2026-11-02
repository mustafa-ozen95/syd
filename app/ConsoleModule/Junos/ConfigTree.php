<?php

declare(strict_types=1);

namespace App\ConsoleModule\Junos;

/**
 * Junos'un süslü parantezli yapılandırmasını (show configuration, yedek
 * dosyası) okur. Yalnız OKUR: özelleştirme formunu yedekteki değerlerle
 * doldurmak ve yamanın neyi sileceğini bilmek için.
 *
 * Düzenli ifade yerine küçük bir ayrıştırıcı: aynı adın tekrarı
 * (`address` x4, `term` x3), iç içe bloklar, tırnaklı değerler ve yorumlar
 * düzenli ifadede kırılıyordu. `inactive:` ile kapatılmış ifade yok sayılır:
 * cihazda çalışmayan bir değer forma "mevcut" diye gelmesin.
 *
 * Bir ifade: kelimeleri ve (blok ise) çocukları. ['kelimeler' => [...], 'cocuklar' => [...]|null]
 */
final class ConfigTree
{
    /** @param  list<array{kelimeler: list<string>, cocuklar: ?array}>  $kok */
    private function __construct(private array $kok) {}

    public static function parse(string $metin): self
    {
        $simgeler = self::simgeler($metin);
        $i = 0;

        return new self(self::blok($simgeler, $i));
    }

    /**
     * Yolun sonundaki değerler. Yol bir bloğu gösteriyorsa bloktaki yaprak
     * ifadeler, bir ifadenin başını gösteriyorsa ifadenin kalan kelimeleri:
     *   'system host-name'                              → ['sw1']
     *   'interfaces vlan unit 0 family inet address'    → ['192.0.2.1/29', …]
     *   'policy-options prefix-list koru'               → ['10.0.0.0/24', …]
     *
     * @return list<string>
     */
    public function degerler(string $yol): array
    {
        return self::bul($this->kok, self::yol($yol));
    }

    public function deger(string $yol): ?string
    {
        return $this->degerler($yol)[0] ?? null;
    }

    public function varMi(string $yol): bool
    {
        return self::blokBul($this->kok, self::yol($yol)) !== null || $this->degerler($yol) !== [];
    }

    /**
     * Bir bloğun doğrudan çocuklarının adları: 'interfaces' → ['ge-0/0/0', 'vlan', 'me0', …].
     *
     * @return list<string>
     */
    public function adlar(string $yol): array
    {
        $blok = self::blokBul($this->kok, self::yol($yol)) ?? [];

        return array_values(array_unique(array_map(fn (array $i): string => implode(' ', $i['kelimeler']), $blok)));
    }

    /** @return list<string> */
    private static function yol(string $yol): array
    {
        return array_values(array_filter(explode(' ', $yol), fn (string $k): bool => $k !== ''));
    }

    /**
     * @param  list<array{kelimeler: list<string>, cocuklar: ?array}>  $blok
     * @param  list<string>  $yol
     * @return list<string>
     */
    private static function bul(array $blok, array $yol): array
    {
        $sonuc = [];

        foreach ($blok as $ifade) {
            $k = $ifade['kelimeler'];
            $n = min(count($k), count($yol));

            if (array_slice($k, 0, $n) !== array_slice($yol, 0, $n)) {
                continue;
            }

            if (count($k) < count($yol)) {
                /* İfade yolun başı: kalan yol çocuklarda. */
                if ($ifade['cocuklar'] !== null) {
                    array_push($sonuc, ...self::bul($ifade['cocuklar'], array_slice($yol, count($k))));
                }
            } elseif (count($k) === count($yol)) {
                /* Tam eşleşen blok: yaprak çocuklar değerdir. */
                foreach ($ifade['cocuklar'] ?? [] as $c) {
                    if ($c['cocuklar'] === null) {
                        $sonuc[] = implode(' ', $c['kelimeler']);
                    }
                }
            } else {
                /* İfade yoldan uzun: kalan kelimeler değerdir (address A/P;). */
                $sonuc[] = implode(' ', array_slice($k, count($yol)));
            }
        }

        return $sonuc;
    }

    /**
     * @param  list<array{kelimeler: list<string>, cocuklar: ?array}>  $blok
     * @param  list<string>  $yol
     */
    private static function blokBul(array $blok, array $yol): ?array
    {
        if ($yol === []) {
            return $blok;
        }

        foreach ($blok as $ifade) {
            $k = $ifade['kelimeler'];

            if ($ifade['cocuklar'] !== null && count($k) <= count($yol) && array_slice($yol, 0, count($k)) === $k) {
                $bulunan = self::blokBul($ifade['cocuklar'], array_slice($yol, count($k)));

                if ($bulunan !== null) {
                    return $bulunan;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $simgeler
     * @return list<array{kelimeler: list<string>, cocuklar: ?array}>
     */
    private static function blok(array $simgeler, int &$i): array
    {
        $blok = [];
        $kelimeler = [];

        while ($i < count($simgeler)) {
            $s = $simgeler[$i++];

            if ($s === '}') {
                break;
            }

            if ($s === '{' || $s === ';') {
                $cocuklar = $s === '{' ? self::blok($simgeler, $i) : null;

                /* inactive: ile kapatılmış ifade cihazda çalışmaz: yok sayılır. */
                if ($kelimeler !== [] && ! in_array($kelimeler[0], ['inactive:', 'deactivate'], true)) {
                    if (in_array($kelimeler[0], ['protect:', 'replace:', 'active:'], true)) {
                        array_shift($kelimeler);
                    }
                    $blok[] = ['kelimeler' => $kelimeler, 'cocuklar' => $cocuklar];
                }
                $kelimeler = [];

                continue;
            }

            $kelimeler[] = $s;
        }

        return $blok;
    }

    /**
     * Yorumları atıp simgelere böler: kelimeler, tırnaklı dizgeler (tırnaksız),
     * `{`, `}`, `;`. `#` ile başlayan satır sonu yorumu (## SECRET-DATA) ve
     * `/* … *\/` atılır.
     *
     * @return list<string>
     */
    private static function simgeler(string $metin): array
    {
        $metin = (string) preg_replace('~/\*.*?\*/~s', ' ', str_replace("\r", '', $metin));
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|#[^\n]*|[{};]|[^\s{};"#]+/', $metin, $m, PREG_SET_ORDER);

        $simgeler = [];
        foreach ($m as $eslesme) {
            if (isset($eslesme[1]) && $eslesme[1] !== '' || str_starts_with($eslesme[0], '"')) {
                $simgeler[] = stripcslashes($eslesme[1] ?? '');
            } elseif (! str_starts_with($eslesme[0], '#')) {
                $simgeler[] = $eslesme[0];
            }
        }

        return $simgeler;
    }
}
