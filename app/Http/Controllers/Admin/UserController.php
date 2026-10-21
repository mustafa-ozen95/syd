<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\PermissionRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\ActivityRecorder;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Kullanıcı yönetimi.
 * Sadece admin rolü erişebilir ("admin" Gate'i, [[AppServiceProvider]]).
 */
class UserController extends Controller
{
    public function __construct(
        private UserPermissionResolver $yetki,
        private PermissionRegistry $sozluk,
    ) {}

    public function index(): View
    {
        $this->authorize('admin');

        $kullanicilar = User::with('rol')->orderBy('id')->get();

        return view('admin.users.index', [
            'kullanicilar' => $kullanicilar,
            /*
             * Her kişinin yetki özeti ve istisna tablosu hazır gelir: şablon
             * hesap yapmaz. Hücrede rolün ne dediği de yazar — "rolden"
             * seçeneği neyi kabul ettiğini göstermeli.
             */
            'yetkiler' => $kullanicilar->mapWithKeys(fn (User $k): array => [$k->id => [
                'ozet' => $k->isAdmin() ? 'hepsi' : (implode(', ', $this->yetki->ozet($k)) ?: '—'),
                'satirlar' => $this->istisnaSatirlari($k),
            ]])->all(),
            /*
             * Düzenleme penceresinin durumu: doğrulama hatasından sonra
             * yalnız gönderilen pencere eski girdiyle açık gelir.
             */
            'formlar' => $kullanicilar->mapWithKeys(fn (User $k): array => [$k->id => [
                'acik' => $acik = old('_form') === "duzenle-{$k->id}",
                'rol' => $acik ? old('rol') : $k->rol?->slug,
            ]])->all(),
            'roller' => Role::sirali()->get(),
            'yetkiTalepleri' => PermissionRequest::with('user')
                ->where('durum', 'bekliyor')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function update(UserUpdateRequest $request, User $kullanici): RedirectResponse
    {
        $validated = $request->validated();

        $oncekiRol = $kullanici->rol?->slug ?? 'rolsüz';
        $oncekiYetki = $this->yetkiOzeti($kullanici);
        $oncekiAktif = $kullanici->aktif;

        $kullanici->update([
            ...(($validated['yeni_sifre'] ?? null)
                ? ['password' => Hash::make($validated['yeni_sifre'])]
                : []),
            'name' => $validated['ad'],
            'email' => $validated['eposta'],
            'rol_id' => Role::where('slug', $validated['rol'])->value('id'),
            'rol_yerel' => true,
            /*
             * `?? null`: alan "nullable" ve form onu hiç göndermeyebiliyor.
             * Doğrudan indislemek "Undefined array key" ile düşüyordu —
             * tarayıcıdaki form alanı her zaman gönderdiği için görünmüyordu.
             */
            'aktif' => $request->boolean('aktif'),
        ]);

        $this->yetki->istisnalariYaz($kullanici, array_filter((array) $request->input('istisna', [])));

        /*
         * NE DEĞİŞTİĞİ YAZILIR, yeni hâli değil. "Kullanıcı güncellendi"
         * satırı denetimde hiçbir soruyu yanıtlamıyor; rolün ya da yetkinin
         * ne zaman kimin eliyle genişlediği tam olarak bu satırdan okunur.
         */
        $degisenler = [];

        $yeniRol = $kullanici->fresh()->rol?->slug ?? 'rolsüz';

        if ($oncekiRol !== $yeniRol) {
            $degisenler[] = "rol {$oncekiRol} → {$yeniRol}";
        }

        if ($oncekiAktif !== $kullanici->aktif) {
            $degisenler[] = $kullanici->aktif ? 'hesap açıldı' : 'hesap kapatıldı';
        }

        if ($oncekiYetki !== ($yeniYetki = $this->yetkiOzeti($kullanici))) {
            $degisenler[] = "yetkiler {$oncekiYetki} → {$yeniYetki}";
        }

        if ($validated['yeni_sifre'] ?? null) {
            /* Parolanın KENDİSİ değil, değiştiği yazılır. */
            $degisenler[] = 'parola verildi';
        }

        app(ActivityRecorder::class)->kaydet(
            'kullanici_guncelle',
            "Kullanıcı güncellendi: {$kullanici->email}"
                .($degisenler === [] ? ' · değişiklik yok' : ' · '.implode(', ', $degisenler)),
            $kullanici,
        );

        return redirect()->route('admin.kullanicilar.index')->with('status', 'Kullanıcı güncellendi.');
    }

    public function destroy(User $kullanici): RedirectResponse
    {
        $this->authorize('admin');

        /* Kayıt SİLMEDEN ÖNCE: silinen hesabın adı sonradan okunamaz. */
        app(ActivityRecorder::class)->kaydet(
            'kullanici_sil',
            "Kullanıcı silindi: {$kullanici->email} · {$kullanici->name} · rol ".($kullanici->rol?->slug ?? 'rolsüz'),
        );

        $kullanici->delete();

        return redirect()->route('admin.kullanicilar.index')->with('status', 'Kullanıcı silindi.');
    }

    public function onaylaYetkiTalebi(PermissionRequest $yetkiTalebi): RedirectResponse
    {
        $this->authorize('admin');

        /*
         * Talep "yedekleri görmek" içindir: onay görme + indirme İSTİSNASI
         * yazar (eski bayrak ikisini birden açıyordu). "Hiçbiri" talebi
         * kişinin yedek istisnalarını kaldırır; rol neyse o kalır.
         */
        $istisnalar = array_filter(
            $this->yetki->istisnalar($yetkiTalebi->user),
            fn (string $cift): bool => ! str_starts_with($cift, 'yedek:'),
            ARRAY_FILTER_USE_KEY,
        );

        if ($yetkiTalebi->perm_yedek) {
            $istisnalar['yedek:view'] = UserPermission::IZIN;
            $istisnalar['yedek:download'] = UserPermission::IZIN;
        }

        $this->yetki->istisnalariYaz($yetkiTalebi->user, $istisnalar);

        $yetkiTalebi->update(['durum' => 'onaylandi']);

        app(ActivityRecorder::class)->kaydet(
            'yetki_talebi_onay',
            "Yetki talebi onaylandı: {$yetkiTalebi->user->email} · yeni yetkiler "
                .$this->yetkiOzeti($yetkiTalebi->user->fresh()),
            $yetkiTalebi->user,
        );

        return redirect()->route('admin.kullanicilar.index')->with('status', 'Yetki talebi onaylandı.');
    }

    public function reddetYetkiTalebi(PermissionRequest $yetkiTalebi): RedirectResponse
    {
        $this->authorize('admin');

        $yetkiTalebi->update(['durum' => 'reddedildi']);

        return redirect()->route('admin.kullanicilar.index')->with('status', 'Yetki talebi reddedildi.');
    }

    /**
     * Yetkilerin tek satırlık özeti; denetim kaydında "önce/sonra"
     * karşılaştırmak için. Tek yerde üretilir ki iki satır aynı biçimde
     * yazılsın ve karşılaştırma anlamlı olsun.
     */
    /**
     * İstisna tablosunun satırları: her "kaynak:aksiyon" için rolün dediği
     * ve kişide seçili olan.
     *
     * @return list<array{cift: string, etiket: string, rol: string, secili: string}>
     */
    private function istisnaSatirlari(User $kullanici): array
    {
        $istisnalar = $this->yetki->istisnalar($kullanici);
        $satirlar = [];

        foreach ($this->sozluk->resourceKeys() as $kaynak) {
            foreach ($this->sozluk->actionsFor($kaynak) as $aksiyon) {
                $cift = $kaynak.':'.$aksiyon;
                $satirlar[] = [
                    'cift' => $cift,
                    'etiket' => $this->sozluk->label($kaynak).' · '.$this->sozluk->actionLabel($aksiyon),
                    'rol' => $kullanici->rol?->verir($kaynak, $aksiyon) ? 'rol verir' : 'rol vermez',
                    'secili' => $istisnalar[$cift] ?? '',
                ];
            }
        }

        return $satirlar;
    }

    private function yetkiOzeti(User $kullanici): string
    {
        $this->yetki->flush($kullanici);
        $ozet = $this->yetki->ozet($kullanici->load('rol'));

        return $ozet === [] ? 'yok' : implode(', ', $ozet);
    }
}
