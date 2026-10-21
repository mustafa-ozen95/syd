<?php

declare(strict_types=1);

namespace App\Konsol\Http\Requests;

use App\Konsol\Models\KonsolIsi;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OlayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:ajan)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asama' => ['required', Rule::in(array_keys(KonsolIsi::ASAMALAR))],
            'mesaj' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
