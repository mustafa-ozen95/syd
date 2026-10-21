<?php

declare(strict_types=1);

namespace App\Services\Kimlik;

use App\Models\Rol;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Support\Facades\Log;

/**
 * Merkezdeki değişikliği YEREL hesaba taşır.
 *
 * NEDEN VAR: merkezde birinin hesabı askıya alınır ya da silinirse buradaki
 * kopya eskir; taşınmazsa o kişi merkeze giremediği hâlde burada erişmeye
 * devam eder. GİRİŞTE BAKMAK YETMEZ: değişiklik, kişi hiç giriş yapmadan da
 * olur.
 *
 * TEK YÖN: merkez söyler, burası uygular. Buradan merkeze hiçbir şey
 * yazılmaz; yazılsaydı iki kaynak birbirini ezerdi.
 *
 * HESAP SİLİNMEZ, KAPATILIR. Merkezde silinen kimliğin buradaki karşılığı
 * pasife alınır: denetim kaydının sahibi o satırdır, silmek
 * geçmişi okunmaz yapardı.
 *
 * KAPIYI MERKEZ TUTAR. Hangi rolün bu panele girebileceği merkezdeki
 * İstemciler ekranında yazılı; rolü listeden çıkan kişiye merkez "giremez"
 * demeye başlar ve bu tur onu burada da kapatır.
 *
 * YETKİ ROLDEN GELİR, AYRINTISI BURADA (kardeş panellerdeki düzen):
 * merkez kişinin rolünü söyler, o rolün bu panelde ne yapabildiğini
 * buradaki yönetici Ayarlar → Roller'da belirler. Kişiye özel istisna da
 * buradadır (bkz. UserPermissionResolver).
 *
 * YÖNETİCİLİK DE DEVROLMAZ. Merkezde rol dağıtabilen biri, buradaki hiçbir
 * yöneticinin haberi olmadan kendini burada yönetici yapabilirdi.
 */
class KimlikEsitleyici
{
    public function __construct(private MerkezIstemcisi $merkez) {}

    /**
     * Merkeze bağlı bütün hesapları eşitler.
     *
     * @return array{sorulan: int, kapatilan: int, acilan: int}
     */
    public function esitle(): array
    {
        $baglar = UserIdentity::with('user')
            ->where('provider', HesapEslestirici::SAGLAYICI)
            ->get()
            ->filter(fn (UserIdentity $bag): bool => $bag->user !== null);

        $sayac = ['sorulan' => $baglar->count(), 'kapatilan' => 0, 'acilan' => 0];

        if ($baglar->isEmpty()) {
            return $sayac;
        }

        $durumlar = $this->merkez->kimlikDurumlari($baglar->pluck('subject')->all());

        foreach ($baglar as $bag) {
            /*
             * YANIT GELMEYEN KİMLİĞE DOKUNULMAZ.
             *
             * Merkez o kimliği yanıtta hiç anmadıysa elimizde bilgi yok
             * demektir; "bilmiyorum"u "yok" saymak, merkezdeki bir aksaklıkta
             * bütün kadroyu panelden atmak olurdu.
             */
            if (! isset($durumlar[$bag->subject])) {
                continue;
            }

            $sonuc = $this->uygula($bag->user, (array) $durumlar[$bag->subject]);

            $sayac['kapatilan'] += (int) $sonuc['kapatildi'];
            $sayac['acilan'] += (int) $sonuc['acildi'];
        }

        return $sayac;
    }

