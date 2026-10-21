<?php

declare(strict_types=1);

namespace App\ConsoleModule\Sources;

use App\ConsoleModule\Contracts\ImageSource;
use App\Models\JunosImage;

/** SYD: Kurulum sayfasındaki Junos görüntüleri; dosya TFTP sunucusunda. */
class JunosTableSource implements ImageSource
{
    public function secenekler(): array
    {
        return JunosImage::query()->sirali()->get()
            ->map(fn (JunosImage $g): array => $this->satir($g))
            ->values()->all();
    }

    public function bul(string $anahtar): ?array
    {
        $g = ctype_digit($anahtar) ? JunosImage::find((int) $anahtar) : null;

        return $g === null ? null : $this->satir($g);
    }

    /** @return array{anahtar: string, model: string, surum: string, etiket: string, dosya: string, adres: string} */
    private function satir(JunosImage $g): array
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
