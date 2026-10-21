<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kendi görünen adını değiştirme.
 *
 * E-POSTA BURADA DEĞİŞTİRİLEMEZ: giriş anahtarı ve merkezle eşleşmenin
 * kendisi o. Kişi kendi adresini yazabilseydi başkasının adresini koyup onun
 * merkez kimliğiyle bu hesaba girerdi. Adresi yönetici değiştirir.
 *
 * Yetkilendirme: hedef istekten değil OTURUMDAN gelir.
 */
class NameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'ad' => ['required', 'string', 'max:100'],
        ];
    }
}
