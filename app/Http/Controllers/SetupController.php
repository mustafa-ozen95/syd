<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\JunosImage;
use App\Services\JunosImageService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\View\View;

/**
 * Junos TFTP yerel kurulum yönlendirmesi: model başına çalıştırılacak
 * `install --format` komutu. Liste veritabanında
 * ve yetkisi olan panelden yönetir (bkz. JunosImageController); komut
 * burada kurulur, görünüm hazır metni gösterir.
 */
class SetupController extends Controller
{
    public function index(JunosImageService $goruntuler): View
    {
        $yetki = [
            'ekle' => Gate::allows('junos:create'),
            'duzenle' => Gate::allows('junos:update'),
            'sil' => Gate::allows('junos:delete'),
        ];

        $hataliForm = (string) old('_form', '');
        $eskiGirdi = fn (): array => ['model' => old('model'), 'surum' => old('surum'), 'dosya' => old('dosya'), 'sira' => old('sira')];

        return view('setup.index', [
            'satirlar' => JunosImage::query()->sirali()->get()->map(fn (JunosImage $g): array => [
                'goruntu' => $g,
                'kimlik' => 'kurulum-'.$g->id,
                'komut' => $g->komut(),
                /* null: dosya TFTP dizininde yok, switch "dosya bulunamadı" alır. */
                'boyut' => $goruntuler->boyut($g->dosya),
                /* Doğrulama hatasından sonra pencere eski girdiyle açık gelir. */
                'acik' => $acik = $hataliForm === 'duzenle-'.$g->id,
                'deger' => $acik ? $eskiGirdi() : $g->only(['model', 'surum', 'dosya', 'sira']),
            ]),
            'tftp' => rtrim((string) config('junos.tftp'), '/'),
            'yuklemeSiniri' => Number::fileSize(config('junos.upload_max_mb') * 1024 * 1024),
            'yetki' => $yetki,
            'eylemVar' => $yetki['duzenle'] || $yetki['sil'],
            'konsolGorur' => Gate::allows('konsol:view'),
            'ekleAcik' => $hataliForm === 'ekle',
            'ekleDeger' => $hataliForm === 'ekle' ? $eskiGirdi() : [],
        ]);
    }
}
