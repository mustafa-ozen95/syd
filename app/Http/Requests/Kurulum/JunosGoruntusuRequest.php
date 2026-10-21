<?php

declare(strict_types=1);

namespace App\Http\Requests\Kurulum;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Junos görüntüsü.
 *
 * Dosya adı KOMUTUN PARÇASI: kullanıcı onu kopyalayıp switch'e yapıştırıyor.
 * Boşluk, eğik çizgi ya da kabuk karakteri giremez — "dosya.tgz; request
 * system zeroize" gibi bir ad, kopyalayan kişiye fark ettirmeden ikinci bir
 * komut çalıştırtırdı.
 */
class JunosGoruntusuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:junos:create / update)
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'model' => ['required', 'string', 'max:60', 'regex:/^[\pL\pN][\pL\pN ._()\/-]*$/u'],
            'surum' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'dosya' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*$/'],
            'sira' => ['nullable', 'integer', 'between:0,9999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dosya.regex' => 'Dosya adında yalnızca harf, rakam, nokta, tire, alt çizgi ve artı olabilir; boşluk ve eğik çizgi olamaz.',
            'surum.regex' => 'Sürümde yalnızca harf, rakam, nokta, tire ve alt çizgi olabilir.',
            'model.regex' => 'Model adı harf ya da rakamla başlamalı.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['model' => 'model', 'surum' => 'sürüm', 'dosya' => 'dosya adı', 'sira' => 'sıra'];
    }

    /** @return array{model: string, surum: string, dosya: string, sira: int} */
    public function payload(): array
    {
        return [
            'model' => $this->string('model')->squish()->value(),
            'surum' => $this->string('surum')->trim()->value(),
            'dosya' => $this->string('dosya')->trim()->value(),
            'sira' => $this->integer('sira'),
        ];
    }
}
