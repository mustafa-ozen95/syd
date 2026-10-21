<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Models\Role;
use App\Services\ActivityRecorder;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Settings\SettingsTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Rol yönetimi — yalnızca yönetici (rota: can:admin). kardeş
 * paneldeki ekranın aynısı.
 *
 * Rolün yetkileri CANLI: kaydedildiği an o roldeki herkes için geçerli.
 * Kişiye özel istisnalar Kullanıcılar ekranından verilir. Hangi rolün bu
 * panele girdiğine merkez (KYM) karar verir; burada yalnızca içeride ne
 * yapabildiği belirlenir.
 */
class RoleController extends Controller
{
    public function __construct(
        private PermissionRegistry $sozluk,
        private SettingsTabs $sekmeler,
        private ActivityRecorder $kayit,
    ) {}

    public function index(): View
    {
        return view('admin.roles.index', [
            'tabs' => $this->sekmeler->all('roller'),
            'roller' => Role::query()->withCount('kullanicilar')->sirali()->get()->map(fn (Role $rol): array => [
                'rol' => $rol,
                'ozet' => $this->ozet($rol),
                'silinebilir' => $rol->silinebilirMi(),
            ]),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', $this->formVerisi(null));
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $rol = Role::create([...$request->payload($this->sozluk), 'yonetici' => false, 'yerlesik' => false]);

        $this->kayit->kaydet('rol_ekle', "Rol açıldı: {$rol->label} ({$rol->slug}) · {$this->ozet($rol)}", $rol);

        return redirect()->route('admin.roller.index')->with('status', "{$rol->label} eklendi.");
    }

    public function edit(Role $rol): View
    {
        return view('admin.roles.form', $this->formVerisi($rol));
    }

    public function update(RoleRequest $request, Role $rol): RedirectResponse
    {
        $once = $this->ozet($rol);
        $veri = $request->payload($this->sozluk);

        /* Yönetici rolü yetki listesi tutmaz: her kontrolü zaten geçer. */
        if ($rol->yonetici) {
            unset($veri['yetkiler']);
        }

        $rol->update($veri);

        $sayi = $rol->kullanicilar()->count();
        $sonra = $this->ozet($rol->fresh());

        $this->kayit->kaydet(
            'rol_guncelle',
            "Rol güncellendi: {$rol->label}".($once !== $sonra ? " · yetkiler {$once} → {$sonra}" : '')
                .($sayi > 0 ? " · roldeki {$sayi} kullanıcıyı etkiler" : ''),
            $rol,
        );

        return redirect()->route('admin.roller.index')->with('status', $sayi > 0
            ? "{$rol->label} güncellendi; roldeki {$sayi} kullanıcı için hemen geçerli."
            : "{$rol->label} güncellendi.");
    }

    public function destroy(Role $rol): RedirectResponse
    {
        if (! $rol->silinebilirMi()) {
            return back()->withErrors(['rol' => 'Yerleşik rol ya da kullanıcısı olan rol silinemez.']);
        }

        $ad = $rol->label;
        $rol->delete();

        $this->kayit->kaydet('rol_sil', "Rol silindi: {$ad}");

        return redirect()->route('admin.roller.index')->with('status', "{$ad} silindi.");
    }

    /** Liste ve kayıttaki tek satırlık özet. */
    private function ozet(Role $rol): string
    {
        if ($rol->yonetici) {
            return 'her şeyi geçer';
        }

        $parcalar = [];

        foreach ($this->sozluk->resourceKeys() as $kaynak) {
            $aksiyonlar = array_map(
                fn (string $a): string => $this->sozluk->actionLabel($a),
                array_values(array_intersect($this->sozluk->actionsFor($kaynak), $rol->aksiyonlar($kaynak))),
            );

            if ($aksiyonlar !== []) {
                $parcalar[] = $this->sozluk->label($kaynak).': '.implode(', ', $aksiyonlar);
            }
        }

        return $parcalar === [] ? 'yetki yok' : implode(' · ', $parcalar);
    }

    /** @return array<string, mixed> */
    private function formVerisi(?Role $rol): array
    {
        $secili = (array) old('yetkiler', $rol?->yetkiler ?? []);

        return [
            'rol' => $rol,
            'tabs' => $this->sekmeler->all('roller'),
            'rozetler' => array_combine(Role::ROZETLER, array_map('ucfirst', Role::ROZETLER)),
            /* Matris satırları hazır: her hücrenin işaretli olup olmadığı burada hesaplanır. */
            'matris' => array_map(fn (string $kaynak): array => [
                'kaynak' => $kaynak,
                'etiket' => $this->sozluk->label($kaynak),
                'hucreler' => array_map(fn (string $aksiyon): array => [
                    'aksiyon' => $aksiyon,
                    'etiket' => $this->sozluk->actionLabel($aksiyon),
                    'secili' => in_array($aksiyon, (array) ($secili[$kaynak] ?? []), true),
                ], $this->sozluk->actionsFor($kaynak)),
            ], $this->sozluk->resourceKeys()),
        ];
    }
}
