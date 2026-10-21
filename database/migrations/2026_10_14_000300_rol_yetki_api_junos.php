<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ROL - YETKİ - API: kardeş panellerdeki yapı.
 *
 *   roller.yetkiler        rolün CANLI yetkisi: kaynak => aksiyonlar
 *   kullanici_izinleri     kişinin İSTİSNASI: rolün vermediğini ekler (izin),
 *                          verdiğini keser (yasak)
 *   api_tokens             dışarıya verilen jetonlar (kardeş paneldeki tablonun aynısı)
 *   junos_goruntuleri      kurulum sayfasının listesi; eskiden config'teydi
 *
 * Eski `users.perm_yedek` bayrağı kaldırılır. Bayrağı açık olup rolü yedek
 * görmeyen kişiye aynı erişim İSTİSNA olarak yazılır: geçişte kimse
 * erişimini kaybetmez.
 */
return new class extends Migration
{
    /** Kurulum sayfasının ilk listesi; sonrası panelden yönetilir. */
    private const GORUNTULER = [
        ['EX-2200', '12', 'jinstall-ex-2200-12.3R12.4-domestic-signed.tgz'],
        ['EX-2300', '24.4', 'junos-arm-32-24.4R2.23.tgz'],
        ['EX-3200', '12', 'jinstall-ex-3200-12.3R12.4-domestic-signed.tgz'],
        ['EX-3300', '12', 'jinstall-ex-3300-12.3R12.4-domestic-signed.tgz'],
        ['EX-4200', '12', 'jinstall-ex-4200-12.3R12.4-domestic-signed.tgz'],
        ['EX-4500', '12', 'jinstall-ex-4500-12.3R12.4-domestic-signed.tgz'],
        ['EX-4550', '12', 'jinstall-ex-4550-12.3R12.4-domestic-signed.tgz'],
        ['QFX-5100', '21.4', 'jinstall-host-qfx-5-flex-21.4R3.15-signed.tgz'],
        ['Jloader-ex-3242', '12', 'jloader-ex-3242-12.1R3-signed.tgz'],
        ['MX', '23.4R2-S8.7', 'junos-vmhost-install-mx-x86-64-23.4R2-S8.7.tgz'],
    ];

    /** Yerleşik rollerin ilk yetkileri; sonrası Ayarlar → Roller'dan. */
    private const ROL_YETKILERI = [
        'network' => ['yedek' => ['view', 'download'], 'junos' => ['view']],
    ];

    public function up(): void
    {
        Schema::table('roller', function (Blueprint $table): void {
            $table->string('aciklama', 190)->nullable()->after('label');
            $table->json('yetkiler')->nullable()->after('yonetici');
        });

        foreach (self::ROL_YETKILERI as $slug => $yetkiler) {
            DB::table('roller')->where('slug', $slug)->update(['yetkiler' => json_encode($yetkiler)]);
        }

        Schema::create('kullanici_izinleri', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kaynak', 40);
            $table->string('aksiyon', 40);
            $table->string('etki', 10);
            $table->timestamps();
            $table->unique(['user_id', 'kaynak', 'aksiyon']);
        });

        $this->bayragiIstisnayaTasi();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('perm_yedek');
        });

        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 80);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 20);
            $table->json('abilities')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('junos_goruntuleri', function (Blueprint $table): void {
            $table->id();
            $table->string('model', 60);
            $table->string('surum', 40);
            $table->string('dosya', 200);
            $table->unsignedSmallInteger('sira')->default(0);
            $table->timestamps();
        });

        $an = now();

        foreach (self::GORUNTULER as $i => [$model, $surum, $dosya]) {
            DB::table('junos_goruntuleri')->insert([
                'model' => $model, 'surum' => $surum, 'dosya' => $dosya,
                'sira' => ($i + 1) * 10, 'created_at' => $an, 'updated_at' => $an,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('junos_goruntuleri');
        Schema::dropIfExists('api_tokens');

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('perm_yedek')->default(false);
        });

        DB::table('users')->whereIn('id', DB::table('kullanici_izinleri')
            ->where('kaynak', 'yedek')->where('aksiyon', 'view')->where('etki', 'izin')->select('user_id'))
            ->update(['perm_yedek' => true]);

        Schema::dropIfExists('kullanici_izinleri');

        Schema::table('roller', function (Blueprint $table): void {
            $table->dropColumn(['aciklama', 'yetkiler']);
        });
    }

    /**
     * Bayrağı açık, rolü yönetici olmayan ve rolü yedek görmeyen kişiye
     * görme + indirme istisnası. Bayrak eskiden ikisini birden açıyordu.
     */
    private function bayragiIstisnayaTasi(): void
    {
        $an = now();
        $roller = DB::table('roller')->get(['id', 'yonetici', 'yetkiler'])->keyBy('id');

        foreach (DB::table('users')->where('perm_yedek', true)->get(['id', 'rol_id']) as $kisi) {
            $rol = $roller[$kisi->rol_id] ?? null;

            if ($rol !== null && (bool) $rol->yonetici) {
                continue;
            }

            $rolunki = (array) (json_decode((string) ($rol->yetkiler ?? ''), true)['yedek'] ?? []);

            foreach (['view', 'download'] as $aksiyon) {
                if (! in_array($aksiyon, $rolunki, true)) {
                    DB::table('kullanici_izinleri')->insert([
                        'user_id' => $kisi->id, 'kaynak' => 'yedek', 'aksiyon' => $aksiyon,
                        'etki' => 'izin', 'created_at' => $an, 'updated_at' => $an,
                    ]);
                }
            }
        }
    }
};
