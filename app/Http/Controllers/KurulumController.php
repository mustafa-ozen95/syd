<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\JunosGoruntusu;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Junos TFTP yerel kurulum yönlendirmesi: model başına çalıştırılacak
 * `install --format` komutu. Liste veritabanında
 * ve yetkisi olan panelden yönetir (bkz. JunosGoruntusuController); komut
 * burada kurulur, görünüm hazır metni gösterir.
 */
class KurulumController extends Controller
{
    public function index(): View
    {
        $yetki = [
            'ekle' => Gate::allows('junos:create'),
            'duzenle' => Gate::allows('junos:update'),
            'sil' => Gate::allows('junos:delete'),
        ];

        $hataliForm = (string) old('_form', '');
        $eskiGirdi = fn (): array => ['model' => old('model'), 'surum' => old('surum'), 'dosya' => old('dosya'), 'sira' => old('sira')];

        return view('kurulum.index', [
            'satirlar' => JunosGoruntusu::query()->sirali()->get()->map(fn (JunosGoruntusu $g): array => [
                'goruntu' => $g,
                'kimlik' => 'kurulum-'.$g->id,
                'komut' => $g->komut(),
                /* Doğrulama hatasından sonra pencere eski girdiyle açık gelir. */
                'acik' => $acik = $hataliForm === 'duzenle-'.$g->id,
                'deger' => $acik ? $eskiGirdi() : $g->only(['model', 'surum', 'dosya', 'sira']),
            ]),
            'tftp' => rtrim((string) config('junos.tftp'), '/'),
            'yetki' => $yetki,
            'eylemVar' => $yetki['duzenle'] || $yetki['sil'],
            'konsolGorur' => Gate::allows('konsol:view'),
            'ekleAcik' => $hataliForm === 'ekle',
            'ekleDeger' => $hataliForm === 'ekle' ? $eskiGirdi() : [],
        ]);
    }
}
