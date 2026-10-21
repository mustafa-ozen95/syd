<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Yönetim ekranları YALNIZCA yöneticide.
 *
 * "Kullanıcıları düzenle" yetkisi, kendini yönetici yapma yetkisi değildir;
 * bu yüzden kapı yetki bayrağı değil `admin` rolüdür. Kural artık Form
 * Request'in authorize()'ında duruyor — controller'a hiç ulaşmadan kapanır.
 */
class YonetimSiniriTest extends TestCase
{
    use RefreshDatabase;

    public function test_yonetici_olmayan_baskasini_guncelleyemez(): void
    {
        $hedef = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.kullanicilar.update', $hedef), [
                'ad' => $hedef->name,
                'eposta' => $hedef->email,
                'rol' => 'admin',
                'aktif' => '1',
                'istisna' => ['yedek:view' => 'izin'],
            ])
            ->assertRedirect(route('yetkisiz'));

        $this->assertSame(0, $hedef->izinler()->count(), 'istisna yazılmamalı');
        $this->assertNull($hedef->fresh()->rol_id, 'rol değişmemeli');
    }
}
