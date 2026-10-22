<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JunosImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Junos görüntüsünün kaydı ve TFTP dizinindeki dosyası birlikte.
 *
 * Dosya `junos` diskinde (TFTP servisinin hacmi). Aynı dosyayı birden çok
 * satır gösterebilir (EX-4500 ile EX-4550 gibi): dosya ancak başka satır
 * kullanmıyorsa silinir ya da yeniden adlandırılır.
 *
 * Yükleme önce gizli geçici adla yazılır, bitince yerine taşınır: TFTP o
 * sırada isteyen switch'e yarım dosya vermesin.
 */
class JunosImageService
{
    private function disk(): Filesystem
    {
        return Storage::disk('junos');
    }

    /** Dosya TFTP dizinindeyse boyutu (okunur), yoksa null. */
    public function boyut(string $dosya): ?string
    {
        return $this->disk()->exists($dosya) ? Number::fileSize($this->disk()->size($dosya), 1) : null;
    }

    /** @param  array{model: string, surum: string, dosya: string, sira: int}  $veri */
    public function ekle(array $veri, ?UploadedFile $yuklenen): JunosImage
    {
        if ($yuklenen) {
            $this->yaz($yuklenen, $veri['dosya']);
        }

        return JunosImage::create($veri);
    }

    /**
     * Ad değiştiyse ve yeni dosya yüklenmediyse eski dosya yeni ada taşınır
     * (yeni adda zaten bir dosya yoksa ve eskisini başka satır kullanmıyorsa).
     *
     * @param  array{model: string, surum: string, dosya: string, sira: int}  $veri
     */
    public function guncelle(JunosImage $image, array $veri, ?UploadedFile $yuklenen): void
    {
        $eski = $image->dosya;
        $yeni = $veri['dosya'];

        if ($yuklenen) {
            $this->yaz($yuklenen, $yeni);
        } elseif ($eski !== $yeni && ! $this->baskasiKullaniyor($image, $eski)
            && $this->disk()->exists($eski) && ! $this->disk()->exists($yeni)) {
            $this->disk()->move($eski, $yeni);
        }

        $image->update($veri);

        if ($yuklenen && $eski !== $yeni && ! $this->baskasiKullaniyor($image, $eski)) {
            $this->disk()->delete($eski);
        }
    }

    public function sil(JunosImage $image): void
    {
        $image->delete();

        if (! $this->baskasiKullaniyor($image, $image->dosya)) {
            $this->disk()->delete($image->dosya);
        }
    }

    private function yaz(UploadedFile $yuklenen, string $dosya): void
    {
        $gecici = '.yukleniyor-'.Str::random(16);

        try {
            $this->disk()->putFileAs('', $yuklenen, $gecici);
            $this->disk()->move($gecici, $dosya);
        } finally {
            $this->disk()->delete($gecici);
        }
    }

    private function baskasiKullaniyor(JunosImage $image, string $dosya): bool
    {
        return JunosImage::query()->whereKeyNot($image->getKey())->where('dosya', $dosya)->exists();
    }
}
