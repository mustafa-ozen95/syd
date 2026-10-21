<?php

declare(strict_types=1);

namespace App\Konsol\Kaynaklar;

use App\Konsol\Contracts\GoruntuKaynagi;
use App\Models\JunosGoruntusu;

/** SYD: Kurulum sayfasındaki Junos görüntüleri; dosya TFTP sunucusunda. */
class JunosTablosuKaynagi implements GoruntuKaynagi
{
    public function secenekler(): array
    {
        return JunosGoruntusu::query()->sirali()->get()
            ->map(fn (JunosGoruntusu $g): array => $this->satir($g))
            ->values()->all();
    }

    public function bul(string $anahtar): ?array
    {
        $g = ctype_digit($anahtar) ? JunosGoruntusu::find((int) $anahtar) : null;

        return $g === null ? null : $this->satir($g);
    }

    /** @return array{anahtar: string, model: string, surum: string, etiket: string, dosya: string, adres: string} */
    private function satir(JunosGoruntusu $g): array
    {
        return [
            'anahtar' => (string) $g->id,
            'model' => $g->model,
            'surum' => $g->surum,
            'etiket' => "{$g->model} · {$g->surum}",
            'dosya' => $g->dosya,
            'adres' => rtrim((string) config('junos.tftp'), '/').'/'.$g->dosya,
        ];
    }
}
