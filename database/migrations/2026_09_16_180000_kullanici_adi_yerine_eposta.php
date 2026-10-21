<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GİRİŞ ANAHTARI KULLANICI ADI DEĞİL E-POSTA.
 *
 * Bu panel kullanıcı adıyla giriyordu; öteki paneller e-posta ile. Merkezle
 * eşleşme zaten e-posta üzerinden yapılıyor — kullanıcı adı, hiçbir yerde
 * karşılığı olmayan ikinci bir kimlikti.
 *
 * Ad ayrı bir alana taşınır (`name`): ekranda insan adı görünür, girişte
 * e-posta kullanılır. Şablonda da böyle.
 *
 * E-POSTASI OLMAYAN HESAP KALAMAZ: giriş anahtarı o. Boş olanlara geçici ve
 * BENZERSİZ bir yer tutucu yazılır (kullanici-<id>@yerel.gecersiz) ve hesap
 * pasife alınır — yöneticinin gerçek adresi girip yeniden açması gerekir.
 * Sessizce silmek ya da girişsiz bırakmak, sebebi görünmeyen bir arıza olurdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('id');
        });

        /* Ad yoksa eski kullanıcı adı ada dönüşür: kimse adsız kalmasın. */
        DB::table('users')->update(['name' => DB::raw('username')]);

        DB::table('users')->whereNull('email')->orWhere('email', '')->get()->each(function ($satir): void {
            DB::table('users')->where('id', $satir->id)->update([
                'email' => 'kullanici-'.$satir->id.'@yerel.gecersiz',
                'aktif' => false,
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });

        /*
         * Benzersizlik ZATEN VAR (kimlik merkezi göçünde kondu); yalnızca
         * "boş olamaz" eklenir. Aynı indeksi ikinci kez yaratmak göçü
         * "index already exists" ile düşürür.
         */
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
            $table->string('name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->after('id');
        });

        DB::table('users')->update(['username' => DB::raw('name')]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
