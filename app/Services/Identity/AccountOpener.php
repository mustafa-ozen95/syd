<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Merkezden gelen TANINMAYAN kişi için yerel hesap açar.
 *
 * NEDEN VAR: merkezde rolü olan biri bu panele ilk kez
 * geldiğinde "eşleşen hesap yok" duvarına çarpıyordu ve yöneticinin elle
 * hesap açmasını beklemesi gerekiyordu. Oysa rol atamak, o kişiye BAKILMIŞ
 * olmasının kendisidir; merkez zaten "bu kişi girebilir" diyor.
 *
 * KARARI MERKEZ VERİR: "bu uygulamada hesap kendiliğinden açılsın mı"
 * sorusunun yanıtı merkezdeki İstemciler ekranındadır ve kimlik yanıtıyla
 * birlikte gelir. Rolün o uygulamaya girip giremediğini de merkez söylüyor;
 * giremeyen zaten buraya ulaşmıyor.
 *
 * YÖNETİCİLİK DEVROLMAZ. Merkezin yöneticisi kimlikleri yönetir; buranın
 * yöneticisi bütün bütün yedeklere ve kullanıcılara dokunur. İkisi kendiliğinden
 * aynı kişi olmamalı: merkezde rol dağıtabilen biri, buradaki hiçbir
 * yöneticinin haberi olmadan kendini burada da yönetici yapabilirdi. Devir
 * yalnızca `identity.yonetici_devri` açıkken olur.
 *
 * PAROLA YAZILMAZ: hesap rastgele, kimsenin bilmediği bir parolayla doğar.
 * Bu kişinin giriş yolu merkezdir; parolayla girmek isterse yerel bir
 * yönetici parola tanımlar.
 */
class AccountOpener
{
    /**
     * Bu kimlik için hesap AÇILABİLİR Mİ?
     *
     * KARAR MERKEZİNDİR: hangi uygulamada hesabın kendiliğinden açılacağını
     * merkez söyler (İstemciler ekranında uygulama başına bir kutucuk) ve
     * kimlik yanıtında `can_create_account` olarak gönderir. Panel kendi
     * listesini tutmaz — aynı karar iki yerde durursa biri değişince öbürü
     * sessizce eski kalır.
     *
     * ADRES DOĞRULAMASI BURADA DA ARANIR: merkez izin verse bile
     * doğrulanmamış bir adresle hesap açmak, meslektaşının adresiyle kayıt
     * olan birine burada hesap vermek olurdu.
     *
     * @param  array{email_verified?: bool, can_create_account?: bool}  $kimlik
     */
    public function acilabilirMi(array $kimlik): bool
    {
        return ($kimlik['can_create_account'] ?? false)
            && ($kimlik['email_verified'] ?? false);
    }

    /**
     * @param  array{sub: string, name: string, email: string, email_verified: bool, rol?: ?string}  $kimlik
     */
    public function ac(array $kimlik): User
    {
        return User::create([
            /* Ad merkezden gelir; boşsa adres okunabilir tek şey. */
            'name' => $kimlik['name'] !== '' ? $kimlik['name'] : $kimlik['email'],
            'email' => $kimlik['email'],

            /*
             * Bilinmeyen parola: hesabın kapısı merkezdir. Boş bırakmak ya da
             * öngörülebilir bir değer koymak, parolayla girişi açık bir kapı
             * hâline getirirdi.
             */
            'password' => Str::random(64),

            'rol_id' => $this->rol($kimlik['role'] ?? null),
            'aktif' => true,

            /*
             * Yetki ROLDEN gelir (bkz. UserPermissionResolver): merkezin
             * verdiği rol bu panelde ne yapabildiğini de söyler. Kişiye
             * özel istisna yöneticinin Kullanıcılar ekranındaki işidir.
             */
        ]);
    }

    /**
     * Merkezdeki rolün buradaki karşılığı.
     *
     * Slug'lar kardeş panellerde de aynı: merkezdeki rol burada da aynı
     * adı taşır. Karşılığı olmayan bir slug ROLSÜZ bırakılır — tanınmayan bir
     * rolü varsayılana çevirmek, kimsenin istemediği bir anda birinin
     * kimliğini değiştirmek olurdu.
     *
     * YÖNETİCİ ROLÜ YALNIZCA DEVİR AÇIKKEN geçer: merkezde rol dağıtabilen
     * biri, buradaki hiçbir yöneticinin haberi olmadan kendini burada da
     * yönetici yapabilirdi.
     */
    private function rol(?string $slug): ?int
    {
        if (blank($slug)) {
            return null;
        }

        $rol = Role::where('slug', $slug)->first();

        if ($rol === null || ($rol->yonetici && ! config('identity.yonetici_devri'))) {
            return null;
        }

        return $rol->id;
    }
}
