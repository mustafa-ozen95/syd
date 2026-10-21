<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Kurulum\JunosGoruntusuRequest;
use App\Models\JunosGoruntusu;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;

/**
 * Kurulum sayfasındaki Junos görüntülerinin yönetimi: yeni görüntü panelden
 * girilir, koda dokunmadan. Kapılar rotada:
 * junos:create / junos:update / junos:delete. Her değişiklik denetim
 * kaydına eski ve yeni dosya adıyla yazılır.
 */
class JunosGoruntusuController extends Controller
{
    public function __construct(private ActivityRecorder $kayit) {}

    public function store(JunosGoruntusuRequest $request): RedirectResponse
    {
        $veri = $request->payload();

        if (! $request->filled('sira')) {
            $veri['sira'] = (int) JunosGoruntusu::query()->max('sira') + 10;
        }

        $goruntu = JunosGoruntusu::create($veri);

        $this->kayit->kaydet('junos_ekle', "Junos görüntüsü eklendi: {$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}", $goruntu);

        return redirect()->route('kurulum.index')->with('status', "{$goruntu->model} eklendi.");
    }

    public function update(JunosGoruntusuRequest $request, JunosGoruntusu $goruntu): RedirectResponse
    {
        $eski = "{$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}";

        $goruntu->update($request->payload());

        $this->kayit->kaydet('junos_duzenle', "Junos görüntüsü güncellendi: {$eski} → {$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}", $goruntu);

        return redirect()->route('kurulum.index')->with('status', "{$goruntu->model} güncellendi.");
    }

    public function destroy(JunosGoruntusu $goruntu): RedirectResponse
    {
        $ad = "{$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}";

        $goruntu->delete();

        $this->kayit->kaydet('junos_sil', "Junos görüntüsü silindi: {$ad}");

        return redirect()->route('kurulum.index')->with('status', "{$goruntu->model} silindi.");
    }
}
