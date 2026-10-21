<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\NameRequest;
use App\Models\PermissionRequest;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Oturum açan kullanıcının kendi hesabı: kullanıcı adı, parola ve yetki
 * talebi.
 *
 * Yetkilendirme: bütün uçlar `auth` altında ve hedef İSTEKTEN DEĞİL
 * oturumdan geliyor — kişi yalnızca kendi hesabına dokunabiliyor, başkasının
 * kaydını göstermenin bir yolu yok. Rol ve gerçek yetkiler (perm_*) burada DEĞİŞTİRİLEMEZ - kullanıcı
 * yalnızca ne istediğini işaretleyip admin onayına gönderebilir
 * (bkz. PermissionRequest, admin/kullanicilar sayfasında onaylanır/reddedilir).
 */
class ProfileController extends Controller
{
    public function show(): View
    {
        $bekleyenTalep = auth()->user()
            ->permissionRequests()
            ->where('durum', 'bekliyor')
            ->latest('created_at')
            ->first();

        $yetki = app(UserPermissionResolver::class);
        $ozet = $yetki->ozet(auth()->user());

        return view('profile.index', [
            'bekleyenTalep' => $bekleyenTalep,
            'yetkiOzeti' => $ozet === [] ? 'yok' : implode(', ', $ozet),
            'yedekGorur' => $yetki->allows(auth()->user(), 'yedek', 'view'),
            /* Doğrulama hatası yalnız gönderilen formda görünür. */
            'buFormAd' => old('_form') === 'ad',
        ]);
    }

    public function adGuncelle(NameRequest $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validated();

        $user->update(['name' => $validated['ad']]);

        return redirect()->route('profil.show')->with('status', 'Adınız güncellendi.');
    }

    public function yetkiTalebiGonder(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->permissionRequests()->where('durum', 'bekliyor')->exists()) {
            return redirect()->route('profil.show')
                ->with('error', 'Zaten onay bekleyen bir yetki talebiniz var.');
        }

        PermissionRequest::create([
            'user_id' => $user->id,
            'perm_yedek' => $request->boolean('perm_yedek'),
        ]);

        return redirect()->route('profil.show')->with('status', 'Yetki talebiniz yöneticinize gönderildi.');
    }
}
