<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Remote\RemoteError;
use App\ConsoleModule\Remote\SitePc;

/**
 * İş açmadan önce sahadaki hazırlık: switch loader'a indirilip disk
 * formatlandıktan sonra ortaya çıkan hataları önceden gösterir. Switch'e
 * hiçbir şey gönderilmez; yalnız PC'de komut çalışır.
 *
 *   PC'ye ulaşılıyor mu       (WinRM/SSH)
 *   konsol kablosu takılı mı  (portlar yeniden okunur)
 *   loader adresi boşta mı    (PC'den ping; ajan da kurmadan önce sorar)
 */
class OnKontrol
{
    public function __construct(private SitePc $uzak) {}

    /** @return list<array{durum: 'tamam'|'uyari'|'hata', metin: string}> */
    public function yap(ConsolePc $pc, string $port, ?string $adres): array
    {
        try {
            $portlar = $this->uzak->portlariAlgila($pc);
        } catch (RemoteError $e) {
            return [['durum' => 'hata', 'metin' => "PC'ye ulaşılamadı ({$pc->ad}): ".$e->getMessage()]];
        }

        $sonuc = [['durum' => 'tamam', 'metin' => "PC'ye ulaşıldı: {$pc->ad}."]];
        $sonuc[] = $this->kablo($pc->refresh(), $port, $portlar);

        if ($adres !== null) {
            $sonuc[] = $this->adres($pc, $adres);
        }

        return $sonuc;
    }

    /**
     * @param  list<string>  $portlar
     * @return array{durum: 'tamam'|'uyari'|'hata', metin: string}
     */
    private function kablo(ConsolePc $pc, string $port, array $portlar): array
    {
        if ($portlar === []) {
            return ['durum' => 'hata', 'metin' => 'PC\'de konsol kablosu (USB-seri) görünmüyor: kablo takılı mı?'];
        }
        if ($port === '') {
            return ['durum' => 'tamam', 'metin' => 'Konsol kablosu takılı: '.implode(', ', $portlar).'.'];
        }

        return in_array($port, $pc->portlar(), true)
            ? ['durum' => 'tamam', 'metin' => 'Seçilen konsol kablosu takılı: '.$pc->portEtiketi($port).'.']
            : ['durum' => 'hata', 'metin' => 'Seçilen konsol kablosu PC\'de görünmüyor. Takılı olanlar: '.implode(', ', $portlar).'. Kabloyu takın ya da formu yenileyip portu yeniden seçin.'];
    }

    /** @return array{durum: 'tamam'|'uyari'|'hata', metin: string} */
    private function adres(ConsolePc $pc, string $adres): array
    {
        try {
            $cevap = $this->uzak->pingCevapVeriyor($pc, $adres);
        } catch (RemoteError $e) {
            return ['durum' => 'uyari', 'metin' => "{$adres} sınanamadı: ".$e->getMessage()];
        }

        return $cevap
            ? ['durum' => 'hata', 'metin' => "{$adres} başka bir cihaz tarafından kullanılıyor (ping'e cevap veriyor). Ajan bu adresle kurmaz; başka bir adres verin."]
            : ['durum' => 'tamam', 'metin' => "{$adres} boşta (ping'e cevap yok)."];
    }
}
