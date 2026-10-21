<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Models\ConsoleJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:ajan)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'asama' => ['required', Rule::in(array_keys(ConsoleJob::ASAMALAR))],
            'mesaj' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
