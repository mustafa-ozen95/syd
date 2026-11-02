<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FinalConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:ajan)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /* Ajan 4 MB'a kadar toplar; büyük bir QFX yapılandırması bile 2 MB'ı geçmez. */
        return ['text' => ['required', 'string', 'max:2000000']];
    }
}
