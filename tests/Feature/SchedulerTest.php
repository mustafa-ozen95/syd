<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Zamanlayıcıda duran işler.
 *
 * Bir turu eklemeyi unutmak sessizdir: her şey çalışıyor görünür, yalnızca
 * veri yavaşça eskir. Liste burada bağlanıyor.
 */
class SchedulerTest extends TestCase
{
    public function test_gerekli_turlar_zamanlayicida(): void
    {
        $komutlar = collect(app(Schedule::class)->events())
            ->map(fn ($olay): string => (string) $olay->command)
            ->implode(' ');

        foreach (['identity:sync', 'audit:verify', 'update:check'] as $komut) {
            $this->assertStringContainsString($komut, $komutlar, "{$komut} zamanlayıcıda yok.");
        }
    }

    /**
     * KİMLİK TURU GÜNLÜK.
     *
     * Merkez rol ya da durum değişince anında haber veriyor; bu tur o
     * bildirimin kaybolduğu durumlar için (ağ, yeniden başlatma, panel o an
     * kapalı) ağdaki emniyet.
     */
    public function test_kimlik_turu_gunluk_kosar(): void
    {
        $olay = collect(app(Schedule::class)->events())
            ->first(fn ($olay): bool => str_contains((string) $olay->command, 'identity:sync'));

        $this->assertNotNull($olay);
        $this->assertSame('10 4 * * *', $olay->expression);
    }
}
