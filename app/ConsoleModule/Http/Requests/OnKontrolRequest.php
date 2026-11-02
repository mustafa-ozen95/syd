<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Models\ConsolePc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "Kontrol et": iş formunun PC, port ve adres alanları. Adres PC'deki
 * komuta (ping) yazılır: yalnız IPv4.
 */
class OnKontrolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:create)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pc' => ['required', 'uuid', Rule::exists('konsol_pcleri', 'uuid')],
            'seri_port' => ['nullable', 'string', 'max:200'],
            'adres_turu' => ['nullable', 'string'],
            'islem' => ['nullable', 'string'],
            'cihaz_ip' => ['nullable', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}(\/\d{1,2})?$/'],
        ];
    }

    public function pc(): ConsolePc
    {
        return ConsolePc::query()->where('uuid', $this->string('pc')->value())->firstOrFail();
    }

    /** Loader'da switch'e verilecek adres (önek olmadan); DHCP'de, CLI kurulumunda ve yalnız yedekte yok. */
    public function adres(): ?string
    {
        if ($this->input('adres_turu') === 'dhcp' || in_array($this->input('islem'), ['yedek', 'cli'], true)) {
            return null;
        }

        $adres = explode('/', $this->string('cihaz_ip')->trim()->value() ?: (string) $this->pc()->cihaz_ip)[0];

        return filter_var($adres, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $adres : null;
    }
}
