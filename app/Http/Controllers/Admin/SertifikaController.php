<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use App\Services\Sertifika\SertifikaTuru;
use App\Services\Settings\SettingsTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * TLS sertifikası — durum, doğrulama kaydı ve tur.
 *
 * NEDEN ELLE: panelin adresi iç ağda; sertifika sağlayıcısı adrese
 * bağlanarak doğrulayamıyor. DNS-01 işliyor ama DNS sağlayıcısında otomasyon
 * yoksa kaydı bir insan ekliyor. Bu ekran onun işini kolaylaştırıyor: kaydı
 * gösteriyor, yayıldığını kendi sorguluyor, sonra turu tamamlatıyor.
 *
 * PANEL SERTİFİKA ALMAZ. ACME hesabı, özel anahtar ve sertifika dosyaları
 * sunucuda durur; panel yalnızca içinde eylemin adı olan küçük bir istek
 * dosyası bırakır (bkz. sertifika-ajan.sh). Ele geçirilen bir panel, alan
 * adımız için sertifika üretememeli.
 *
 * Yetkilendirme: HasMiddleware ile `can:admin`.
 */
class SertifikaController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingsTabs $sekmeler,
        private SertifikaTuru $sertifika,
        private ActivityRecorder $kayit,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:admin')];
    }

    public function index(): View
    {
        return view('settings.sertifika', [
            'sections' => $this->sekmeler->all('sertifika'),
            'durum' => $this->sertifika->durum(),
            'kayitlar' => $this->sertifika->kayitlar(),
            'islem' => $this->sertifika->sonIslem(),
            'yayilma' => $this->sertifika->yayilmaDurumu(),
            'bekliyor' => $this->sertifika->istekBekliyorMu(),
        ]);
    }

    /** Yeni tur: sunucu ACME siparişini açar ve TXT kaydını yazar. */
    public function baslat(): RedirectResponse
    {
        if (! $this->sertifika->istekBirak('baslat')) {
            return $this->iletilemedi();
        }

        $this->kayit->kaydet('sertifika_tur', 'Yeni sertifika turu başlatıldı.');

        return back()->with('status', 'Tur başlatıldı. Kayıt birkaç saniye içinde burada görünecek.');
    }

    /**
     * Onayla: kaydın YAYILDIĞINI panel kendi sorar, sonra turu tamamlatır.
     *
     * Yayılmamış bir kayıtla sağlayıcıya gitmek başarısız bir deneme demek;
     * tur baştan başlar ve kullanıcı sebebini anlamaz.
     */
    public function tamamla(): RedirectResponse
    {
        $eksik = collect($this->sertifika->yayilmaDurumu())
            ->reject(fn (array $k): bool => $k['yayildi'])
            ->pluck('ad')
            ->all();

        if ($eksik !== []) {
            return back()->withErrors([
                'sertifika' => 'Şu kayıt DNS\'te henüz görünmüyor: '.implode(', ', $eksik),
            ]);
        }

        if (! $this->sertifika->istekBirak('tamamla')) {
            return $this->iletilemedi();
        }

        $this->kayit->kaydet('sertifika_al', 'Sertifika alma isteği gönderildi.');

        return back()->with('status', 'Sertifika alınıyor. Bu işlem bir dakikayı bulabilir.');
    }

    /**
     * İstek dosyası yazılamadı: sunucudaki ajan hiçbir şey görmez. Yine de
     * "tur başlatıldı" demek kullanıcıyı boşuna bekletir; istek dizini root'a
     * ait olduğunda sekme tam olarak böyle sessizce hiçbir şey yapmaz.
     */
    private function iletilemedi(): RedirectResponse
    {
        return back()->withErrors([
            'sertifika' => 'İstek sunucuya iletilemedi: panel istek dizinine (/etc/dc/sertifika-panel) yazamıyor. Sunucu yöneticisine bildirin.',
        ]);
    }
}
