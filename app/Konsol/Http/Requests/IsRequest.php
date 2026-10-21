<?php

declare(strict_types=1);

namespace App\Konsol\Http\Requests;

use App\Konsol\Contracts\GoruntuKaynagi;
use App\Konsol\Contracts\YapilandirmaKaynagi;
use App\Konsol\Models\KonsolIsi;
use App\Konsol\Models\KonsolPc;
use App\Konsol\Services\AjanIsi;
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
        $yapilandirma = (string) $this->input('yapilandirma_turu');
        $secilen = $yapilandirma === 'yedek';

        return [
            'pc_id' => ['required', 'integer', Rule::exists('konsol_pcleri', 'id'), function (string $alan, mixed $deger, Closure $hata): void {
                if (KonsolIsi::query()->acik()->where('pc_id', (int) $deger)->exists()) {
                    $hata('Bu PC\'de açık bir iş var; konsol kablosu bir tane. Önce onu kapatın.');
                }
            }],
            /*
             * Cihaz listeden (yedek klasörleri) ya da boş: yedeği olmayan yeni
             * bir switch de kurulabilir. "En son
             * yedek" o cihazın yedeğidir, onda seçim şart.
             */
            'ad' => [Rule::requiredIf($yapilandirma === 'son_yedek'), 'nullable', 'string', 'max:120', function (string $alan, mixed $deger, Closure $hata): void {
                if ((string) $deger !== '' && ! in_array((string) $deger, app(YapilandirmaKaynagi::class)->cihazlar(), true)) {
                    $hata('Cihazı listeden seçin.');
                }
            }],
            'adres_turu' => ['nullable', Rule::in(array_keys(KonsolIsi::ADRES_TURLERI))],
            'cihaz_ip' => ['nullable', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/([8-9]|[12]\d|30)$/'],
            'gecit' => ['nullable', 'ip'],
            'goruntu' => ['required', 'string', function (string $alan, mixed $deger, Closure $hata): void {
                if (app(GoruntuKaynagi::class)->bul((string) $deger) === null) {
                    $hata('Görüntü listede yok.');
                }
            }],
            'yapilandirma_turu' => ['required', Rule::in(array_keys(KonsolIsi::YAPILANDIRMA_TURLERI))],
            'yedek_cihaz' => [Rule::requiredIf($secilen), 'nullable', 'string', 'max:120', function (string $alan, mixed $deger, Closure $hata) use ($secilen): void {
                if ($secilen && ! in_array((string) $deger, app(YapilandirmaKaynagi::class)->cihazlar(), true)) {
                    $hata('Bu cihazın yedeği yok.');
                }
            }],
            'yedek_dosya' => [Rule::requiredIf($secilen), 'nullable', 'string', 'max:255',
                function (string $alan, mixed $deger, Closure $hata) use ($secilen): void {
                    if ($secilen && ! in_array((string) $deger, array_column(app(YapilandirmaKaynagi::class)->yedekler((string) $this->input('yedek_cihaz')), 'dosya'), true)) {
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
            'ad.required' => '"Cihazın en son yedeği" için cihazı seçin.',
            'cihaz_ip.regex' => 'Adres önekle yazılmalı: 198.51.100.120/24.',
            'yedek_cihaz.required' => 'Yedeği yüklenecek cihazı seçin.',
            'yedek_dosya.required' => 'Yüklenecek yedeği seçin.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $g = app(GoruntuKaynagi::class)->bul((string) $this->input('goruntu'));
        $yapilandirma = (string) $this->input('yapilandirma_turu');

        $pc = $this->pc();
        $dhcp = $this->input('adres_turu') === 'dhcp';

        return [
            'pc_id' => $pc->id,
            'ad' => $this->string('ad')->value() ?: 'Yeni cihaz',
            /* Boş bırakılırsa PC kaydındaki adres. */
            'adres_turu' => $dhcp ? 'dhcp' : 'sabit',
            'cihaz_ip' => $dhcp ? null : ($this->string('cihaz_ip')->trim()->value() ?: $pc->cihaz_ip),
            'gecit' => $dhcp ? null : ($this->string('gecit')->trim()->value() ?: $pc->gecit),
            'root_parola' => $this->filled('root_parola') ? (string) $this->input('root_parola') : null,
            'goruntu_etiket' => $g['etiket'],
            'goruntu_adres' => $g['adres'],
            'beklenen_surum' => AjanIsi::surum($g['dosya']),
            'yapilandirma_turu' => $yapilandirma,
            'yedek_cihaz' => match ($yapilandirma) {
                'son_yedek' => $this->string('ad')->value(),
                'yedek' => $this->string('yedek_cihaz')->value(),
                default => null,
            },
            'yedek_dosya' => $yapilandirma === 'yedek' ? $this->string('yedek_dosya')->value() : null,
        ];
    }

    public function pc(): KonsolPc
    {
        return KonsolPc::query()->findOrFail($this->integer('pc_id'));
    }
}
