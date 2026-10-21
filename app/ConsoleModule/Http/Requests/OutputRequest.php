<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:ajan)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /* Ajan panele ulaşamazken biriken çıktı bir seferde gelebilir (en çok 256 KB). */
        return ['metin' => ['present', 'nullable', 'string', 'max:300000']];
    }
}
