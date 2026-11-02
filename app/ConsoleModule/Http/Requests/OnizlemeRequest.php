<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Junos\YedekDosyasi;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

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
        $yuklendi = $this->hasFile('yedek_yukle');

        return [
            'yedek_yukle' => ['nullable', 'file', 'max:'.(YedekDosyasi::EN_BUYUK / 1024), function (string $alan, mixed $deger, Closure $hata): void {
                if ($deger instanceof UploadedFile && YedekDosyasi::metin($deger) === null) {
                    $hata('Dosya bir Junos yapılandırması değil (system bloğu yok) ya da 8 MB\'tan büyük.');
                }
            }],
            'ad' => [Rule::requiredIf(! $yuklendi), 'nullable', 'string', function (string $alan, mixed $deger, Closure $hata) use ($kaynak): void {
                if ((string) $deger !== '' && ! in_array((string) $deger, $kaynak->cihazlar(), true)) {
                    $hata('Cihazı listeden seçin.');
                }
            }],
            'yedek_dosya' => [Rule::requiredIf(! $yuklendi), 'nullable', 'string', function (string $alan, mixed $deger, Closure $hata) use ($kaynak, $yuklendi): void {
                if (! $yuklendi && ! in_array((string) $deger, array_column($kaynak->yedekler((string) $this->input('ad')), 'dosya'), true)) {
                    $hata('Bu yedek dosyası o cihazın klasöründe yok.');
                }
            }],
        ] + $this->ozellestirmeKurallari();
    }

    /** Yüklenen yedeğin metni; yoksa null (listedeki cihazın dosyası kullanılır). */
    public function yedekMetni(): ?string
    {
        return $this->hasFile('yedek_yukle') ? YedekDosyasi::metin($this->file('yedek_yukle')) : null;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->ozellestirmeIletileri();
    }
}
