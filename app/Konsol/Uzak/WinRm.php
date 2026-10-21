<?php

declare(strict_types=1);

namespace App\Konsol\Uzak;

use Illuminate\Support\Facades\Process;

/**
 * Saha PC'sinde PowerShell çalıştırır (WinRM, NTLM, şifreli). Kardeş
 * paneldeki RunsOverWinRm taşımasının sadeleşmiş hâli; yardımcı betik aynı.
 *
 * Parola ve sırlar KOMUT SATIRINA YAZILMAZ: istek yardımcıya standart
 * girdiden gider, betiğe giren sırlar kabuğun ortam değişkenindedir.
 */
class WinRm
{
    private const ORTAM = 'KONSOL_ORTAM';

    /**
     * @param  array{adres: string, port: int, kullanici: string, parola: string}  $hedef
     * @param  array<string, string>  $ortam  betikte $env:AD olarak okunur
     * @return array{kod: int, cikti: string, hata: string}
     */
    public function powershell(array $hedef, string $betik, array $ortam = [], ?int $sure = null): array
    {
        /*
         * BETİK VE SIRLAR KABUĞUN ORTAMINDA, TEK DEĞİŞKENDE gider; komut
         * satırında yalnız onları açıp betiği çalıştıran kısa bir başlatıcı
         * durur.
         *
         *  - Komut cmd.exe'den geçiyor, 8191 karakter sınırı var; sertifikalı
         *    kurulum betiği aşıyordu ("The command line is too long").
         *  - pywinrm 0.3.0 birden çok ortam değişkeninde YALNIZ SONUNCUSUNU
         *    gönderiyor (her biri öncekinin üzerine yazılıyor); jeton PC'ye hiç
         *    ulaşmıyordu.
         */
        $paket = ['KONSOL_BETIK' => 'try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch {}'."\n"
            .'$ProgressPreference = "SilentlyContinue"; $ErrorActionPreference = "Stop"'."\n".$betik] + $ortam;
        $baslatici = base64_encode(mb_convert_encoding(
            '$o = $env:'.self::ORTAM.' | ConvertFrom-Json; Remove-Item env:'.self::ORTAM.'; '
            .'$o.PSObject.Properties | ForEach-Object { Set-Item -Path ("env:" + $_.Name) -Value $_.Value }; '
            .'& ([scriptblock]::Create($env:KONSOL_BETIK))',
            'UTF-16LE',
            'UTF-8',
        ));

        $yanit = $this->cagir($hedef, [
            'action' => 'run',
            'program' => 'powershell.exe',
            'arguments' => ['-NonInteractive', '-NoProfile', '-EncodedCommand', $baslatici],
            'env' => [self::ORTAM => json_encode($paket, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
        ], $sure);

        return [
            'kod' => (int) ($yanit['status'] ?? 1),
            'cikti' => $this->temizle((string) base64_decode((string) ($yanit['stdout'] ?? ''), true), $ortam),
            'hata' => $this->temizle((string) base64_decode((string) ($yanit['stderr'] ?? ''), true), $ortam),
        ];
    }

    /** @param  array{adres: string, port: int, kullanici: string, parola: string}  $hedef */
    public function sina(array $hedef): void
    {
        $this->cagir($hedef, ['action' => 'verify'], null);
    }

    /**
     * @param  array{adres: string, port: int, kullanici: string, parola: string}  $hedef
     * @param  array<string, mixed>  $istek
     * @return array<string, mixed>
     */
    private function cagir(array $hedef, array $istek, ?int $sure): array
    {
        $ayar = (array) config('konsol.winrm');
        $sure ??= (int) $ayar['timeout'];

        $sonuc = Process::timeout($sure + 10)
            ->env(['OPENSSL_CONF' => $ayar['openssl_conf']])
            ->input(json_encode([
                'host' => $hedef['adres'],
                'port' => $hedef['port'],
                'username' => $hedef['kullanici'],
                'password' => $hedef['parola'],
                'timeout' => $sure,
                ...$istek,
            ], JSON_THROW_ON_ERROR))
            ->run([$ayar['python'], $ayar['runner']]);

        $yanit = json_decode(trim($sonuc->output()), true);

        if (! $sonuc->successful() || ! is_array($yanit)) {
            throw new UzakHata('WinRM yardımcısı çalıştırılamadı.');
        }

        if (($yanit['ok'] ?? false) !== true) {
            $hata = (string) ($yanit['error'] ?? '');

            throw new UzakHata(match (true) {
                str_contains($hata, '401') || str_contains($hata, 'InvalidCredentials')
                    || str_contains($hata, 'credentials were rejected') => 'Kimlik doğrulaması reddedildi (kullanıcı adı ya da parola).',
                str_contains($hata, 'Max retries') || str_contains($hata, 'ConnectionError')
                    || str_contains($hata, 'timed out') => 'Saha PC\'sine ulaşılamadı (WinRM '.$hedef['port'].').',
                default => 'WinRM: '.mb_substr($hata, 0, 200),
            });
        }

        return $yanit;
    }

    /**
     * PowerShell hata akışının CLIXML sarmalı atılır; betiğe giren sırlar
     * çıktıya düşmüşse silinir ("düşmez" bir varsayım, temizlik ölçü).
     *
     * @param  array<string, string>  $ortam
     */
    private function temizle(string $metin, array $ortam): string
    {
        if (str_starts_with($metin, '#< CLIXML')) {
            preg_match_all('/<S S="Error">(.*?)<\/S>/s', $metin, $m);
            $metin = html_entity_decode(str_replace(['_x000D_', '_x000A_'], ['', "\n"], implode('', $m[1])));
        }

        foreach ($ortam as $sir) {
            if ($sir !== '') {
                $metin = str_replace($sir, '***', $metin);
            }
        }

        return trim($metin);
    }
}
