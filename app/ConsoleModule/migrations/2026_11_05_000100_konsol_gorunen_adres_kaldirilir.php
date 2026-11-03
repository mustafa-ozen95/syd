<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Ajan jetonunun adres kilidi ve "Panelin gördüğü adres" kalkar.
 *
 * Panel istemcinin gerçek adresini görmüyor: ağ geçidi (10.4.4.1) arkasındaki
 * her isteği kendi adresiyle iletiyor. Kilit ya ajan kurulumunu bozuyor (401)
 * ya da geçit adresi yazılınca geçidin arkasındaki herkese açılıyordu.
 * Açık ajan jetonlarının eski kilidi de silinir: her PC aynı kurala bağlı.
 * Elle açılan API jetonlarının adres kuralına dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_tokens')->where('name', 'like', 'Konsol ajanı%')->whereNull('revoked_at')->update(['allowed_ips' => null]);

        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->dropColumn('gorunen_adres');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->string('gorunen_adres', 45)->nullable()->after('adres');
        });
    }
};
