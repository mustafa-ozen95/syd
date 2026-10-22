<?php

declare(strict_types=1);

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Junos görüntüsü.
 *
 * Dosya adı KOMUTUN PARÇASI: kullanıcı onu kopyalayıp switch'e yapıştırıyor.
 * Boşluk, eğik çizgi ya da kabuk karakteri giremez — "dosya.tgz; request
 * system zeroize" gibi bir ad, kopyalayan kişiye fark ettirmeden ikinci bir
 * komut çalıştırtırdı.
 *
 * Görüntü dosyası (goruntu) isteğe bağlıdır: yüklenirse TFTP dizinine bu
 * adla yazılır; dosya adı boşsa yüklenen dosyanın adı alınır ve aynı kurala
 * uyar (adı uymayan dosya, adı elle verilerek yüklenir).
 */
class JunosImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:junos:create / update)
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('dosya')) && $this->file('goruntu') instanceof UploadedFile) {
            $this->merge(['dosya' => $this->file('goruntu')->getClientOriginalName()]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'model' => ['required', 'string', 'max:60', 'regex:/^[\pL\pN][\pL\pN ._()\/-]*$/u'],
            'surum' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'dosya' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*$/'],
            'sira' => ['nullable', 'integer', 'between:0,9999'],
            'goruntu' => ['nullable', 'file', 'max:'.(config('junos.upload_max_mb') * 1024)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dosya.regex' => 'Dosya adında yalnızca harf, rakam, nokta, tire, alt çizgi ve artı olabilir; boşluk ve eğik çizgi olamaz.',
            'surum.regex' => 'Sürümde yalnızca harf, rakam, nokta, tire ve alt çizgi olabilir.',
            'model.regex' => 'Model adı harf ya da rakamla başlamalı.',
            'goruntu.uploaded' => 'Dosya yüklenemedi (boyut sınırı aşılmış ya da bağlantı kopmuş olabilir).',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['model' => 'model', 'surum' => 'sürüm', 'dosya' => 'dosya adı', 'sira' => 'sıra', 'goruntu' => 'görüntü dosyası'];
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

    public function goruntu(): ?UploadedFile
    {
        $dosya = $this->file('goruntu');

        return $dosya instanceof UploadedFile ? $dosya : null;
    }
}
