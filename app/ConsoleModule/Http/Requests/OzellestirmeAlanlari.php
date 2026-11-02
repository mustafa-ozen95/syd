<?php

declare(strict_types=1);

namespace App\ConsoleModule\Http\Requests;

use App\ConsoleModule\Junos\Md5Crypt;
use App\ConsoleModule\Junos\PatchBuilder;
use Closure;

/**
 * "Yapılandırmayı özelleştir" alanlarının kuralları ve düzgün biçimi. İş açan
 * istek (IsRequest) ve önizleme (OnizlemeRequest) AYNI kuralları buradan
 * alır: önizlemede geçen bir değer iş açılırken reddedilmesin, ya da tersi.
 *
 * Değerler cihaza `set` satırı olarak gider: tırnak, noktalı virgül ve boru
 * karakteri kaçırılmaz, baştan reddedilir.
 */
trait OzellestirmeAlanlari
{
    /** Liste alanları: textarea, satır satır. */
    private const LISTELER = ['vlan0_adresleri', 're_filter', 'me_filter', 'koru', 'snmp_communityler', 'ek_komutlar'];

    /** @return array<string, mixed> */
    protected function ozellestirmeKurallari(): array
    {
        $oz = 'ozellestirme.';

        return [
            'ozellestir' => ['nullable', 'boolean'],
            $oz.'host_name' => ['nullable', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9.-]{0,62}$/'],
            $oz.'uplink_arayuz' => ['nullable', 'required_with:'.$oz.'uplink_adres', 'regex:/^(ge|xe|et)-\d{1,2}\/\d{1,2}\/\d{1,2}$/'],
            $oz.'uplink_adres' => ['nullable', 'required_with:'.$oz.'uplink_arayuz', $this->onekli()],
            $oz.'uplink_gecit' => ['nullable', 'ipv4', function (string $alan, mixed $deger, Closure $hata): void {
                $adres = (string) $this->input('ozellestirme.uplink_adres');
                if ($adres !== '' && ! PatchBuilder::altAgda((string) $deger, $adres)) {
                    $hata('Ağ geçidi uplink adresinin alt ağında olmalı.');
                }
            }],
            $oz.'me0_adres' => ['nullable', $this->onekli()],
            $oz.'me0_kaldir' => ['nullable', 'boolean'],
            $oz.'vlan0_adresleri' => ['nullable', 'string', $this->satirlar($this->onekli(), 20)],
            $oz.'re_filter' => ['nullable', 'string', $this->satirlar($this->adresYaDaOnek(), 50)],
            $oz.'me_filter' => ['nullable', 'string', $this->satirlar($this->adresYaDaOnek(), 50)],
            $oz.'koru' => ['nullable', 'string', $this->satirlar($this->adresYaDaOnek(), 100)],
            $oz.'snmp_ad' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 .,:\/()_-]{1,120}$/'],
            $oz.'snmp_aciklama' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 .,:\/()_-]{1,120}$/'],
            $oz.'snmp_communityler' => ['nullable', 'string', $this->satirlar(fn (string $s): bool => preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $s) === 1, 10)],
            $oz.'snmp_engine_kaldir' => ['nullable', 'boolean'],
            $oz.'root_parola' => ['nullable', 'string', $this->parola()],
            $oz.'admin_parola' => ['nullable', 'string', $this->parola()],
            $oz.'archival_kaldir' => ['nullable', 'boolean'],
            $oz.'ek_komutlar' => ['nullable', 'string', $this->satirlar(
                fn (string $s): bool => preg_match('/^(set|delete|deactivate|activate) [^|;`\r\n]{1,300}$/', $s) === 1, 50,
            )],
        ];
    }

    /** @return array<string, string> */
    protected function ozellestirmeIletileri(): array
    {
        return [
            'ozellestirme.host_name.regex' => 'Makine adı harf, rakam, nokta ve tire olmalı.',
            'ozellestirme.uplink_arayuz.regex' => 'Arayüz ge-0/1/0, xe-0/1/0 biçiminde olmalı.',
            'ozellestirme.snmp_ad.regex' => 'SNMP adında tırnak ve noktalı virgül olamaz.',
            'ozellestirme.snmp_aciklama.regex' => 'SNMP açıklamasında tırnak ve noktalı virgül olamaz.',
        ];
    }

    /**
     * Doğrulanmış alanlar PatchBuilder'ın beklediği biçimde; özelleştirme
     * istenmediyse null. Parolalar burada özete çevrilir: düz metin hiçbir
     * yere yazılmaz. Boş alan ve boş liste "dokunma" demektir.
     *
     * @return array<string, mixed>|null
     */
    public function ozellestirme(): ?array
    {
        if (! $this->boolean('ozellestir')) {
            return null;
        }

        $girdi = (array) $this->input('ozellestirme', []);
        $oz = [];

        foreach (['host_name', 'uplink_arayuz', 'uplink_adres', 'uplink_gecit', 'me0_adres', 'snmp_ad', 'snmp_aciklama'] as $alan) {
            $oz[$alan] = trim((string) ($girdi[$alan] ?? ''));
        }
        foreach (self::LISTELER as $alan) {
            $liste = self::bol((string) ($girdi[$alan] ?? ''));
            if ($liste !== []) {
                $oz[$alan] = $liste;
            }
        }
        foreach (['me0_kaldir', 'snmp_engine_kaldir', 'archival_kaldir'] as $alan) {
            $oz[$alan] = filter_var($girdi[$alan] ?? false, FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['root' => 'root_parola', 'admin' => 'admin_parola'] as $kim => $alan) {
            $deger = (string) ($girdi[$alan] ?? '');
            $oz[$kim.'_ozet'] = $deger === '' ? '' : Md5Crypt::ozet($deger);
        }

        return $oz;
    }

    /** @return list<string> */
    private static function bol(string $metin): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $metin) ?: []), fn (string $s): bool => $s !== '')));
    }

    private function onekli(): Closure
    {
        return function (string $alan, mixed $deger, Closure $hata): void {
            if (! self::onekMi((string) $deger)) {
                $hata('Adres önekle yazılmalı: 198.51.100.1/24.');
            }
        };
    }

    private function adresYaDaOnek(): Closure
    {
        return fn (string $s): bool => filter_var($s, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false || self::onekMi($s);
    }

    private static function onekMi(string $s): bool
    {
        return preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\/(\d{1,2})$/', $s, $m) === 1
            && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && (int) $m[2] <= 32;
    }

    /** Her satırı sınar; hatalı ilk satırı söyler. */
    private function satirlar(Closure $gecerli, int $enCok): Closure
    {
        return function (string $alan, mixed $deger, Closure $hata) use ($gecerli, $enCok): void {
            $liste = self::bol((string) $deger);
            if (count($liste) > $enCok) {
                $hata("En çok {$enCok} satır.");

                return;
            }
            foreach ($liste as $satir) {
                if (! $gecerli($satir)) {
                    $hata("Geçersiz satır: {$satir}");

                    return;
                }
            }
        };
    }

    private function parola(): Closure
    {
        return function (string $alan, mixed $deger, Closure $hata): void {
            $s = (string) $deger;
            if (! Md5Crypt::ozetMi($s) && (mb_strlen($s) < 8 || mb_strlen($s) > 128)) {
                $hata('Parola en az 8 karakter olmalı (ya da $1$ ile başlayan özet).');
            }
        };
    }
}
