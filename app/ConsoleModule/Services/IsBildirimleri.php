<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Models\ConsoleJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "İş bitti" bildirimi: kurulum saatler sürebiliyor; işi açan, sayfaya
 * bakmadan bittiğini öğrensin. Panelin her sayfası (console-notify.js)
 * yoklar; işi açana bir kez gösterilir.
 *
 * Yoklama aralığı sunucudan gelir: kişinin açık işi varken sık, yokken
 * seyrek. Bir gün içinde görülmeyen sonuç artık haber değildir.
 */
class IsBildirimleri
{
    private const ACIK_ISTE_SANIYE = 20;

    private const BOSTA_SANIYE = 120;

    /** @return array{bildirimler: list<array{mesaj: string, durum: string, url: string}>, sonraki: int} */
    public function al(User $kim): array
    {
        $isler = DB::transaction(function () use ($kim) {
            $isler = ConsoleJob::query()->where('olusturan_id', $kim->id)->whereIn('durum', ['tamam', 'hata'])
                ->whereNull('bildirildi_at')->where('updated_at', '>=', now()->subDay())
                ->orderBy('updated_at')->lockForUpdate()->get();
            ConsoleJob::query()->whereKey($isler->modelKeys())->update(['bildirildi_at' => now()]);

            return $isler;
        });

        return [
            'bildirimler' => $isler->map(fn (ConsoleJob $is): array => [
                'mesaj' => $is->durum === 'tamam'
                    ? "{$is->ad}: {$is->islemOzeti()} tamamlandı."
                    : "{$is->ad}: iş hatayla bitti. ".mb_substr((string) $is->son_mesaj, 0, 160),
                'durum' => $is->durum,
                'url' => route('console.show', $is),
            ])->values()->all(),
            'sonraki' => ConsoleJob::query()->acik()->where('olusturan_id', $kim->id)->exists() ? self::ACIK_ISTE_SANIYE : self::BOSTA_SANIYE,
        ];
    }
}
