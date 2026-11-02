<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Setup\ConfigSablonRequest;
use App\Models\ConfigSablonu;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Config şablonları (Kurulum sayfası). Kapılar rotada: görmek ve indirmek
 * junos:view, eklemek/düzenlemek/silmek junos:create/update/delete — Junos
 * görüntüleriyle aynı kişiler.
 */
class ConfigSablonController extends Controller
{
    public function __construct(private ActivityRecorder $kayit) {}

    public function store(ConfigSablonRequest $request): RedirectResponse
    {
        $veri = $request->payload();

        if (! $request->filled('sira')) {
            $veri['sira'] = (int) ConfigSablonu::query()->max('sira') + 10;
        }

        $sablon = ConfigSablonu::create($veri);
        $this->kayit->kaydet('sablon_ekle', "Config şablonu eklendi: {$sablon->ad} · {$sablon->dosya}", $sablon);

        return redirect()->route('setup.index')->with('status', "{$sablon->ad} eklendi.");
    }

    public function update(ConfigSablonRequest $request, ConfigSablonu $sablon): RedirectResponse
    {
        $eski = $sablon->ad;
        $veri = $request->payload();
        $sablon->update($veri);

        $this->kayit->kaydet('sablon_duzenle', "Config şablonu güncellendi: {$eski}".($eski !== $sablon->ad ? " → {$sablon->ad}" : '')
            .(isset($veri['metin']) ? " · yeni dosya: {$sablon->dosya}" : ''), $sablon);

        return redirect()->route('setup.index')->with('status', "{$sablon->ad} güncellendi.");
    }

    public function destroy(ConfigSablonu $sablon): RedirectResponse
    {
        $sablon->delete();
        $this->kayit->kaydet('sablon_sil', "Config şablonu silindi: {$sablon->ad} · {$sablon->dosya}");

        return redirect()->route('setup.index')->with('status', "{$sablon->ad} silindi.");
    }

    public function download(ConfigSablonu $sablon): Response
    {
        $this->kayit->kaydet('sablon_indir', "Config şablonu indirildi: {$sablon->ad}", $sablon);

        return response($sablon->metin, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.addcslashes($sablon->dosya, '"\\').'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
