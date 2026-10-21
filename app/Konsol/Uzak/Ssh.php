<?php

declare(strict_types=1);

namespace App\Konsol\Uzak;

use Illuminate\Support\Facades\Process;

/**
 * Linux saha PC'sinde bash betiği çalıştırır (SSH, parola ile). Betik uzak
 * makinede standart girdiden okunur; sırlar komut satırına da diske de
 * düşmez. Anahtar parmak iziyle doğrulanır (bkz. bin/ssh-runner.py).
 */
class Ssh
{
    /**
     * @param  array{adres: string, port: int, kullanici: string, parola: string, parmak_izi: ?string}  $hedef
     * @param  list<string>  $sirlar  çıktıdan silinecek değerler
     * @return array{kod: int, cikti: string, hata: string, parmak_izi: ?string}
     */
    public function bash(array $hedef, string $betik, array $sirlar = [], ?int $sure = null): array
    {
        $ayar = (array) config('konsol.ssh');
        $sure ??= (int) $ayar['timeout'];

        $sonuc = Process::timeout($sure + 10)
            ->input(json_encode([
                'host' => $hedef['adres'],
                'port' => $hedef['port'],
                'username' => $hedef['kullanici'],
                'password' => $hedef['parola'],
                'fingerprint' => $hedef['parmak_izi'],
                'script' => "set -euo pipefail\n".$betik,
                'timeout' => $sure,
            ], JSON_THROW_ON_ERROR))
            ->run([$ayar['python'], $ayar['runner']]);

        $yanit = json_decode(trim($sonuc->output()), true);

        if (! $sonuc->successful() || ! is_array($yanit)) {
            throw new UzakHata('SSH yardımcısı çalıştırılamadı.');
        }

        if (($yanit['ok'] ?? false) !== true) {
            $hata = (string) ($yanit['error'] ?? '');

            throw new UzakHata(match (true) {
                str_contains($hata, 'Authentication') => 'Kimlik doğrulaması reddedildi (kullanıcı adı ya da parola).',
                str_contains($hata, 'anahtarı değişmiş') => mb_substr($hata, 0, 300),
                str_contains($hata, 'Unable to connect') || str_contains($hata, 'timed out')
                    || str_contains($hata, 'refused') => 'Saha PC\'sine ulaşılamadı (SSH '.$hedef['port'].').',
                default => 'SSH: '.mb_substr($hata, 0, 200),
            });
        }

        $temizle = function (string $metin) use ($sirlar, $hedef): string {
            foreach ([...$sirlar, $hedef['parola']] as $sir) {
                if ($sir !== '') {
                    $metin = str_replace($sir, '***', $metin);
                }
            }

            return trim($metin);
        };

        return [
            'kod' => (int) ($yanit['status'] ?? 1),
            'cikti' => $temizle((string) base64_decode((string) ($yanit['stdout'] ?? ''), true)),
            'hata' => $temizle((string) base64_decode((string) ($yanit['stderr'] ?? ''), true)),
            'parmak_izi' => isset($yanit['fingerprint']) ? (string) $yanit['fingerprint'] : null,
        ];
    }
}
