<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Hesap simgesindeki baş harfler.
 *
 * KURAL BÜTÜN PANELLERDE AYNI: ilk iki KELİMENİN baş harfi ("Sınama Ağ"
 * → "SA"); adın ilk iki HARFİ ("SI") alınmaz.
 *
 * Aynı kişi aynı simgeyi her panelde görmeli: simge bir kimlik işareti ve
 * panelden panele değişince "burada başka biri miyim" sorusunu doğuruyor.
 *
 * Üst çubuğu PHP bileşeni olan panellerde bu kural
 * `App\View\Components\Nav\Topbar::initialsFor()` içinde durur; burada üst
 * çubuk anonim bir Blade bileşeni — mantık ortak bir yere alındı ki ikisi
 * ayrışmasın.
 */
final class Initials
{
    public static function al(?string $ad): string
    {
        if ($ad === null || trim($ad) === '') {
            return '?';
        }

        return Str::of($ad)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $parca): string => Str::upper(Str::substr($parca, 0, 1)))
            ->implode('');
    }
}
