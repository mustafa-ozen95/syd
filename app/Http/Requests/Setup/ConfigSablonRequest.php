<?php

declare(strict_types=1);

namespace App\Http\Requests\Setup;

use App\ConsoleModule\Junos\YedekDosyasi;
use App\Models\ConfigSablonu;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Config şablonu. Dosya yeni şablonda zorunlu, düzenlemede isteğe bağlı
 * (boşsa mevcut metin kalır). Junos yapılandırması değilse (system bloğu
 * yok) reddedilir: yanlış dosya switch'e `load override` ile gitmesin.
 */
class ConfigSablonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:junos:create / update)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $sablon = $this->route('sablon');

        return [
            'ad' => ['required', 'string', 'max:120', 'regex:/^[\pL\pN][\pL\pN ._()-]*$/u',
                Rule::unique('config_sablonlari', 'ad')->ignore($sablon instanceof ConfigSablonu ? $sablon->id : null)],
            'sira' => ['nullable', 'integer', 'between:0,9999'],
            'dosya' => [$sablon instanceof ConfigSablonu ? 'nullable' : 'required', 'file', 'max:'.(YedekDosyasi::EN_BUYUK / 1024),
                function (string $alan, mixed $deger, Closure $hata): void {
                    if ($deger instanceof UploadedFile && YedekDosyasi::metin($deger) === null) {
                        $hata('Dosya bir Junos yapılandırması değil (system bloğu yok) ya da 8 MB\'tan büyük.');
                    }
                }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ad.regex' => 'Ad harf ya da rakamla başlamalı; harf, rakam, boşluk, nokta, tire ve parantez olabilir.',
            'ad.unique' => 'Bu adda bir şablon var.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ad' => 'ad', 'sira' => 'sıra', 'dosya' => 'yapılandırma dosyası'];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $veri = ['ad' => $this->string('ad')->squish()->value(), 'sira' => $this->integer('sira')];
        $dosya = $this->file('dosya');

        if ($dosya instanceof UploadedFile && ($metin = YedekDosyasi::metin($dosya)) !== null) {
            $veri += ['dosya' => mb_substr($dosya->getClientOriginalName(), 0, 200), 'metin' => $metin, 'boyut' => strlen($metin)];
        }

        return $veri;
    }
}
