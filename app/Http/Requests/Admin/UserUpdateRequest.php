<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kullanıcı güncelleme — YALNIZCA yönetici.
 */
class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            /* Gerekçesi KullaniciEkleRequest'te; kural aynı olmak zorunda. */
            'ad' => ['required', 'string', 'max:100'],

            'eposta' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('kullanici')?->getKey()),
            ],
            'rol' => ['required', 'string', 'exists:roller,slug'],
            /*
             * PAROLA İSTEĞE BAĞLI: boş bırakılırsa hesabın parolası
             * DEĞİŞMEZ. Bu panelde hesapların çoğu merkezden geliyor ve
             * parolası yok; parola yalnızca merkez erişilemez olduğunda
             * girilebilsin diye elle veriliyor.
             */
            'yeni_sifre' => ['nullable', 'string', 'min:12'],

            'aktif' => ['boolean'],

            /*
             * Kişiye özel İSTİSNALAR: "kaynak:aksiyon" => izin | yasak.
             * Boş değer "rolden" demektir ve satır yazılmaz; sözlükte
             * olmayan çiftleri çözümleyici zaten atar.
             */
            'istisna' => ['array'],
            'istisna.*' => ['nullable', 'in:izin,yasak'],
        ];
    }

    /**
     * Parola alanı FORMDAN GELMEYEBİLİR ve "nullable" onu var saymaz.
     * Doğrudan indislemek "Undefined array key" ile düşüyordu; burada
     * bir kez normalleştirilir, controller indisleme derdine girmez.
     */
    protected function prepareForValidation(): void {}
}
