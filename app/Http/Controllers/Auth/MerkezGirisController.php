<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\ActivityRecorder;
use App\Services\Kimlik\HesapAcici;
use App\Services\Kimlik\HesapEslestirici;
use App\Services\Kimlik\MerkezIstemcisi;
use App\Services\Kimlik\MerkezYetkisizException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Kimlik Yönetim Merkezi ile giriş.
 *
 * PAROLA BU PANELE HİÇ UĞRAMAZ: kullanıcı merkezin kendi sayfasında giriş
 * yapar, buraya tek kullanımlık bir kod döner. Bu yüzden düğme TAM SAYFA
 * yönlendirir; bir pencere içinde sorulsaydı kullanıcı parolasını yazarken
 * adres çubuğunda merkezi göremezdi.
 *
 * PANELİN KENDİ GİRİŞİNİ DEĞİŞTİRMEZ. Kullanıcı adı/parola ile giriş yerinde
 * kalır: merkez erişilemez olduğunda panel kilitlenmemeli.
 *
 * Kardeş panellerdeki akışın aynısıdır; ayrışmasınlar.
 */
class MerkezGirisController extends Controller
{
    private const DURUM = 'kimlik_durum';

    private const DOGRULAYICI = 'kimlik_dogrulayici';

    public function baslat(Request $request, MerkezIstemcisi $merkez): RedirectResponse
    {
        abort_unless($merkez->acikMi(), 404);

        $dogrulayici = $merkez->dogrulayici();
        $durum = Str::random(40);

        /*
         * İkisi de OTURUMDA kalır: geri dönen isteğin bizim başlattığımız
         * akış olduğunu böyle biliriz.
         */
        $request->session()->put(self::DOGRULAYICI, $dogrulayici);
        $request->session()->put(self::DURUM, $durum);

        return redirect()->away($merkez->girisAdresi($durum, $merkez->meydanOkuma($dogrulayici)));
    }

    public function geri(
        Request $request,
        MerkezIstemcisi $merkez,
        HesapEslestirici $eslestirici,
        HesapAcici $hesapAcici,
        ActivityRecorder $kayit,
    ): RedirectResponse {
        abort_unless($merkez->acikMi(), 404);

        $beklenen = $request->session()->pull(self::DURUM);
        $dogrulayici = $request->session()->pull(self::DOGRULAYICI);

        /*
         * DURUM EŞLEŞMELİ (CSRF).
         *
         * Eşleşmiyorsa bu akışı biz başlatmamışız demektir: saldırgan, kendi
         * kodunu kurbanın tarayıcısında kullandırıp onu kendi hesabına
         * bağlayabilirdi.
         */
        if (blank($beklenen) || ! hash_equals((string) $beklenen, (string) $request->query('state'))) {
            return $this->hata('Giriş akışı doğrulanamadı; lütfen yeniden deneyin.');
        }

        if (blank($request->query('code')) || blank($dogrulayici)) {
            return $this->hata('Merkezden kod gelmedi.');
        }

        try {
            $jeton = $merkez->jeton((string) $request->query('code'), (string) $dogrulayici);
            $kimlik = $merkez->kullanici((string) ($jeton['access_token'] ?? ''));
        } catch (MerkezYetkisizException $e) {
            /*
             * MERKEZ KAPIYI KAPATTI — hata değil, KARAR.
             *
             * Hangi rolün bu panele girebileceğine merkez karar veriyor
             * (İstemciler ekranındaki rol listesi). Red kayda geçer: kimin
             * nereye girmeye çalıştığı sorulacak.
             */
            $kayit->kaydet('merkez-giris-reddedildi', $e->getMessage());

            return $this->hata($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->hata('Merkezle bağlantı kurulamadı; lütfen parolanızla girin.');
        }

        /*
         * Buraya ulaşan kimlik MERKEZİN ONAYINDAN geçmiştir: girmemesi
         * gereken yukarıda 403 alır. Panelin ayrıca rol listesi tutmasına
         * gerek yok — aynı karar iki yerde durursa biri değişince öbürü
         * sessizce eski kalır.
         */
        $kullanici = $eslestirici->bul($kimlik);

        /*
         * EŞLEŞME YOKSA HESAP AÇILIR — koşulları sağlıyorsa.
         *
         * Merkezde rolü olan biri bu panele ilk geldiğinde "eşleşen hesap
         * yok" duvarına çarpıyor ve yöneticinin elle hesap açmasını
         * bekliyordu. Oysa rol atamak, o kişiye bakılmış olmasının
         * kendisidir; rol süzgeci de yukarıda zaten geçildi.
         *
         * Koşullar HesapAcici'de: ayar açık, adres doğrulanmış, rol izinli.
         * Sağlanmıyorsa kapı yine kapalıdır.
         */
        if ($kullanici === null && $hesapAcici->acilabilirMi($kimlik)) {
            $kullanici = $hesapAcici->ac($kimlik);

            $kayit->kaydet(
                'merkez-hesap-acildi',
                "Merkezden gelen kimlik için hesap açıldı: {$kullanici->email}"
                    ." · {$kimlik['email']} · merkezdeki rol ".($kimlik['rol'] ?? 'rolsüz'),
                $kullanici,
                $kullanici,
            );
        }

        if ($kullanici === null) {
            return $this->hata('Bu kimlikle eşleşen bir hesap yok. Hesap açılması için yöneticinize başvurun.');
        }

        if (! $kullanici->aktif) {
            return $this->hata('Hesabınız etkin değil. Yöneticinize başvurun.');
        }

        $ilkBag = $eslestirici->bagla($kullanici, $kimlik);

        /*
         * HATIRLAMA ÇEREZİ YOK: oturum süresi gerçekten süre olsun
         * (kardeş panellerde de aynı).
         */
        Auth::login($kullanici, remember: false);
        $request->session()->regenerate();

        $kayit->kaydet(
            $ilkBag->wasRecentlyCreated ? 'merkez-giris-baglandi' : 'merkez-giris',
            "Merkezle giriş: {$kimlik['email']} (rol: ".($kimlik['rol'] ?? 'rolsüz').')',
            $kullanici,
            $kullanici,
        );

        return redirect()->intended(route('yedek.index'));
    }

    private function hata(string $mesaj): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $mesaj]);
    }
}
