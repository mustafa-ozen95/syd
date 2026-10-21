<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Switch'in loader'daki adresi İŞTE seçilir (sabit adres ya da DHCP). PC
 * kaydındaki adres varsayılan olarak kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->string('adres_turu', 8)->default('sabit')->after('yedek_dosya');
            $table->string('cihaz_ip', 18)->nullable()->after('adres_turu');
            $table->string('gecit', 45)->nullable()->after('cihaz_ip');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->dropColumn(['adres_turu', 'cihaz_ip', 'gecit']);
        });
    }
};
