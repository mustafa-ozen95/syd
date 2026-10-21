<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Controllers;

use App\ConsoleModule\Http\Requests\InputRequest;
use App\ConsoleModule\Http\Requests\IsRequest;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Services\ConsolePage;
use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Konsol kurulum ekranı. Kapılar rotada: konsol:view / create / update /
 * delete. İş açmak AÇIK ONAYDIR: ajan yalnız panelde açılmış işi uygular.
 * Her değişiklik ve konsola elle yazılan her satır denetim kaydına yazılır.
 */
class ConsoleController extends Controller
{
    public function __construct(private ActivityRecorder $kayit) {}

    public function index(ConsolePage $sayfa): View
    {
        return view('konsol::index', $sayfa->liste());
    }

    public function show(ConsoleJob $is, ConsolePage $sayfa): View
    {
        return view('konsol::is', $sayfa->is($is));
    }

    public function store(IsRequest $request): RedirectResponse
    {
        $pc = $request->pc();
        $is = ConsoleJob::create($request->payload() + ['olusturan_id' => Auth::id()]);
        $is->olaylar()->create(['asama' => 'basladi', 'mesaj' => 'İş açıldı.', 'kaynak' => Auth::user()?->name]);

        $this->kayit->kaydet('konsol_is_ac', "Konsol işi açıldı: {$is->ad} · PC: {$pc->ad} · {$is->goruntu_etiket} · {$is->adresOzeti()} · {$is->yapilandirmaOzeti()}"
            .($is->root_parola !== null ? ' · root parolası verildi' : ''), $is);

        return redirect()->route('konsol.show', $is)
            ->with('status', "İş açıldı; ajan en geç bir dakika içinde {$pc->ad} üzerine kurulur ve konsolu açar. İş bitince kaldırılır.");
    }

    /** Yeni iş formunda seçilen cihazın yedekleri (console-form.js). */
    public function yedekler(Request $request, ConsolePage $sayfa): JsonResponse
    {
        return response()->json(['yedekler' => $sayfa->yedekSecenekleri($request->string('cihaz')->value())]);
    }

    /** Canlı ekranın yoklaması: verilen parçadan sonraki çıktı ve işin durumu. */
    public function akis(Request $request, ConsoleJob $is, ConsolePage $sayfa): JsonResponse
    {
        return response()->json($sayfa->akis($is, max(0, (int) $request->query('sonra', '0'))));
    }

    /** Konsol çıktısının tamamı, düz metin. */
    public function kayit(ConsoleJob $is): StreamedResponse
    {
        $ad = 'konsol-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $is->ad).'-'.$is->created_at?->format('Ymd-His').'.txt';

        return response()->streamDownload(function () use ($is): void {
            $is->ciktilar()->orderBy('id')->select(['id', 'metin'])->lazyById(200)->each(function ($parca): void {
                echo $parca->metin;
            });
        }, $ad, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function girdi(InputRequest $request, ConsoleJob $is): JsonResponse
    {
        if (! $is->acikMi()) {
            return response()->json(['message' => 'İş kapalı.'], 409);
        }

        $metin = $request->metin();
        $is->girdiler()->create(['metin' => $metin, 'kullanici_id' => Auth::id()]);

        /* Tuşlar kaydı şişirmesin: yalnız yazılan satırlar denetime girer. */
        if (! $request->filled('tus')) {
            $this->kayit->kaydet('konsol_girdi', "Konsola elle yazıldı ({$is->ad}): ".mb_substr(rtrim($metin, "\r"), 0, 200), $is);
        }

        return response()->json(['ok' => true]);
    }

    public function kapat(ConsoleJob $is): RedirectResponse
    {
        if (! $is->acikMi()) {
            return redirect()->route('konsol.show', $is)->with('error', 'İş zaten kapalı.');
        }

        $is->kapat('iptal', 'Panelden kapatıldı.');
        $is->olaylar()->create(['asama' => 'hata', 'mesaj' => 'Panelden kapatıldı; ajan durur.', 'kaynak' => Auth::user()?->name]);
        $this->kayit->kaydet('konsol_is_kapat', "Konsol işi kapatıldı: {$is->ad}", $is);

        return redirect()->route('konsol.show', $is)->with('status', 'İş kapatıldı. Ajan en geç birkaç saniyede durur; cihazda o ana kadar yapılanlar geri alınmaz.');
    }

    public function destroy(ConsoleJob $is): RedirectResponse
    {
        if ($is->acikMi()) {
            return redirect()->route('konsol.show', $is)->with('error', 'Açık iş silinemez; önce kapatın.');
        }

        $ad = "{$is->ad} ({$is->durumEtiketi()})";
        $is->delete();
        $this->kayit->kaydet('konsol_is_sil', "Konsol işi silindi: {$ad}");

        return redirect()->route('konsol.index')->with('status', "{$ad} geçmişten silindi.");
    }
}
