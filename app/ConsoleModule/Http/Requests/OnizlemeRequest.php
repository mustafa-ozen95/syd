<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Contracts\ConfigurationSource;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Yeni iş formunda "Önizle": seçilen yedeğe uygulanacak yamayı gösterir.
 * Kurallar iş açan istekle ortak (OzellestirmeAlanlari).
 */
class OnizlemeRequest extends FormRequest
{
    use OzellestirmeAlanlari;

    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:create)
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['ozellestir' => true]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $kaynak = app(ConfigurationSource::class);

        return [
            'ad' => ['required', 'string', function (string $alan, mixed $deger, Closure $hata) use ($kaynak): void {
                if (! in_array((string) $deger, $kaynak->cihazlar(), true)) {
                    $hata('Cihazı listeden seçin.');
                }
            }],
            'yedek_dosya' => ['required', 'string', function (string $alan, mixed $deger, Closure $hata) use ($kaynak): void {
                if (! in_array((string) $deger, array_column($kaynak->yedekler((string) $this->input('ad')), 'dosya'), true)) {
                    $hata('Bu yedek dosyası o cihazın klasöründe yok.');
                }
            }],
        ] + $this->ozellestirmeKurallari();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->ozellestirmeIletileri();
    }
}
