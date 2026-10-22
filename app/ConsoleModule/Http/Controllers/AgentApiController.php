<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Controllers;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Http\Requests\EventRequest;
use App\ConsoleModule\Http\Requests\OutputRequest;
use App\ConsoleModule\Models\ConsoleInput;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\AgentJob;
use App\Http\Controllers\Controller;
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
class AgentApiController extends Controller
{
    public function __construct(private ApiTokenContext $baglam) {}

    public function jobs(AgentJob $hazirlayici): JsonResponse
    {
        $pc = $this->pc();
        $isler = $pc === null ? collect() : $pc->isler()->acik()->with('pc')->orderBy('id')->get();

        return response()->json(['data' => $isler->map(fn (ConsoleJob $job): array => $hazirlayici->hazirla($job))->values()]);
    }

    public function event(EventRequest $request, ConsoleJob $job, ActivityRecorder $kayit): JsonResponse
    {
        $this->benimAcikIsim($job);
        $asama = $request->string('stage')->value();
        $mesaj = $request->filled('message') ? mb_substr($request->string('message')->value(), 0, 2000) : null;

        $job->olaylar()->create(['asama' => $asama, 'mesaj' => $mesaj, 'kaynak' => 'Ajan']);

        match ($asama) {
            'tamam', 'hata' => $job->kapat($asama, $mesaj),
            'basladi' => $job->forceFill(['durum' => 'calisiyor', 'asama' => $asama, 'ajan_token_id' => $this->ajan()->id,
                'son_mesaj' => $mesaj === null ? null : mb_substr($mesaj, 0, 500)])->save(),
            default => $job->forceFill(['asama' => $asama, 'son_mesaj' => $mesaj === null ? $job->son_mesaj : mb_substr($mesaj, 0, 500)])->save(),
        };

        if (in_array($asama, ['tamam', 'hata'], true)) {
            $kayit->kaydet('konsol_is_'.$asama, "Konsol kurulumu {$asama}: {$job->ad} · {$job->goruntu_etiket}".($mesaj ? " · {$mesaj}" : ''), $job);
        }

        return response()->json(['status' => $job->durum]);
    }

    /**
     * Çıktıyı ekler, panelden yazılmış ama ajana gitmemiş girdileri verir.
     * İşin çıktısı sınırı aşınca saklanmaz (ekranda not düşülür); kurulum
     * sürer.
     */
    public function output(OutputRequest $request, ConsoleJob $job): JsonResponse
    {
        $this->benimAcikIsim($job);
        $metin = (string) $request->input('text', '');
        $sinir = (int) config('console.output_limit');

        $girdiler = DB::transaction(function () use ($job, $metin, $sinir): array {
            if ($metin !== '' && $job->cikti_bayt < $sinir) {
                $job->ciktilar()->create(['metin' => $metin]);
                $job->increment('cikti_bayt', strlen($metin));

                if ($job->cikti_bayt >= $sinir) {
                    $job->ciktilar()->create(['metin' => "\r\n[panel: çıktı sınırına ulaşıldı, bundan sonrası saklanmıyor]\r\n"]);
                }
            }

            $bekleyen = $job->girdiler()->whereNull('gonderildi_at')->orderBy('id')->lockForUpdate()->get();
            ConsoleInput::query()->whereKey($bekleyen->modelKeys())->update(['gonderildi_at' => now()]);

            return $bekleyen->pluck('metin')->all();
        });

        return response()->json(['inputs' => $girdiler, 'cancelled' => false]);
    }

    /** Yedeğin metni yalnız işi üstlenen ajana ve iş açıkken verilir. */
    public function configuration(ConsoleJob $job, ConfigurationSource $kaynak, ActivityRecorder $kayit): Response
    {
        $this->benimAcikIsim($job);

        if ($job->yapilandirma_turu === 'yok' || $job->yedek_cihaz === null) {
            throw new ConflictHttpException('Bu işte yapılandırma yüklenmiyor.');
        }

        $metin = $kaynak->icerik($job->yedek_cihaz, $job->yapilandirma_turu === 'yedek' ? $job->yedek_dosya : null)
            ?? throw new ConflictHttpException("{$job->yedek_cihaz} için yedek bulunamadı.");

        $kayit->kaydet('konsol_yapilandirma', "Konsol: yapılandırma ajana verildi: {$job->ad} · {$job->yapilandirmaOzeti()} · {$this->ajan()->name}", $job);

        return response($metin, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Ajanın kendisi: PC kurulurken panelden kendi jetonuyla indirir. */
    public function download(string $platform): BinaryFileResponse
    {
        $tanim = config("console.agent_files.{$platform}") ?? abort(404);
        $yol = rtrim((string) config('console.agent_dir'), '/').'/'.$tanim['dosya'];
        abort_unless(is_file($yol), 404);

        return response()->download($yol, $tanim['dosya'], ['Content-Type' => 'application/octet-stream']);
    }

    private function benimAcikIsim(ConsoleJob $job): void
    {
        $pc = $this->pc();

        if ($pc === null || (int) $job->pc_id !== (int) $pc->id || ! $job->acikMi()) {
            throw new ConflictHttpException('İş bu ajanda değil ya da kapalı.');
        }
    }

    private function pc(): ?ConsolePc
    {
        return ConsolePc::query()->where('jeton_id', $this->ajan()->id)->first();
    }

    private function ajan(): ApiToken
    {
        return $this->baglam->get() ?? abort(401);
    }
}
