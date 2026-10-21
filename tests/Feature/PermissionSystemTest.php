<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ROL - YETKİ (kardeş panellerdeki düzen): rol canlı okunur, kişi yalnız
 * istisna tutar; önce kişi, sonra rol; eşleşme yoksa kapalı.
 */
final class PermissionSystemTest extends TestCase
{
    use RefreshDatabase;

    private function karar(User $u, string $kaynak, string $aksiyon): bool
    {
        $r = app(UserPermissionResolver::class);
        $r->flush($u);

        return $r->allows($u->fresh(), $kaynak, $aksiyon);
    }

    #[Test]
    public function network_rolu_yedek_gorur_ve_indirir_junos_yonetemez(): void
    {
        $u = User::factory()->rol('network')->create();

        $this->assertTrue($this->karar($u, 'yedek', 'view'));
        $this->assertTrue($this->karar($u, 'yedek', 'download'));
        $this->assertTrue($this->karar($u, 'junos', 'view'));
        $this->assertFalse($this->karar($u, 'junos', 'create'));
    }

    #[Test]
    public function rolsuz_ve_yetkisiz_rol_hicbir_sey_goremez(): void
    {
        foreach ([User::factory()->create(), User::factory()->rol('teknik')->create()] as $u) {
            $this->assertFalse($this->karar($u, 'yedek', 'view'));
            $this->actingAs($u)->get(route('yedek.index'))->assertRedirect(route('yetkisiz'));
            $this->actingAs($u)->get(route('yedek.klasor', ['yol' => 'X']))->assertRedirect(route('yetkisiz'));
        }
    }

    #[Test]
    public function kisinin_yasagi_rolu_keser_izni_ekler(): void
    {
        $u = User::factory()->rol('network')->create();
        $u->izinler()->create(['kaynak' => 'yedek', 'aksiyon' => 'download', 'etki' => UserPermission::YASAK]);
        $u->izinler()->create(['kaynak' => 'junos', 'aksiyon' => 'create', 'etki' => UserPermission::IZIN]);

        $this->assertTrue($this->karar($u, 'yedek', 'view'));
        $this->assertFalse($this->karar($u, 'yedek', 'download'));
        $this->assertTrue($this->karar($u, 'junos', 'create'));
    }

    #[Test]
    public function gormeden_indirilemez_kapali_hesap_hicbir_sey_yapamaz(): void
    {
        $u = User::factory()->create();
        $u->izinler()->create(['kaynak' => 'yedek', 'aksiyon' => 'download', 'etki' => UserPermission::IZIN]);
        $this->assertFalse($this->karar($u, 'yedek', 'download'), 'view olmadan download geçmez');

        $kapali = User::factory()->rol('network')->inactive()->create();
        $this->assertFalse($this->karar($kapali, 'yedek', 'view'));
    }

    #[Test]
    public function rol_degisikligi_aninda_gecerli(): void
    {
        $u = User::factory()->rol('teknik')->create();
        $this->assertFalse($this->karar($u, 'yedek', 'view'));

        Role::where('slug', 'teknik')->sole()->update(['yetkiler' => ['yedek' => ['view']]]);

        $this->assertTrue($this->karar($u, 'yedek', 'view'));
    }

    #[Test]
    public function yonetici_istisnalari_kullanicilar_ekranindan_yazar(): void
    {
        $yonetici = User::factory()->admin()->create();
        $hedef = User::factory()->rol('network')->create();

        $this->actingAs($yonetici)->post(route('admin.kullanicilar.update', $hedef), [
            'ad' => $hedef->name, 'eposta' => $hedef->email, 'rol' => 'network', 'aktif' => '1',
            'istisna' => ['yedek:download' => 'yasak', 'junos:update' => 'izin', 'yok:boyle' => 'izin', 'yedek:view' => ''],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(
            ['yedek:download' => 'yasak', 'junos:update' => 'izin'],
            app(UserPermissionResolver::class)->istisnalar($hedef),
        );

        $this->actingAs($yonetici)->get(route('admin.kullanicilar.index'))
            ->assertOk()
            ->assertSee('name="istisna[yedek:download]"', false);
    }

    #[Test]
    public function yetki_talebi_onayi_istisna_yazar(): void
    {
        $yonetici = User::factory()->admin()->create();
        $kisi = User::factory()->create();

        $this->actingAs($kisi)->post(route('profil.yetki-talebi'), ['perm_yedek' => '1'])->assertRedirect();
        $talep = $kisi->permissionRequests()->sole();
        $this->assertTrue($talep->perm_yedek);
        $this->assertSame('bekliyor', $talep->durum);

        $this->actingAs($yonetici)->post(route('admin.yetki-talepleri.onayla', $talep))->assertRedirect();

        $this->assertTrue($this->karar($kisi, 'yedek', 'view'));
        $this->assertTrue($this->karar($kisi, 'yedek', 'download'));
    }

    #[Test]
    public function ust_cubuk_yetkiye_gore_cizilir(): void
    {
        $this->actingAs(User::factory()->rol('network')->create())->get(route('kurulum.index'))
            ->assertSee('Yedekler')->assertSee('Kurulum')->assertDontSee('Kullanıcılar');

        $this->actingAs(User::factory()->admin()->create())->get(route('kurulum.index'))
            ->assertSee('Kullanıcılar')->assertSee('Ayarlar');
    }
}
