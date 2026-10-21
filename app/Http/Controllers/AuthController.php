<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\GirisRequest;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Kimlik\MerkezIstemcisi;
use App\Services\Kimlik\OrtakCikis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Giriş ekranı, parolayla giriş ve çıkış.
 *
 * ASIL YOL MERKEZ: kullanıcı merkezin sayfasında giriş yapar, kimin
 * girebileceğine merkez karar verir ve hesap orada açılır.
 *
 * PAROLA FORMU YİNE DE DURUR — kardeş panellerdeki kararla aynı:
 * merkez erişilemez olduğu gün panele kimse giremezdi, yöneticisi dahil.
 * Merkez açıkken form KATLI durur; ekranı sadeleştirir ama o günü kurtarır.
 *
 * Bu panelde parolanın anlamı daha da dar: burada duran şey switch
 * yapılandırmaları ve hesaplar merkezden geliyor — parola yalnızca yönetici
 * elle verdiyse vardır.
 *
 * Yetkilendirme: giriş ekranı ve parola ucu BİLEREK `auth` dışında; çıkış
 * zaten oturum sahibini ilgilendirir.
 */
class AuthController extends Controller
{
    public function showLogin(MerkezIstemcisi $merkez): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('yedek.index');
        }

        /* Görünüm kardeş panellerle BİREBİR AYNI dosya; beklediği iki değer bunlar. */
        return view('auth.login', [
            'merkezAcik' => $merkez->acikMi(),
            'merkezAd' => config('kimlik.ad'),
        ]);
    }

    /**
     * Parolayla giriş.
     *
     * PASİF HESAP GİREMEZ ve HESABIN VAR OLUP OLMADIĞI AYIRT EDİLMEZ:
     * ekranda tek bir mesaj var; iki farklı mesaj, hangi adreslerin kayıtlı
     * olduğunu deneyerek öğrenmenin yolu olurdu.
     */
    public function login(GirisRequest $request, ActivityRecorder $kayit): RedirectResponse
    {
        $bilgiler = $request->validated();

        $kullanici = User::where('email', $bilgiler['email'])->first();

        if (! $kullanici || ! $kullanici->aktif || ! Auth::attempt($bilgiler)) {
            /* Deneme kayda geçer; PAROLA YAZILMAZ — kaydı okuyan herkes görürdü. */
            $kayit->kaydet('giris_basarisiz', "Başarısız giriş denemesi: {$bilgiler['email']}");

            /*
             * HATA DOĞRULAMA HATASI OLARAK DÖNER — kardeş panellerdeki gibi.
             * Görünümü 401 ile doğrudan çizmek giriş ekranını şablondan ayrı
             * bir değişken (`$error`) taşımaya zorlar ve ekranları ayrıştırır.
             */
            throw ValidationException::withMessages([
                'email' => 'Bu bilgilerle giriş yapılamadı.',
            ]);
        }

        $request->session()->regenerate();

        $kayit->kaydet('giris', "Panele giriş (parola): {$kullanici->email}", $kullanici, $kullanici);

        return redirect()->intended(route('yedek.index'));
    }

    /**
     * ORTAK ÇIKIŞ: merkezden giren kişinin çıkışı merkeze bildirilir; merkez
     * kendi oturumunu ve öteki panelleri kapatır (bkz. OrtakCikis). Bu
     * paneldeki öteki oturumları da (başka cihaz) aynı an kapatır.
     *
     * ZAMAN AŞIMI ORTAK DEĞİL: ekran kilidinin çıkışı (süre doldu) yalnız bu
     * oturumu kapatır; bir sekmede süresi dolan kişi öteki panellerden atılmaz.
     */
    public function logout(Request $request, ActivityRecorder $kayit, MerkezIstemcisi $merkez): RedirectResponse
    {
        $bag = $request->user() !== null && ! OrtakCikis::zamanAsimiMi($request) ? OrtakCikis::bag($request->user()) : null;

        if ($bag !== null) {
            OrtakCikis::isle($bag, OrtakCikis::simdiMs());

            if ($merkez->acikMi()) {
                rescue(fn () => $merkez->oturumKapandi($bag->subject));
            }
        }

        /* Kayıt ÇIKIŞTAN ÖNCE: sonrasında kimin çıktığı belli olmaz. */
        if ($kullanici = $request->user()) {
            $kayit->kaydet('cikis', "Panelden çıkış: {$kullanici->email}", $kullanici, $kullanici);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function yetkisiz(): View
    {
        return view('auth.yetkisiz');
    }
}
