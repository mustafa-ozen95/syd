<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Models\ConsolePc;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Konsol PC'si. Dizin ve kullanıcı uzak makinedeki betiğe yazılıyor; biçimleri
 * dar tutulur (tırnak, noktalı virgül, `..` giremez).
 */
class PcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota kapısı soruyor (can:konsol:update)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $windows = $this->input('platform') === 'windows';
        $yeni = $this->route('pc') === null;

        return [
            'ad' => ['required', 'string', 'max:60'],
            'platform' => ['required', Rule::in(array_keys(ConsolePc::PLATFORMLAR))],
            'adres' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9.-]*$/'],
            'gorunen_adres' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'kullanici' => $windows
                ? ['required', 'string', 'max:64', 'regex:/^[\pL\pN][\pL\pN ._\\\\-]*$/u']
                : ['required', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'parola' => [$yeni ? 'required' : 'nullable', 'string', 'max:256'],
            'cihaz_ip' => ['required', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/([8-9]|[12]\d|30)$/'],
            /* Loader komutu yalnız IPv4 alır; kolon da 15 karakter. */
            'gecit' => ['required', 'ipv4'],
            /* Satır başına bir port; ajan betiğine ve agent.json'a yazılır: yalnız güvenli biçim. */
            'seri_portlar' => ['nullable', 'string', function (string $alan, mixed $deger, Closure $hata): void {
                $portlar = self::portlar((string) $deger);
                if (count($portlar) > 8) {
                    $hata('En çok 8 seri port.');
                }
                foreach ($portlar as $port) {
                    if (preg_match('/^(COM\d{1,3}|\/dev\/[A-Za-z0-9_.\/-]{1,50})$/', $port) !== 1 || str_contains($port, '..')) {
                        $hata("Seri port COM3 ya da /dev/ttyUSB0 biçiminde olmalı: {$port}");

                        return;
                    }
                }
            }],
            'dizin' => ['nullable', 'string', 'max:120', $windows
                ? 'regex:/^[A-Za-z]:\\\\[A-Za-z0-9 _.\\\\-]*$/'
                : 'regex:/^\/[A-Za-z0-9_.\/-]*$/', 'not_regex:/\.\./'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cihaz_ip.regex' => 'Switch\'e verilecek adres önekle yazılmalı: 198.51.100.120/24.',
            'dizin.regex' => 'Dizinde yalnızca harf, rakam, boşluk, nokta, tire ve alt çizgi olabilir.',
            'kullanici.regex' => 'Kullanıcı adında tırnak ya da noktalı virgül olamaz.',
            'adres.regex' => 'Adres bir IP ya da makine adı olmalı.',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $platform = (string) $this->input('platform');
        $varsayilan = ConsolePc::PLATFORMLAR[$platform];

        return array_filter([
            'ad' => $this->string('ad')->squish()->value(),
            'platform' => $platform,
            'adres' => $this->string('adres')->trim()->value(),
            'gorunen_adres' => $this->filled('gorunen_adres') ? $this->string('gorunen_adres')->trim()->value() : '',
            'port' => $this->filled('port') ? $this->integer('port') : $varsayilan['port'],
            'kullanici' => $this->string('kullanici')->trim()->value(),
            'parola' => $this->filled('parola') ? (string) $this->input('parola') : null,
            'cihaz_ip' => $this->string('cihaz_ip')->trim()->value(),
            'gecit' => $this->string('gecit')->trim()->value(),
            'seri_portlar' => $portlar = self::portlar((string) $this->input('seri_portlar')),
            /* İlk port eski alanda da durur: eski ajan.json ve tek portlu PC'ler. */
            'seri_port' => $portlar[0] ?? '',
            'dizin' => $this->filled('dizin') ? rtrim($this->string('dizin')->trim()->value(), '\\/') : $varsayilan['dizin'],
        ], fn ($v) => $v !== null);
    }

    /** @return list<string> */
    private static function portlar(string $metin): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $metin) ?: []), fn (string $p): bool => $p !== '')));
    }
}
