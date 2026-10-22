<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Controllers;

use App\ConsoleModule\Http\Requests\PcRequest;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Remote\RemoteError;
use App\ConsoleModule\Remote\SitePc;
use App\Http\Controllers\Controller;
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
        'test' => 'Bağlantı sınandı',
        'install' => 'Ajan kuruldu/güncellendi',
        'uninstall' => 'Ajan kaldırıldı',
        'status' => 'Durum okundu',
    ];

    public function __construct(private ActivityRecorder $kayit) {}

    public function store(PcRequest $request): RedirectResponse
    {
        $pc = ConsolePc::create($request->payload());
        $this->kayit->kaydet('konsol_pc_ekle', "Konsol PC'si eklendi: {$pc->ad} ({$pc->platform}, {$pc->adres}:{$pc->port})", $pc);

        return redirect()->route('console.index')->with('status', "{$pc->ad} eklendi. \"Sına\" ile bağlantıyı deneyin.");
    }

    public function update(PcRequest $request, ConsolePc $pc): RedirectResponse
    {
        $veri = $request->payload();

        /* Adres değişince eski anahtar izi geçersiz: yeni makine yeniden tanınır. */
        if ($veri['adres'] !== $pc->adres || $veri['port'] !== $pc->port) {
            $veri['ssh_parmak_izi'] = null;
        }

        $pc->update($veri);
        $this->kayit->kaydet('konsol_pc_duzenle', "Konsol PC'si güncellendi: {$pc->ad} ({$pc->adres}:{$pc->port})"
            .(isset($veri['parola']) ? ' · parola değişti' : ''), $pc);

        return redirect()->route('console.index')->with('status', "{$pc->ad} güncellendi.");
    }

    public function destroy(ConsolePc $pc): RedirectResponse
    {
        if ($pc->isler()->acik()->exists()) {
            return redirect()->route('console.index')->with('error', "{$pc->ad} üzerinde açık iş var; önce kapatın.");
        }

        if ($pc->ajanKuruluMu()) {
            return redirect()->route('console.index')->with('error', "{$pc->ad} üzerinde ajan kurulu; önce \"Kaldır\" ile PC'den silin.");
        }

        $pc->jeton?->revoke();
        $ad = "{$pc->ad} ({$pc->adres})";
        $pc->delete();
        $this->kayit->kaydet('konsol_pc_sil', "Konsol PC'si silindi: {$ad}; ajan jetonu iptal edildi, işleri geçmişten silindi");

        return redirect()->route('console.index')->with('status', "{$ad} silindi.");
    }

    public function action(ConsolePc $pc, string $action, SitePc $uzak): RedirectResponse
    {
        abort_unless(isset(self::ISLEMLER[$action]), 404);

        try {
            $sonuc = match ($action) {
                'test' => ['metin' => $uzak->sina($pc)],
                'install' => ['metin' => $uzak->kur($pc, Auth::user())],
                'uninstall' => ['metin' => $uzak->kaldir($pc)],
                'status' => ['durum' => $uzak->durum($pc)],
            };
        } catch (RemoteError $e) {
            $this->kayit->kaydet('konsol_pc_hata', "{$pc->ad}: ".self::ISLEMLER[$action].' — başarısız: '.$e->getMessage(), $pc);

            return redirect()->route('console.index')->with('pcSonuc', ['pc' => $pc->ad, 'hata' => true, 'metin' => $e->getMessage()]);
        }

        if ($action !== 'status') {
            $this->kayit->kaydet('konsol_pc_'.['test' => 'sina', 'install' => 'kur', 'uninstall' => 'kaldir'][$action], "{$pc->ad}: ".self::ISLEMLER[$action], $pc);
        }

        return redirect()->route('console.index')->with('pcSonuc', ['pc' => $pc->ad, 'hata' => false] + $sonuc);
    }
}
