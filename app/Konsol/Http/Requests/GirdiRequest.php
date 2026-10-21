<?php

declare(strict_types=1);

namespace App\Konsol\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Panelden konsola yazılan: bir satır (Enter'la gider) ya da tek tuş. */
class GirdiRequest extends FormRequest
{
    public const TUSLAR = [
        'enter' => "\r",
        'bosluk' => ' ',
        'ctrl-c' => "\x03",
        'ctrl-d' => "\x04",
    ];

    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:create)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'satir' => ['required_without:tus', 'nullable', 'string', 'max:1000'],
            'tus' => ['required_without:satir', 'nullable', Rule::in(array_keys(self::TUSLAR))],
        ];
    }

    public function metin(): string
    {
        return $this->filled('tus')
            ? self::TUSLAR[(string) $this->input('tus')]
            : str_replace(["\r\n", "\n"], "\r", (string) $this->input('satir'))."\r";
    }
}
