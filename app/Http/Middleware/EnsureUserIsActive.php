<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasif hesabın AÇIK OTURUMU bir sonraki istekte biter.
 *
 * NEDEN VAR: `aktif` yalnızca GİRİŞTE sorulıyordu (bkz. AuthController).
 * Erişimi kaldırılan biri, o sırada oturumu açıksa, sekmesini kapatmadığı
 * sürece panelde gezinmeye devam ediyordu — yedek indirebiliyor, sayfa
 * listesini görebiliyordu.
 *
 * Bu ara katman her istekte sorar: durum hangi yoldan değişirse değişsin
 * (panelden, merkezden gelen eşitlemeyle ya da doğrudan veritabanından),
 * pasif hesap içeride kalamaz.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $kullanici = $request->user();

        if ($kullanici !== null && ! $kullanici->aktif) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Hesabınız etkin değil. Lütfen yöneticinizle görüşün.']);
        }

        return $next($request);
    }
}
