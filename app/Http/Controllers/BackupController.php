<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ActivityRecorder;
use App\Services\Backup\BackupStore;
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
class BackupController extends Controller
{
    public function __construct(private readonly BackupStore $depo) {}

    /** Cihaz klasörleri. */
    public function index(Request $request): View|RedirectResponse
    {
        /*
         * Ana sayfa herkesin ilk durağı; yedek göremeyen kişi 403 yerine
         * görebildiği bölüme gider.
         */
        if (Gate::denies('yedek:view')) {
            return redirect()->route(Gate::allows('junos:view') ? 'setup.index' : 'forbidden');
        }

        $arama = trim((string) $request->query('search', ''));

        $klasorler = $this->depo->klasorler();

        if ($arama !== '') {
            $klasorler = $klasorler
                ->filter(fn (array $k): bool => str_contains(mb_strtolower($k['ad']), mb_strtolower($arama)))
                ->values();
        }

        return view('backup.index', [
            'klasorler' => $klasorler,
            'arama' => $arama,
            'ozet' => $this->depo->ozet(),
            'erisim' => $this->depo->erisilebilirMi(),
        ]);
    }

    /** Bir cihazın yedekleri. */
    public function folder(Request $request, string $path = ''): View
    {
        $tam = $this->depo->coz($path);

        abort_if($tam === null || ! is_dir($tam), 404);

        $arama = trim((string) $request->query('search', ''));
        $dosyalar = $this->depo->dosyalar($path, $arama);

        return view('backup.folder', [
            'klasor' => $path,
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
    public function download(string $path, ActivityRecorder $kayit): BinaryFileResponse
    {
        $dosya = $this->depo->dosya($path);

        abort_if($dosya === null, 404);

        $kayit->kaydet('yedek_indirildi', 'Yedek indirildi: '.$path);

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
        $boy = (int) config('backup.page_size', 50);
        $sayfa = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $dosyalar->forPage($sayfa, $boy)->values(),
            $dosyalar->count(),
            $boy,
            $sayfa,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'page'],
        );
    }
}
