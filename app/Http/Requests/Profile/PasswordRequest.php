<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kendi parolasını değiştirme.
 *
 * MEVCUT PAROLA SORULUR (`current_password`): açık bırakılmış bir sekmeyi
 * bulan biri, oturum sahibinin parolasını sessizce değiştirip hesabı
 * devralabilirdi.
 */
class PasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mevcut' => ['required', 'current_password'],
            'yeni_sifre' => ['required', 'string', 'min:'.config('panel.parola_uzunluk'), 'confirmed'],
        ];
    }
}
