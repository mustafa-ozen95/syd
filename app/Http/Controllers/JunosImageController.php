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

        $image = JunosImage::create($veri);

        $this->kayit->kaydet('junos_ekle', "Junos görüntüsü eklendi: {$image->model} {$image->surum} · {$image->dosya}", $image);

        return redirect()->route('setup.index')->with('status', "{$image->model} eklendi.");
    }

    public function update(JunosImageRequest $request, JunosImage $image): RedirectResponse
    {
        $eski = "{$image->model} {$image->surum} · {$image->dosya}";

        $image->update($request->payload());

        $this->kayit->kaydet('junos_duzenle', "Junos görüntüsü güncellendi: {$eski} → {$image->model} {$image->surum} · {$image->dosya}", $image);

        return redirect()->route('setup.index')->with('status', "{$image->model} güncellendi.");
    }

    public function destroy(JunosImage $image): RedirectResponse
    {
        $ad = "{$image->model} {$image->surum} · {$image->dosya}";

        $image->delete();

        $this->kayit->kaydet('junos_sil', "Junos görüntüsü silindi: {$ad}");

        return redirect()->route('setup.index')->with('status', "{$image->model} silindi.");
    }
}
