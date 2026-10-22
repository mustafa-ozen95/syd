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
            'roller' => Role::query()->withCount('kullanicilar')->sirali()->get()->map(fn (Role $role): array => [
                'rol' => $role,
                'ozet' => $this->ozet($role),
                'silinebilir' => $role->silinebilirMi(),
            ]),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.form', $this->formVerisi(null));
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = Role::create([...$request->payload($this->sozluk), 'yonetici' => false, 'yerlesik' => false]);

        $this->kayit->kaydet('rol_ekle', "Rol açıldı: {$role->label} ({$role->slug}) · {$this->ozet($role)}", $role);

        return redirect()->route('admin.roles.index')->with('status', "{$role->label} eklendi.");
    }

    public function edit(Role $role): View
    {
        return view('admin.roles.form', $this->formVerisi($role));
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $once = $this->ozet($role);
        $veri = $request->payload($this->sozluk);

        /* Yönetici rolü yetki listesi tutmaz: her kontrolü zaten geçer. */
        if ($role->yonetici) {
            unset($veri['yetkiler']);
        }

        $role->update($veri);

        $sayi = $role->kullanicilar()->count();
        $sonra = $this->ozet($role->fresh());

        $this->kayit->kaydet(
            'rol_guncelle',
            "Rol güncellendi: {$role->label}".($once !== $sonra ? " · yetkiler {$once} → {$sonra}" : '')
                .($sayi > 0 ? " · roldeki {$sayi} kullanıcıyı etkiler" : ''),
            $role,
        );

        return redirect()->route('admin.roles.index')->with('status', $sayi > 0
            ? "{$role->label} güncellendi; roldeki {$sayi} kullanıcı için hemen geçerli."
            : "{$role->label} güncellendi.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (! $role->silinebilirMi()) {
            return back()->withErrors(['rol' => 'Yerleşik rol ya da kullanıcısı olan rol silinemez.']);
        }

        $ad = $role->label;
        $role->delete();

        $this->kayit->kaydet('rol_sil', "Rol silindi: {$ad}");

        return redirect()->route('admin.roles.index')->with('status', "{$ad} silindi.");
    }

    /** Liste ve kayıttaki tek satırlık özet. */
    private function ozet(Role $role): string
    {
        if ($role->yonetici) {
            return 'her şeyi geçer';
        }

        $parcalar = [];

        foreach ($this->sozluk->resourceKeys() as $kaynak) {
            $aksiyonlar = array_map(
                fn (string $a): string => $this->sozluk->actionLabel($a),
                array_values(array_intersect($this->sozluk->actionsFor($kaynak), $role->aksiyonlar($kaynak))),
            );

            if ($aksiyonlar !== []) {
                $parcalar[] = $this->sozluk->label($kaynak).': '.implode(', ', $aksiyonlar);
            }
        }

        return $parcalar === [] ? 'yetki yok' : implode(' · ', $parcalar);
    }

    /** @return array<string, mixed> */
    private function formVerisi(?Role $role): array
    {
        $secili = (array) old('yetkiler', $role?->yetkiler ?? []);

        return [
            'rol' => $role,
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
