<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Collection;
use SplFileInfo;

/**
 * Switch yedeklerinin durduğu dizin.
 *
 * BU SINIF YAZMAZ. Yedekleri cihazlar FTP ile bırakıyor; panelin işi
 * yalnızca okumak. Silme, taşıma, yeniden adlandırma ucu YOK — ve olmayacak:
 * buradaki dosyalar cihaz yedeklerinin çoğu zaman tek kopyası ve bir arıza
 * anında elde kalan yegâne yapılandırma kaydı.
 * Dizin konteynere de salt-okunur bağlanır; kod yanlışlıkla yazmaya kalksa
 * bile dosya sistemi izin vermez.
 *
 * KÖK DIŞINA ÇIKILMAZ. Gelen her yol `realpath` ile çözülür ve kökün altında
 * olduğu doğrulanır; sembolik bağ ya da `..` ile dışarı çıkmak mümkün değil.
 */
class BackupStore
{
    /** Listede hiç görünmeyecek adlar. */
    private const GIZLI = ['.', '..'];

    public function __construct(private readonly string $kok) {}

    public static function yapilandirmadan(): self
    {
        return new self((string) config('backup.root'));
    }

    public function kok(): string
    {
        return $this->kok;
    }

    public function erisilebilirMi(): bool
    {
        return is_dir($this->kok) && is_readable($this->kok);
    }

    /**
     * Göreli yolu güvenli mutlak yola çevirir.
     *
     * Kök dışına çıkan, var olmayan ya da gizli bir ada denk gelen her yol
     * için null döner — çağıran taraf bunu 404 sayar.
     */
    public function coz(string $gorece): ?string
    {
        $gorece = str_replace('\\', '/', $gorece);
        $gorece = ltrim($gorece, '/');

        foreach (explode('/', $gorece) as $parca) {
            if ($parca !== '' && $this->gizliMi($parca)) {
                return null;
            }
        }

        $kokGercek = realpath($this->kok);
        $tam = realpath($this->kok.'/'.$gorece);

        if ($kokGercek === false || $tam === false) {
            return null;
        }

        if ($tam !== $kokGercek && ! str_starts_with($tam, $kokGercek.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $tam;
    }

    /** Mutlak yolu köke göre göreli hâle getirir (bağlantı üretmek için). */
    public function gorece(string $tam): string
    {
        $kokGercek = (string) realpath($this->kok);

        return ltrim(str_replace($kokGercek, '', $tam), DIRECTORY_SEPARATOR);
    }

    /**
     * Kökteki cihaz klasörleri.
     *
     * Her klasörün yanında kaç yedek tuttuğu ve EN SON ne zaman yedek aldığı
     * durur. Son tarih asıl bilgidir: bir cihaz yedek göndermeyi bıraktığında
     * bunu ancak buradan görebilirsiniz, klasör hâlâ dolu görünür.
     *
     * @return Collection<int, array{ad: string, adet: int, son: ?int, boyut: int}>
     */
    public function klasorler(): Collection
    {
        if (! $this->erisilebilirMi()) {
            return collect();
        }

        $klasorler = collect();

        foreach (scandir($this->kok) ?: [] as $ad) {
            if ($this->gizliMi($ad)) {
                continue;
            }

            $yol = $this->kok.'/'.$ad;

            if (! is_dir($yol)) {
                continue;
            }

            $adet = 0;
            $son = null;
            $boyut = 0;

            foreach (scandir($yol) ?: [] as $dosyaAdi) {
                if ($this->gizliMi($dosyaAdi) || ! is_file($yol.'/'.$dosyaAdi)) {
                    continue;
                }

                $adet++;
                $boyut += (int) filesize($yol.'/'.$dosyaAdi);
                $zaman = BackupName::zaman($dosyaAdi) ?? filemtime($yol.'/'.$dosyaAdi);

                if ($zaman !== false && ($son === null || $zaman > $son)) {
                    $son = $zaman;
                }
            }

            $klasorler->push(['ad' => $ad, 'adet' => $adet, 'son' => $son, 'boyut' => $boyut]);
        }

        return $klasorler->sortBy('ad', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Bir klasördeki yedekler — EN YENİSİ ÜSTTE.
     *
     * Sıralama dosya adındaki damgaya göre; adında damga olmayan dosya için
     * değiştirilme zamanına düşülür. Alfabetik sıra işe yaramıyordu: aynı
     * cihazın yıllar arayla alınmış yedekleri ad olarak yan yana geliyor, aranan hep
     * en yeni olduğu halde listenin ortasında kalıyordu.
     *
     * @return Collection<int, array{ad: string, yol: string, boyut: int, zaman: ?int, damgali: bool}>
     */
    public function dosyalar(string $klasorYolu, string $arama = ''): Collection
    {
        $tam = $this->coz($klasorYolu);

        if ($tam === null || ! is_dir($tam)) {
            return collect();
        }

        $dosyalar = collect();

        foreach (scandir($tam) ?: [] as $ad) {
            if ($this->gizliMi($ad) || ! is_file($tam.'/'.$ad)) {
                continue;
            }

            if ($arama !== '' && ! str_contains(mb_strtolower($ad), mb_strtolower($arama))) {
                continue;
            }

            $damga = BackupName::zaman($ad);

            $dosyalar->push([
                'ad' => $ad,
                'yol' => $this->gorece($tam.'/'.$ad),
                'boyut' => (int) filesize($tam.'/'.$ad),
                'zaman' => $damga ?? (filemtime($tam.'/'.$ad) ?: null),
                'damgali' => $damga !== null,
            ]);
        }

        return $dosyalar->sortByDesc(fn (array $d): int => $d['zaman'] ?? 0)->values();
    }

    /** İndirilecek dosya; yol kök dışındaysa ya da dosya değilse null. */
    public function dosya(string $gorece): ?SplFileInfo
    {
        $tam = $this->coz($gorece);

        return $tam !== null && is_file($tam) ? new SplFileInfo($tam) : null;
    }

    /** Depo toplamı: kaç cihaz, kaç dosya, ne kadar yer. */
    public function ozet(): array
    {
        $klasorler = $this->klasorler();

        return [
            'cihaz' => $klasorler->count(),
            'dosya' => (int) $klasorler->sum('adet'),
            'boyut' => (int) $klasorler->sum('boyut'),
            'son' => $klasorler->max('son'),
        ];
    }

    private function gizliMi(string $ad): bool
    {
        return in_array($ad, self::GIZLI, true) || str_starts_with($ad, '.');
    }
}
