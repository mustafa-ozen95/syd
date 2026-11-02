<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Seri port COM numarasıyla değil KABLONUN KİMLİĞİYLE tutulur: Windows kablo
 * başka USB girişine takılınca ona yeni COM numarası veriyor (Teknik PC'de
 * COM3 → COM9 oldu, iş olmayan porta gitti). Kimlik Windows'ta aygıt
 * örneğinin kimliği (FTDIBUS\VID_0403+PID_6001+A50285BIA\0000), Linux'ta
 * /dev/serial/by-id yolu; 60 karaktere sığmıyor.
 *
 *   konsol_pcleri.port_bilgileri  panelin PC'de algıladığı portlar:
 *                                 kimlik → {com, ad} (ekranda etiket için)
 *   konsol_pcleri.portlar_at      son algılama
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->string('seri_port', 200)->nullable()->change();
            $table->json('port_bilgileri')->nullable()->after('seri_portlar');
            $table->timestamp('portlar_at')->nullable()->after('port_bilgileri');
        });

        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->string('seri_port', 200)->nullable()->change();
            $table->string('acik_port', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->dropColumn(['port_bilgileri', 'portlar_at']);
        });
    }
};
