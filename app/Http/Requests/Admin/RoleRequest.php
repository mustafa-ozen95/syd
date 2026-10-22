<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Services\Authorization\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rol formu.
 *
 * Kısa ad (slug) merkezle eşleşmenin anahtarı: merkez rol değişikliğini bu
 * adla bildiriyor. Yeni rolde verilir, sonra DEĞİŞMEZ — değişseydi o
 * roldeki kişiler bir sonraki eşitlemede sessizce rolsüz kalırdı.
 *
 * Yetkiler sözlükteki çiftlerle sınırlı; tanımsız satır atılır.
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $yeni = $this->route('role') === null;

        return [
            'slug' => $yeni
                ? ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('roller', 'slug')]
                : ['prohibited'],
            'label' => ['required', 'string', 'max:60'],
            'aciklama' => ['nullable', 'string', 'max:190'],
            'badge' => ['required', Rule::in(Role::ROZETLER)],
            'sira' => ['nullable', 'integer', 'between:0,9999'],
            'yetkiler' => ['array'],
            'yetkiler.*' => ['array'],
            'yetkiler.*.*' => ['string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['slug' => 'kısa ad', 'label' => 'ad', 'aciklama' => 'açıklama', 'badge' => 'renk', 'sira' => 'sıra'];
    }

    /** @return array<string, mixed> */
    public function payload(PermissionRegistry $sozluk): array
    {
        $yetkiler = [];

        foreach ((array) $this->input('yetkiler', []) as $kaynak => $aksiyonlar) {
            $kabul = array_values(array_filter(
                (array) $aksiyonlar,
                fn (mixed $a): bool => is_string($kaynak) && is_string($a) && $sozluk->supports($kaynak, $a),
            ));

            if ($kabul !== []) {
                $yetkiler[$kaynak] = $kabul;
            }
        }

        return array_filter([
            'slug' => $this->route('role') === null ? $this->string('slug')->value() : null,
            'label' => $this->string('label')->squish()->value(),
            'aciklama' => $this->string('aciklama')->squish()->value() ?: null,
            'badge' => $this->string('badge')->value(),
            'sira' => $this->integer('sira'),
            'yetkiler' => $yetkiler,
        ], fn (mixed $v, string $k): bool => $k !== 'slug' || $v !== null, ARRAY_FILTER_USE_BOTH);
    }
}
