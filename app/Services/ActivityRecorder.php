<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Denetim kaydını yazan TEK yer.
 *
 * `ActivityLog` doğrudan yazılmaz: zincir alanlarını yalnızca ActivityChain
 * dolduruyor ve modelin dışından yazılan bir satır zinciri kırardı. Bir
 * ekran kayıt tutmak istiyorsa buradan geçer.
 *
 * KAYIT SESSİZCE DÜŞMEZ ama İŞİ DE DÜŞÜRMEZ: zincir kilidi alınamazsa
 * (redis yoksa, kilit doluysa) olayın kendisi zaten olmuştur; kaydı
 * yazamamak yüzünden kullanıcıya hata göstermek, yapılan işi geri almaz.
 * Böyle bir durumda satır özetsiz yazılır ve doğrulama onu "korumasız"
 * olarak sayar — sessizce kaybolmasından iyidir.
 */
class ActivityRecorder
{
    public function __construct(private ActivityChain $zincir) {}

    /**
     * @param  string  $tur  olayın kısa adı; istatistik ekranı buna bakıyor
     */
    public function kaydet(
        string $tur,
        ?string $aciklama = null,
        ?Model $konu = null,
        ?User $kim = null,
    ): ?ActivityLog {
        $kullanici = $kim ?? Auth::user();

        $alanlar = [
            'user_id' => $kullanici?->getKey(),

            /*
             * Ad da yazılır: hesap sonradan silinse ya da adı değişse bile
             * satır kimin yaptığını söylemeye devam etmeli.
             */
            'kullanici_adi' => $kullanici?->name,
            'tur' => $tur,
            'aciklama' => $aciklama,
            'konu_type' => $konu?->getMorphClass(),
            'konu_id' => $konu?->getKey(),
            'ip_adresi' => Request::ip(),
        ];

        try {
            return $this->zincir->ekle($alanlar);
        } catch (\Throwable $e) {
            report($e);

            /* Özetsiz satır: doğrulama onu "korumasız" sayar, kaybolmaz. */
            return ActivityLog::create($alanlar);
        }
    }
}