    /**
     * Tek bir hesabı merkezdeki durumuna getirir.
     *
     * MERKEZ HER İKİ YÖNDE DE EZER. Yalnızca kapatsaydı, merkezde geri açılan
     * hesap burada kapalı kalır ve kimse hangisinin doğru olduğunu bilemezdi.
     *
     * @param  array{durum?: string, girebilir?: bool, rol?: ?string}  $durum
     * @return array{kapatildi: bool, acildi: bool}
     */
    public function uygula(User $kullanici, array $durum): array
    {
        /*
         * "GİREBİLİR" SORAN UYGULAMAYA GÖREDİR.
         *
         * Merkez yanıtı bu panele göre veriyor: hesabın durumu açık olsa bile
         * rolü bu uygulamanın listesinde değilse "giremez" döner
         * (bkz. merkezdeki KimlikDurumuController). Panelin ayrıca kendi rol
         * listesini tutmasına gerek yok.
         */
        $girebilir = (bool) ($durum['girebilir'] ?? false);

        /*
         * ROL HER DURUMDA eşitlenir — kapalı hesabınki de.
         *
         * Atlansaydı, merkezden geri açılan bir hesap eski rolüyle açılırdı.
         * Slug'lar kardeş panellerde de aynı: merkezdeki rol burada da aynı
         * adla görünmeli. Bu, kimliğin kendisidir; yetki ayrı.
         */
        $this->roluGuncelle($kullanici, $durum['rol'] ?? null);

        /* Ortak çıkış: bildirim kaybolduysa an buradan gelir (bkz. OrtakCikis). */
        if (is_int($durum['son_cikis'] ?? null) && ($bag = OrtakCikis::bag($kullanici)) !== null) {
            OrtakCikis::isle($bag, $durum['son_cikis']);
        }

        if (! $girebilir && $kullanici->aktif) {
            $kullanici->forceFill(['aktif' => false])->save();

            Log::info('Merkezde erişimi kalktı, hesap kapatıldı.', [
                'kullanici' => $kullanici->email,
                'merkezdeki_durum' => (string) ($durum['durum'] ?? 'bilinmiyor'),
                'merkezdeki_rol' => (string) ($durum['rol'] ?? 'rolsüz'),
            ]);

            return ['kapatildi' => true, 'acildi' => false];
        }

        if ($girebilir && ! $kullanici->aktif) {
            $kullanici->forceFill(['aktif' => true])->save();

            Log::info('Merkezde erişimi geri geldi, hesap açıldı.', [
                'kullanici' => $kullanici->email,
            ]);

            return ['kapatildi' => false, 'acildi' => true];
        }

        return ['kapatildi' => false, 'acildi' => false];
    }

    /**
     * Merkezdeki rol değiştiyse buradaki de değişir.
     *
     * Slug'lar kardeş panellerde de aynı: merkezdeki rol burada da aynı
     * adla görünmeli. Bu, kimliğin kendisidir — yetki ayrı ve kişiye
     * özeldir.
     *
     * Karşılığı olmayan slug'a DOKUNULMAZ: tanınmayan bir rolü varsayılana
     * çevirmek, kimsenin istemediği bir anda birinin kimliğini değiştirmek
     * olurdu. Yöneticilik yalnızca `kimlik.yonetici_devri` açıkken geçer;
     * merkezde rol dağıtabilen biri, buradaki hiçbir yöneticinin haberi
     * olmadan kendini burada da yönetici yapamamalı.
     */
    private function roluGuncelle(User $kullanici, ?string $slug): void
    {
        if (blank($slug)) {
            return;
        }

        /*
         * PANELDE ELLE VERİLEN ROLE DOKUNULMAZ.
         *
         * Bir kişi yalnızca bu panelde farklı bir rolde olabilir; merkezde
         * sıradan bir rolde olan biri burada yönetici olabilir. İşaret olmasaydı
         * yönetici rolü değiştirir, bir sonraki turda bu tur onu sessizce
         * geri alırdı.
         */
        if ($kullanici->rol_yerel) {
            return;
        }

        $rol = Rol::where('slug', $slug)->first();

        if ($rol === null || $kullanici->rol_id === $rol->id) {
            return;
        }

        if ($rol->yonetici && ! config('kimlik.yonetici_devri')) {
            return;
        }

        $eski = $kullanici->rol?->slug ?? 'rolsüz';

        $kullanici->forceFill(['rol_id' => $rol->id])->save();

        Log::info('Merkezdeki rol buraya işlendi.', [
            'kullanici' => $kullanici->email,
            'rol' => "{$eski} → {$rol->slug}",
        ]);
    }
}
