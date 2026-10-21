<?php

declare(strict_types=1);

namespace App\Konsol\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Konsol\Contracts\YapilandirmaKaynagi;
use App\Konsol\Http\Requests\CiktiRequest;
use App\Konsol\Http\Requests\OlayRequest;
use App\Konsol\Models\KonsolGirdisi;
use App\Konsol\Models\KonsolIsi;
use App\Konsol\Models\KonsolPc;
use App\Konsol\Services\AjanIsi;
use App\Models\ApiToken;
use App\Services\ActivityRecorder;
use App\Services\Api\ApiTokenContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Sahadaki ajanın konuştuğu uçlar (can:konsol:ajan). Ajan YALNIZ kendi
 * PC'sine atanmış AÇIK işleri görür; kapalı işe yazdığı her şey 409 alır
 * (ajan bunu "iş kapatıldı" diye okur ve durur).
 */
class AjanApiController extends Controller
{
    public function __construct(private ApiTokenContext $baglam) {}

    public function isler(AjanIsi $hazirlayici): JsonResponse
    {
        $pc = $this->pc();
        $isler = $pc === null ? collect() : $pc->isler()->acik()->with('pc')->orderBy('id')->get();

        return response()->json(['data' => $isler->map(fn (KonsolIsi $is): array => $hazirlayici->hazirla($is))->values()]);
    }

    public function olay(OlayRequest $request, KonsolIsi $is, ActivityRecorder $kayit): JsonResponse
    {
        $this->benimAcikIsim($is);
        $asama = $request->string('asama')->value();
        $mesaj = $request->filled('mesaj') ? mb_substr($request->string('mesaj')->value(), 0, 2000) : null;

        $is->olaylar()->create(['asama' => $asama, 'mesaj' => $mesaj, 'kaynak' => 'Ajan']);

        match ($asama) {
            'tamam', 'hata' => $is->kapat($asama, $mesaj),
            'basladi' => $is->forceFill(['durum' => 'calisiyor', 'asama' => $asama, 'ajan_token_id' => $this->ajan()->id,
                'son_mesaj' => $mesaj === null ? null : mb_substr($mesaj, 0, 500)])->save(),
            default => $is->forceFill(['asama' => $asama, 'son_mesaj' => $mesaj === null ? $is->son_mesaj : mb_substr($mesaj, 0, 500)])->save(),
        };

        if (in_array($asama, ['tamam', 'hata'], true)) {
            $kayit->kaydet('konsol_is_'.$asama, "Konsol kurulumu {$asama}: {$is->ad} · {$is->goruntu_etiket}".($mesaj ? " · {$mesaj}" : ''), $is);
        }

        return response()->json(['durum' => $is->durum]);
    }

    /**
     * Çıktıyı ekler, panelden yazılmış ama ajana gitmemiş girdileri verir.
     * İşin çıktısı sınırı aşınca saklanmaz (ekranda not düşülür); kurulum
     * sürer.
     */
    public function cikti(CiktiRequest $request, KonsolIsi $is): JsonResponse
    {
        $this->benimAcikIsim($is);
        $metin = (string) $request->input('metin', '');
        $sinir = (int) config('konsol.cikti_siniri');

        $girdiler = DB::transaction(function () use ($is, $metin, $sinir): array {
            if ($metin !== '' && $is->cikti_bayt < $sinir) {
                $is->ciktilar()->create(['metin' => $metin]);
                $is->increment('cikti_bayt', strlen($metin));

                if ($is->cikti_bayt >= $sinir) {
                    $is->ciktilar()->create(['metin' => "\r\n[panel: çıktı sınırına ulaşıldı, bundan sonrası saklanmıyor]\r\n"]);
                }
            }

            $bekleyen = $is->girdiler()->whereNull('gonderildi_at')->orderBy('id')->lockForUpdate()->get();
            KonsolGirdisi::query()->whereKey($bekleyen->modelKeys())->update(['gonderildi_at' => now()]);

            return $bekleyen->pluck('metin')->all();
        });

        return response()->json(['girdiler' => $girdiler, 'iptal' => false]);
    }

    /** Yedeğin metni yalnız işi üstlenen ajana ve iş açıkken verilir. */
    public function yapilandirma(KonsolIsi $is, YapilandirmaKaynagi $kaynak, ActivityRecorder $kayit): Response
    {
        $this->benimAcikIsim($is);

        if ($is->yapilandirma_turu === 'yok' || $is->yedek_cihaz === null) {
            throw new ConflictHttpException('Bu işte yapılandırma yüklenmiyor.');
        }

        $metin = $kaynak->icerik($is->yedek_cihaz, $is->yapilandirma_turu === 'yedek' ? $is->yedek_dosya : null)
            ?? throw new ConflictHttpException("{$is->yedek_cihaz} için yedek bulunamadı.");

        $kayit->kaydet('konsol_yapilandirma', "Konsol: yapılandırma ajana verildi: {$is->ad} · {$is->yapilandirmaOzeti()} · {$this->ajan()->name}", $is);

        return response($metin, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Ajanın kendisi: PC kurulurken panelden kendi jetonuyla indirir. */
    public function indir(string $platform): BinaryFileResponse
    {
        $tanim = config("konsol.ajan_dosyalari.{$platform}") ?? abort(404);
        $yol = rtrim((string) config('konsol.ajan_dizini'), '/').'/'.$tanim['dosya'];
        abort_unless(is_file($yol), 404);

        return response()->download($yol, $tanim['dosya'], ['Content-Type' => 'application/octet-stream']);
    }

    private function benimAcikIsim(KonsolIsi $is): void
    {
        $pc = $this->pc();

        if ($pc === null || (int) $is->pc_id !== (int) $pc->id || ! $is->acikMi()) {
            throw new ConflictHttpException('İş bu ajanda değil ya da kapalı.');
        }
    }

    private function pc(): ?KonsolPc
    {
        return KonsolPc::query()->where('jeton_id', $this->ajan()->id)->first();
    }

    private function ajan(): ApiToken
    {
        return $this->baglam->get() ?? abort(401);
    }
}
