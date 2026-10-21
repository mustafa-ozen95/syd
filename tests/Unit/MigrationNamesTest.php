<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Göç dosya adları `migrations` tablosunda kayıtlıdır: dosya yeniden adlandırılırsa göç "hiç koşmamış" sayılır
 * ve var olan tabloyu yeniden oluşturmaya kalkıp paneli açılışta durdurur. Bu adlar klasör adı çevirilerinde bile değişmez.
 */
class MigrationNamesTest extends TestCase
{
    #[Test]
    public function console_module_migration_file_names_stay_as_recorded(): void
    {
        $dir = dirname(__DIR__, 2).'/app/ConsoleModule/migrations';

        foreach ([
            '2026_10_15_000600_konsol_kurulum.php',
            '2026_10_16_000100_konsol_tur_kaldirilir.php',
            '2026_10_16_000200_konsol_is_adresi.php',
        ] as $ad) {
            $this->assertFileExists($dir.'/'.$ad);
        }
    }
}
