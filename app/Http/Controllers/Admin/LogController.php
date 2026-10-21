<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ErrorLog;
use App\Services\Activity\ActivityChain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Denetim ve hata kayıtları — YALNIZCA yönetici.
 *
 * Kayıt tutmak tek başına yetmiyor: kimse bakamıyorsa kayıt yok sayılır.
 * Bütünlük rozeti de bu ekranda durur.
 *
 * Yetkilendirme: HasMiddleware ile `can:admin`; controller'ın hiçbir metodu
 * yetkisiz çağrılamaz.
 */
class LogController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:admin')];
    }

    public function denetim(Request $request, ActivityChain $zincir): View
    {
        $tur = (string) $request->query('tur', '');
        $ara = trim((string) $request->query('ara', ''));

        return view('logs.audit', [
            'kayitlar' => ActivityLog::query()
                ->with('user')
                ->when($tur !== '', fn ($s) => $s->where('tur', $tur))
                ->when($ara !== '', fn ($s) => $s->where(fn ($i) => $i
                    ->where('aciklama', 'like', "%{$ara}%")
                    ->orWhere('kullanici_adi', 'like', "%{$ara}%")))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),

            'turler' => ActivityLog::query()
                ->select('tur')
                ->distinct()
                ->orderBy('tur')
                ->pluck('tur')
                ->all(),

            'tur' => $tur,
            'ara' => $ara,

            /*
             * Bütünlük rozeti SON doğrulamanın sonucundan basılır; ekran her
             * açılışta bütün kaydı yeniden özetlemez. Doğrulamayı zamanlayıcı
             * koşturuyor (denetim:dogrula, her gece 03:30).
             */
            'butunluk' => $zincir->sonDogrulama(),
        ]);
    }

    /**
     * Zinciri ŞİMDİ doğrula.
     *
     * Rozet gecelik turdan geliyor; bir şeyden şüphelenen kişi beklemek
     * zorunda kalmamalı. İş ağır olabilir, bu yüzden ayrı bir düğme.
     */
    public function dogrula(ActivityChain $zincir): RedirectResponse
    {
        $sonuc = $zincir->dogrula();

        return redirect()->route('admin.kayitlar.denetim')->with(
            $sonuc['saglam'] ? 'status' : 'error',
            $sonuc['saglam']
                ? "Zincir sağlam: {$sonuc['bakilan']} kayıt doğrulandı."
                : "ZİNCİR KIRIK. İlk tutmayan kayıt: #{$sonuc['kirik_id']}",
        );
    }

    public function hatalar(): View
    {
        return view('logs.errors', [
            'hatalar' => ErrorLog::query()
                ->with('user')
                ->orderByDesc('last_seen_at')
                ->paginate(50),
        ]);
    }
}
