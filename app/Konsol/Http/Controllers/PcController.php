<?php

declare(strict_types=1);

namespace App\Konsol\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Konsol\Http\Requests\PcRequest;
use App\Konsol\Models\KonsolPc;
use App\Konsol\Uzak\SahaPc;
use App\Konsol\Uzak\UzakHata;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Konsol PC'leri (can:konsol:update). Bağlantı ayarları ve PC'de yapılan her
 * işlem denetim kaydına yazılır; parola hiçbir yere yazılmaz.
 */
class PcController extends Controller
{
    private const ISLEMLER = [
        'sina' => 'Bağlantı sınandı',
        'kur' => 'Ajan kuruldu/güncellendi',
        'kaldir' => 'Ajan kaldırıldı',
        'durum' => 'Durum okundu',
    ];

    public function __construct(private ActivityRecorder $kayit) {}

    public function store(PcRequest $request): RedirectResponse
    {
        $pc = KonsolPc::create($request->payload());
        $this->kayit->kaydet('konsol_pc_ekle', "Konsol PC'si eklendi: {$pc->ad} ({$pc->platform}, {$pc->adres}:{$pc->port})", $pc);

        return redirect()->route('konsol.index')->with('status', "{$pc->ad} eklendi. \"Sına\" ile bağlantıyı deneyin.");
    }

    public function update(PcRequest $request, KonsolPc $pc): RedirectResponse
    {
        $veri = $request->payload();

        /* Adres değişince eski anahtar izi geçersiz: yeni makine yeniden tanınır. */
        if ($veri['adres'] !== $pc->adres || $veri['port'] !== $pc->port) {
            $veri['ssh_parmak_izi'] = null;
        }

        $pc->update($veri);
        $this->kayit->kaydet('konsol_pc_duzenle', "Konsol PC'si güncellendi: {$pc->ad} ({$pc->adres}:{$pc->port})"
            .(isset($veri['parola']) ? ' · parola değişti' : ''), $pc);

        return redirect()->route('konsol.index')->with('status', "{$pc->ad} güncellendi.");
    }

    public function destroy(KonsolPc $pc): RedirectResponse
    {
        if ($pc->isler()->acik()->exists()) {
            return redirect()->route('konsol.index')->with('error', "{$pc->ad} üzerinde açık iş var; önce kapatın.");
        }

        if ($pc->ajanKuruluMu()) {
            return redirect()->route('konsol.index')->with('error', "{$pc->ad} üzerinde ajan kurulu; önce \"Kaldır\" ile PC'den silin.");
        }

        $pc->jeton?->revoke();
        $ad = "{$pc->ad} ({$pc->adres})";
        $pc->delete();
        $this->kayit->kaydet('konsol_pc_sil', "Konsol PC'si silindi: {$ad}; ajan jetonu iptal edildi, işleri geçmişten silindi");

        return redirect()->route('konsol.index')->with('status', "{$ad} silindi.");
    }

    public function islem(KonsolPc $pc, string $islem, SahaPc $uzak): RedirectResponse
    {
        abort_unless(isset(self::ISLEMLER[$islem]), 404);

        try {
            $sonuc = match ($islem) {
                'sina' => ['metin' => $uzak->sina($pc)],
                'kur' => ['metin' => $uzak->kur($pc, Auth::user())],
                'kaldir' => ['metin' => $uzak->kaldir($pc)],
                'durum' => ['durum' => $uzak->durum($pc)],
            };
        } catch (UzakHata $e) {
            $this->kayit->kaydet('konsol_pc_hata', "{$pc->ad}: ".self::ISLEMLER[$islem].' — başarısız: '.$e->getMessage(), $pc);

            return redirect()->route('konsol.index')->with('pcSonuc', ['pc' => $pc->ad, 'hata' => true, 'metin' => $e->getMessage()]);
        }

        if ($islem !== 'durum') {
            $this->kayit->kaydet('konsol_pc_'.$islem, "{$pc->ad}: ".self::ISLEMLER[$islem], $pc);
        }

        return redirect()->route('konsol.index')->with('pcSonuc', ['pc' => $pc->ad, 'hata' => false] + $sonuc);
    }
}
