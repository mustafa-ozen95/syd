<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Ayarlar → Roller: yalnız yönetici; yetkiler canlı; yerleşik rol silinmez. */
final class RoleScreenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function yalnizca_yonetici_girer(): void
    {
        $this->actingAs(User::factory()->rol('network')->create())->get(route('admin.roller.index'))->assertRedirect(route('yetkisiz'));

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.roller.index'))
            ->assertOk()
            ->assertSee('Network')
            ->assertSee('Yedekler: Görüntüle, İndir');
    }

    #[Test]
    public function yetkiler_kaydedilir_tanimsiz_atilir_kisa_ad_degismez(): void
    {
        $yonetici = User::factory()->admin()->create();
        $teknik = Role::where('slug', 'teknik')->sole();

        $this->actingAs($yonetici)->get(route('admin.roller.edit', $teknik))->assertOk();

        $this->actingAs($yonetici)->put(route('admin.roller.update', $teknik), [
            'label' => 'Teknik', 'badge' => 'gray', 'sira' => 3,
            'yetkiler' => ['yedek' => ['view', 'sil'], 'junos' => ['view'], 'uydurma' => ['view']],
        ])->assertRedirect(route('admin.roller.index'));

        $this->assertSame(['yedek' => ['view'], 'junos' => ['view']], $teknik->fresh()->yetkiler);

        $this->actingAs($yonetici)->put(route('admin.roller.update', $teknik), [
            'slug' => 'baska', 'label' => 'Teknik', 'badge' => 'gray',
        ])->assertSessionHasErrors('slug');
        $this->assertSame('teknik', $teknik->fresh()->slug);
    }

    #[Test]
    public function yeni_rol_acilir_ve_silinir_yerlesik_silinmez(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->post(route('admin.roller.store'), [
            'slug' => 'noc', 'label' => 'NOC', 'badge' => 'green', 'yetkiler' => ['yedek' => ['view']],
        ])->assertRedirect(route('admin.roller.index'));

        $noc = Role::where('slug', 'noc')->sole();
        $this->assertFalse($noc->yonetici);
        $this->assertTrue($noc->verir('yedek', 'view'));

        $this->actingAs($yonetici)->delete(route('admin.roller.destroy', $noc))->assertRedirect();
        $this->assertModelMissing($noc);

        $network = Role::where('slug', 'network')->sole();
        $this->actingAs($yonetici)->delete(route('admin.roller.destroy', $network))->assertSessionHasErrors('rol');
        $this->assertModelExists($network);
    }

    /** Uyarı listede bir kez görünür: bildirim bloğu doğrulama hatalarını zaten basıyor. */
    #[Test]
    public function silinemeyen_rol_uyarisi_bir_kez_gorunur(): void
    {
        $network = Role::where('slug', 'network')->sole();

        $yanit = $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.roller.index'))
            ->followingRedirects()
            ->delete(route('admin.roller.destroy', $network))
            ->assertOk();

        $this->assertSame(1, substr_count($yanit->getContent(), 'Yerleşik rol ya da kullanıcısı olan rol silinemez.'));
    }
}
