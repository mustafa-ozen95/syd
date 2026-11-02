<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Contracts\ImageSource;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\AgentJob;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yeni iş. Bir PC'de aynı anda TEK açık iş olur: kablo bir tane.
 */
class IsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:create)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $cihaz = (string) $this->input('ad');
        $yalnizYedek = $this->input('islem') === 'yedek';

        return [
            'islem' => ['nullable', Rule::in(array_keys(ConsoleJob::ISLEMLER))],
            'pc_id' => ['required', 'integer', Rule::exists('konsol_pcleri', 'id'), function (string $alan, mixed $deger, Closure $hata): void {
                if (ConsoleJob::query()->acik()->where('pc_id', (int) $deger)->exists()) {
                    $hata('Bu PC\'de açık bir iş var; konsol kablosu bir tane. Önce onu kapatın.');
                }
            }],
            /*
             * Cihaz listeden (yedek klasörleri) ya da boş. Boşsa switch fabrika
             * ayarında kalır; seçilirse o cihazın bir yedeği yüklenir. Yalnız
             * yedek yüklemede cihaz şart: yüklenecek başka bir şey yok.
             */
            'ad' => [Rule::requiredIf($yalnizYedek), 'nullable', 'string', 'max:120', function (string $alan, mixed $deger, Closure $hata): void {
                if ((string) $deger !== '' && ! in_array((string) $deger, app(ConfigurationSource::class)->cihazlar(), true)) {
                    $hata('Cihazı listeden seçin.');
                }
            }],
            'adres_turu' => ['nullable', Rule::in(array_keys(ConsoleJob::ADRES_TURLERI))],
            'cihaz_ip' => ['nullable', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/([8-9]|[12]\d|30)$/'],
            'gecit' => ['nullable', 'ip'],
            'goruntu' => [Rule::requiredIf(! $yalnizYedek), 'nullable', 'string', function (string $alan, mixed $deger, Closure $hata) use ($yalnizYedek): void {
                if (! $yalnizYedek && app(ImageSource::class)->bul((string) $deger) === null) {
                    $hata('Görüntü listede yok.');
                }
            }],
            'yedek_dosya' => [Rule::requiredIf($cihaz !== ''), 'nullable', 'string', 'max:255',
                function (string $alan, mixed $deger, Closure $hata) use ($cihaz): void {
                    if ($cihaz !== '' && ! in_array((string) $deger, array_column(app(ConfigurationSource::class)->yedekler($cihaz), 'dosya'), true)) {
                        $hata('Bu yedek dosyası o cihazın klasöründe yok.');
                    }
                }],
            'root_parola' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ad.required' => 'Yalnız yedek yüklemede yedeği yüklenecek cihazı seçin.',
            'cihaz_ip.regex' => 'Adres önekle yazılmalı: 198.51.100.120/24.',
            'yedek_dosya.required' => 'Yüklenecek yedeği seçin.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $yalnizYedek = $this->input('islem') === 'yedek';
        $g = $yalnizYedek ? null : app(ImageSource::class)->bul((string) $this->input('goruntu'));
        $cihaz = $this->string('ad')->value();

        $pc = $this->pc();
        $dhcp = $this->input('adres_turu') === 'dhcp';

        $kurulum = $g === null ? [
            /* Yalnız yedek: görüntü ve loader adresi yok. */
            'adres_turu' => 'sabit',
            'cihaz_ip' => null,
            'gecit' => null,
            'goruntu_etiket' => null,
            'goruntu_adres' => null,
            'beklenen_surum' => null,
        ] : [
            /* Boş bırakılırsa PC kaydındaki adres. */
            'adres_turu' => $dhcp ? 'dhcp' : 'sabit',
            'cihaz_ip' => $dhcp ? null : ($this->string('cihaz_ip')->trim()->value() ?: $pc->cihaz_ip),
            'gecit' => $dhcp ? null : ($this->string('gecit')->trim()->value() ?: $pc->gecit),
            'goruntu_etiket' => $g['etiket'],
            'goruntu_adres' => $g['adres'],
            'beklenen_surum' => AgentJob::surum($g['dosya']),
        ];

        return $kurulum + [
            'pc_id' => $pc->id,
            'islem' => $yalnizYedek ? 'yedek' : 'kurulum',
            'ad' => $cihaz ?: 'Yeni cihaz',
            'root_parola' => $this->filled('root_parola') ? (string) $this->input('root_parola') : null,
            /* Cihaz seçildiyse onun seçilen yedeği, seçilmediyse fabrika ayarı. */
            'yapilandirma_turu' => $cihaz !== '' ? 'yedek' : 'yok',
            'yedek_cihaz' => $cihaz ?: null,
            'yedek_dosya' => $cihaz !== '' ? $this->string('yedek_dosya')->value() : null,
        ];
    }

    public function pc(): ConsolePc
    {
        return ConsolePc::query()->findOrFail($this->integer('pc_id'));
    }
}
