<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Controllers;

use App\ConsoleModule\Http\Requests\InputRequest;
use App\ConsoleModule\Http\Requests\IsRequest;
use App\ConsoleModule\Http\Requests\OnizlemeRequest;
use App\ConsoleModule\Junos\PatchBuilder;
use App\ConsoleModule\Junos\YedekDosyasi;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Services\ConsolePage;
use App\ConsoleModule\Services\JobOpener;
use App\ConsoleModule\Services\Ozellestirme;
use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        return view('console::index', $sayfa->liste());
    }

    public function show(ConsoleJob $job, ConsolePage $sayfa): View
    {
        return view('console::is', $sayfa->is($job));
    }

    public function store(IsRequest $request, JobOpener $acici): RedirectResponse
    {
        $pc = $request->pc();
        $job = $acici->ac($pc, $request->payload() + ['olusturan_id' => Auth::id()], $request->file('goruntu_yukle'));
        $job->olaylar()->create(['asama' => 'basladi', 'mesaj' => 'İş açıldı.', 'kaynak' => Auth::user()?->name]);

        $this->kayit->kaydet('konsol_is_ac', "Konsol işi açıldı: {$job->ad} · PC: {$pc->ad} · {$job->islemOzeti()} · {$job->adresOzeti()} · {$job->yapilandirmaOzeti()}"
            .($job->root_parola !== null ? ' · root parolası verildi' : ''), $job);

        return redirect()->route('console.show', $job)
            ->with('status', ($job->yalnizYedekMi() ? 'İş açıldı (yalnız yedek)' : 'İş açıldı')
                ."; ajan en geç bir dakika içinde {$pc->ad} üzerine kurulur ve konsolu açar. İş bitince kaldırılır.");
    }

    /** Yeni iş formunda seçilen cihazın yedekleri (console-form.js). */
    public function backups(Request $request, ConsolePage $sayfa): JsonResponse
    {
        return response()->json(['yedekler' => $sayfa->yedekSecenekleri($request->string('device')->value())]);
    }

    /** Özelleştirme bölümünün ön değerleri: seçilen yedekte ne var (console-form.js). */
    public function fields(Request $request, Ozellestirme $ozellestirme): JsonResponse
    {
        /* Bilgisayardan seçilen yedek de olabilir (POST, dosyayla). */
        $yuklenen = $request->file('yedek_yukle');
        $cihaz = $request->string('device')->value();

        /* Yetkisi olmayana yedeğin değerleri gösterilmez (yalnız şablonlar). */
        if (! Ozellestirme::serbestMi($request->user(), $cihaz, $yuklenen instanceof UploadedFile)) {
            return response()->json(['alanlar' => null]);
        }

        $metin = $yuklenen instanceof UploadedFile ? YedekDosyasi::metin($yuklenen) : null;

        return response()->json(['alanlar' => $ozellestirme->alanlar($cihaz, $request->string('file')->value(), $metin)]);
    }

    /** Ajanın yedeğin üstüne yazacağı satırlar; parola özetleri maskeli. */
    public function preview(OnizlemeRequest $request, Ozellestirme $ozellestirme): JsonResponse
    {
        $cihaz = $request->string('ad')->value();
        $dosya = $request->string('yedek_dosya')->value();
        $oz = (array) $request->ozellestirme();
        $metin = $request->yedekMetni();

        return response()->json([
            'satirlar' => PatchBuilder::maskele($ozellestirme->yama($cihaz, $dosya, $oz, $metin)),
            'ozet' => $ozellestirme->ozet($cihaz, $dosya, $oz, $metin),
        ]);
    }

    /** Canlı ekranın yoklaması: verilen parçadan sonraki çıktı ve işin durumu. */
    public function stream(Request $request, ConsoleJob $job, ConsolePage $sayfa): JsonResponse
    {
        return response()->json($sayfa->akis($job, max(0, (int) $request->query('after', '0'))));
    }

    /** Konsol çıktısının tamamı, düz metin. */
    public function log(ConsoleJob $job): StreamedResponse
    {
        $ad = 'konsol-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $job->ad).'-'.$job->created_at?->format('Ymd-His').'.txt';

        return response()->streamDownload(function () use ($job): void {
            $job->ciktilar()->orderBy('id')->select(['id', 'metin'])->lazyById(200)->each(function ($parca): void {
                echo $parca->metin;
            });
        }, $ad, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function input(InputRequest $request, ConsoleJob $job): JsonResponse
    {
        if (! $job->acikMi()) {
            return response()->json(['message' => 'İş kapalı.'], 409);
        }

        $metin = $request->metin();
        $job->girdiler()->create(['metin' => $metin, 'kullanici_id' => Auth::id()]);

        /* Tuşlar kaydı şişirmesin: yalnız yazılan satırlar denetime girer. */
        if (! $request->filled('tus')) {
            $this->kayit->kaydet('konsol_girdi', "Konsola elle yazıldı ({$job->ad}): ".mb_substr(rtrim($metin, "\r"), 0, 200), $job);
        }

        return response()->json(['ok' => true]);
    }

    public function close(ConsoleJob $job): RedirectResponse
    {
        if (! $job->acikMi()) {
            return redirect()->route('console.show', $job)->with('error', 'İş zaten kapalı.');
        }

        $job->kapat('iptal', 'Panelden kapatıldı.');
        $job->olaylar()->create(['asama' => 'hata', 'mesaj' => 'Panelden kapatıldı; ajan durur.', 'kaynak' => Auth::user()?->name]);
        $this->kayit->kaydet('konsol_is_kapat', "Konsol işi kapatıldı: {$job->ad}", $job);

        return redirect()->route('console.show', $job)->with('status', 'İş kapatıldı. Ajan en geç birkaç saniyede durur; cihazda o ana kadar yapılanlar geri alınmaz.');
    }

    public function destroy(ConsoleJob $job): RedirectResponse
    {
        if ($job->acikMi()) {
            return redirect()->route('console.show', $job)->with('error', 'Açık iş silinemez; önce kapatın.');
        }

        $ad = "{$job->ad} ({$job->durumEtiketi()})";
        $job->delete();
        $this->kayit->kaydet('konsol_is_sil', "Konsol işi silindi: {$ad}");

        return redirect()->route('console.index')->with('status', "{$ad} geçmişten silindi.");
    }
}
