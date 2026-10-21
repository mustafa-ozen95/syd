<?php

namespace Tests\Unit;

use App\ConsoleModule\Models\ConsoleEvent;
use App\ConsoleModule\Models\ConsoleInput;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsoleOutput;
use App\ConsoleModule\Models\ConsolePc;
use App\Models\JunosImage;
use App\Models\Role;
use App\Models\UserPermission;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sınıflar İngilizce adlandırılırken etkinlik kaydındaki eski sınıf adı satırları
 * (subject_type / konu_type) ve zincir özetleri bozulmamalı.
 */
class LegacyMorphAliasTest extends TestCase
{
    public static function renamed(): array
    {
        return [
            ['App\\Konsol\\Models\\KonsolOlayi', ConsoleEvent::class],
            ['App\\Konsol\\Models\\KonsolGirdisi', ConsoleInput::class],
            ['App\\Konsol\\Models\\KonsolIsi', ConsoleJob::class],
            ['App\\Konsol\\Models\\KonsolCiktisi', ConsoleOutput::class],
            ['App\\Konsol\\Models\\KonsolPc', ConsolePc::class],
            ['App\\Models\\JunosGoruntusu', JunosImage::class],
            ['App\\Models\\Rol', Role::class],
            ['App\\Models\\KullaniciIzni', UserPermission::class],
        ];
    }

    #[DataProvider('renamed')]
    public function test_old_name_resolves_and_new_rows_keep_old_name(string $old, string $new): void
    {
        $this->assertSame($new, Relation::getMorphedModel($old));
        $this->assertSame($old, (new $new)->getMorphClass());
    }
}
