<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Models\ConsolePc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Portları listele": iş formunda seçili PC. */
class PortListeRequest extends FormRequest
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
        ];
    }

    public function pc(): ConsolePc
    {
        return ConsolePc::query()->where('uuid', $this->string('pc')->value())->firstOrFail();
    }
}
