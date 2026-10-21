<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ActivityRecorder;
use App\Services\Yedek\YedekDeposu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Switch yedeklerinin görüntülenmesi.
 *
 * SALT OKUMA. Bu denetleyicide yazan tek şey DENETİM KAYDIDIR: kim hangi
 * yedeği indirdi. Ortak bir parolayla girilen panelde bir yapılandırmayı
 * kimin aldığı hiçbir yerde durmaz; burada her kişi kendi hesabıyla girer
 * ve her indirme kayda geçer.
 */
class YedekController extends Controller
{
    public function __construct(private readonly YedekDeposu $depo) {}

    /** Cihaz klasörleri. */
    public function index(Request $request): View|RedirectResponse
    {
        /*
         * Ana sayfa herkesin ilk durağı; yedek göremeyen kişi 403 yerine
         * görebildiği bölüme gider.
         */
        if (Gate::denies('yedek:view')) {
            return redirect()->route(Gate::allows('junos:view') ? 'kurulum.index' : 'yetkisiz');
        }

        $arama = trim((string) $request->query('ara', ''));

        $klasorler = $this->depo->klasorler();

        if ($arama !== '') {
            $klasorler = $klasorler
                ->filter(fn (array $k): bool => str_contains(mb_strtolower($k['ad']), mb_strtolower($arama)))
                ->values();
        }

        return view('yedek.index', [
            'klasorler' => $klasorler,
            'arama' => $arama,
            'ozet' => $this->depo->ozet(),
            'erisim' => $this->depo->erisilebilirMi(),
        ]);
    }

    /** Bir cihazın yedekleri. */
    public function klasor(Request $request, string $yol = ''): View
    {
        $tam = $this->depo->coz($yol);

        abort_if($tam === null || ! is_dir($tam), 404);

        $arama = trim((string) $request->query('ara', ''));
        $dosyalar = $this->depo->dosyalar($yol, $arama);

        return view('yedek.klasor', [
            'klasor' => $yol,
            'arama' => $arama,
            'dosyalar' => $this->sayfala($request, $dosyalar),
            'toplam' => $dosyalar->count(),
            /* İndirmek ayrı yetki (yedek:download); düğme yetkisi olana çıkar. */
            'indirebilir' => Gate::allows('yedek:download'),
        ]);
    }

    /**
     * İndirme.
     *
     * KAYDA GEÇER: hangi kullanıcı hangi yedeği aldı. Yedek dosyası bir
     * cihazın tüm yapılandırmasıdır — adresler, arayüzler, komşuluklar.
     * Kimin indirdiğini bilmek, bir sızıntı sonrası sorulacak ilk sorudur.
     */
    public function indir(string $yol, ActivityRecorder $kayit): BinaryFileResponse
    {
        $dosya = $this->depo->dosya($yol);

        abort_if($dosya === null, 404);

        $kayit->kaydet('yedek_indirildi', 'Yedek indirildi: '.$yol);

        /*
         * `.gz` dosyaları tarayıcıda AÇILMASIN diye octet-stream: bazı
         * tarayıcılar gzip'i kendiliğinden açıp içeriği ekrana basıyordu.
         */
        return response()
            ->download($dosya->getPathname(), $dosya->getFilename(), ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * Sayfalama ELDE yapılır: kaynak dosya sistemi, veritabanı değil.
     *
     * Bir cihazın klasöründe binlerce yedek olabiliyor; hepsini tek sayfada
     * çizmek tarayıcıyı kilitliyordu.
     *
     * @param  Collection<int, array<string, mixed>>  $dosyalar
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function sayfala(Request $request, Collection $dosyalar): LengthAwarePaginator
    {
        $boy = (int) config('yedek.sayfa_boyu', 50);
        $sayfa = max(1, (int) $request->query('sayfa', 1));

        return new LengthAwarePaginator(
            $dosyalar->forPage($sayfa, $boy)->values(),
            $dosyalar->count(),
            $boy,
            $sayfa,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'sayfa'],
        );
    }
}
