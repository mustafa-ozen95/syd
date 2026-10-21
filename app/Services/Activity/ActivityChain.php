<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Cache;

/**
 * Denetim kaydını değiştirilemez kılan hash zinciri.
 *
 * Her satır kendinden öncekinin özetini taşır ve kendi özeti bir sonrakinin
 * içine girer. Bir satır sonradan değiştirilirse ya da silinirse ondan
 * sonraki bütün özetler tutmaz; doğrulama kırılmanın tam yerini söyler.
 * Amaç kaydı gizlemek değil, DEĞİŞTİRİLMEDİĞİNİ kanıtlayabilmektir.
 *
 * Özet düz SHA değil, gizli anahtarla üretilen HMAC-SHA256'dır. Düz özet
 * kullanılsaydı veritabanına yazabilen biri bir satırı değiştirip zinciri
 * baştan hesaplayabilirdi; anahtar olmadan bunu yapamaz.
 *
 * Sıra önemlidir: iki kayıt aynı anda yazılırsa ikisi de aynı "önceki"yi
 * okuyup zinciri çatallandırabilir. Bu yüzden okuma ve yazma tek kilidin
 * içindedir.
 *
 * TEK ZİNCİR. Ayrı bir sayfa erişimi zinciri, erişim kayıtları hacmin
 * çoğunu oluşturduğunda ve daha kısa saklandığında anlamlıdır. Burada sayfa
 * gezintisi kaydedilmiyor; ikinci bir zincir bakılacak bir parça daha olurdu.
 */
class ActivityChain
{
    private const LOCK = 'denetim:zincir';

    /**
     * Özet biçiminin güncel sürümü.
     *
     * Payload'a yeni bir alan eklendiğinde bu sayı artırılır ve eski sürüm
     * ozetle() içinde KORUNUR. Aksi hâlde biçim değişikliği bütün geçmişi
     * "kırık" gösterir ve zinciri sıfırlamak gerekir — sıfırlamak, zincirin
     * var olma sebebini ortadan kaldırır.
     */
    private const SURUM = 1;

    /** Kilit için beklenecek en fazla süre (saniye). */
    private const BEKLEME = 5;

    /**
     * Zincire yeni bir satır ekler.
     *
     * @param  array<string, mixed>  $alanlar
     */
    public function ekle(array $alanlar): ActivityLog
    {
        return Cache::lock(self::LOCK, 10)->block(self::BEKLEME, function () use ($alanlar): ActivityLog {
            /*
             * Zincirin ucu: en son ÖZETLİ satır. Zincirden önce yazılmış
             * satırlar arada durabilir; onların özeti yoktur ve ucu onlar
             * belirlemez.
             */
            $onceki = ActivityLog::query()
                ->whereNotNull('ozet')
                ->latest('id')
                ->value('ozet') ?? $this->capa();

            $log = new ActivityLog($alanlar);
            $log->created_at = $alanlar['created_at'] ?? now();
            $log->onceki_ozet = $onceki;
            $log->zincir_surumu = self::SURUM;
            $log->ozet = $this->ozetle($log, $onceki);
            $log->save();

            return $log;
        });
    }

    /**
     * Zinciri doğrular.
     *
     * Zincirden ÖNCE yazılmış satırlar (özeti olmayanlar) doğrulanmaz ve
     * doğrulanmış gibi de gösterilmez: sayıları ayrıca bildirilir. Onları
     * sonradan özetlemek dürüst olmazdı — o satırların değişmediğine dair
     * elimizde bir kanıt yok.
     *
     * @return array{saglam: bool, bakilan: int, korumasiz: int, kirik_id: ?int, zaman: string}
     */
    public function dogrula(): array
    {
        $onceki = $this->capa();
        $bakilan = 0;
        $korumasiz = 0;
        $kirik = null;

        ActivityLog::query()
            ->orderBy('id')
            ->chunkById(500, function ($satirlar) use (&$onceki, &$bakilan, &$korumasiz, &$kirik): bool {
                foreach ($satirlar as $log) {
                    if ($log->ozet === null) {
                        $korumasiz++;

                        continue;
                    }

                    $bakilan++;

                    if ($log->onceki_ozet !== $onceki || $log->ozet !== $this->ozetle($log, $onceki)) {
                        $kirik = $log->id;

                        return false;
                    }

                    $onceki = $log->ozet;
                }

                return true;
            });

        $sonuc = [
            'saglam' => $kirik === null,
            'bakilan' => $bakilan,
            'korumasiz' => $korumasiz,
            'kirik_id' => $kirik,
            'zaman' => now()->toIso8601String(),
        ];

        Cache::put((string) config('activity.verification_key'), $sonuc, now()->addWeek());

        return $sonuc;
    }

    /**
     * En son doğrulama sonucu. Ekran her açılışta bütün satırları yeniden
     * özetlemez; zamanlanmış doğrulamanın bıraktığı sonucu okur.
     *
     * @return array{saglam: bool, bakilan: int, korumasiz: int, kirik_id: ?int, zaman: string}|null
     */
    public function sonDogrulama(): ?array
    {
        $sonuc = Cache::get((string) config('activity.verification_key'));

        return is_array($sonuc) ? $sonuc : null;
    }

    /**
     * Budama sonrası zincirin yeni başlangıç noktası.
     *
     * Saklama süresi dolan satırlar silindiğinde kalan ilk satırın "önceki"si
     * artık veritabanında yoktur. Silinen son satırın özeti çapa olarak
     * saklanır ve doğrulama oradan devam eder; bu olmadan her budama zinciri
     * kırık gösterirdi.
     */
    public function capayiYaz(?string $ozet): void
    {
        Cache::forever((string) config('activity.chain_anchor_key'), $ozet);
    }

    private function capa(): ?string
    {
        $capa = Cache::get((string) config('activity.chain_anchor_key'));

        return is_string($capa) ? $capa : null;
    }

    /**
     * Satırın özeti.
     *
     * Özete giren alanlar kaydın ANLAMINI taşıyan alanlardır; biri
     * değiştirilirse özet tutmaz. Sıralama sabittir, aksi hâlde aynı satır
     * farklı özetler üretirdi.
     */
    private function ozetle(ActivityLog $log, ?string $onceki): string
    {
        $veri = implode('|', [
            $onceki ?? '',
            $log->created_at?->format('Y-m-d H:i:s.u') ?? '',
            (string) $log->user_id,
            (string) $log->kullanici_adi,
            (string) $log->konu_type,
            (string) $log->konu_id,
            (string) $log->tur,
            (string) $log->aciklama,
            (string) $log->ip_adresi,
        ]);

        return hash_hmac('sha256', $veri, (string) config('activity.chain_key'));
    }
}
