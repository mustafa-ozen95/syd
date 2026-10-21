<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Giriş formu.
 *
 * Yetkilendirme: uç bilerek `auth` DIŞINDA — giriş yapmamış kullanıcının
 * erişebilmesi gerekiyor.
 */
class GirisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
