<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Setup\JunosImageRequest;
use App\Models\JunosImage;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;

/**
 * Kurulum sayfasındaki Junos görüntülerinin yönetimi: yeni görüntü panelden
 * girilir, koda dokunmadan. Kapılar rotada:
 * junos:create / junos:update / junos:delete. Her değişiklik denetim
 * kaydına eski ve yeni dosya adıyla yazılır.
 */
class JunosImageController extends Controller
{
    public function __construct(private ActivityRecorder $kayit) {}

    public function store(JunosImageRequest $request): RedirectResponse
    {
        $veri = $request->payload();

        if (! $request->filled('sira')) {
            $veri['sira'] = (int) JunosImage::query()->max('sira') + 10;
        }

        $goruntu = JunosImage::create($veri);

        $this->kayit->kaydet('junos_ekle', "Junos görüntüsü eklendi: {$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}", $goruntu);

        return redirect()->route('kurulum.index')->with('status', "{$goruntu->model} eklendi.");
    }

    public function update(JunosImageRequest $request, JunosImage $goruntu): RedirectResponse
    {
        $eski = "{$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}";

        $goruntu->update($request->payload());

        $this->kayit->kaydet('junos_duzenle', "Junos görüntüsü güncellendi: {$eski} → {$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}", $goruntu);

        return redirect()->route('kurulum.index')->with('status', "{$goruntu->model} güncellendi.");
    }

    public function destroy(JunosImage $goruntu): RedirectResponse
    {
        $ad = "{$goruntu->model} {$goruntu->surum} · {$goruntu->dosya}";

        $goruntu->delete();

        $this->kayit->kaydet('junos_sil', "Junos görüntüsü silindi: {$ad}");

        return redirect()->route('kurulum.index')->with('status', "{$goruntu->model} silindi.");
    }
}
