<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Bir PC'ye birden çok USB-seri kablo: aynı anda birden çok switch.
 *
 *   konsol_pcleri.seri_portlar  PC'nin portları (liste); seri_port ilkini
 *                               taşımaya devam eder (eski ajan.json uyumu).
 *   konsol_isleri.seri_port     işin portu ('' = otomatik, tek portlu PC).
 *   konsol_isleri.acik_port     iş AÇIKKEN seri_port, kapanınca NULL.
 *                               unique(pc_id, acik_port): aynı porta iki
 *                               açık iş veritabanında da olamaz (NULL'lar
 *                               çakışmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->json('seri_portlar')->nullable()->after('seri_port');
        });
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->string('seri_port', 60)->nullable()->after('pc_id');
            $table->string('acik_port', 60)->nullable()->after('seri_port');
        });

        foreach (DB::table('konsol_pcleri')->whereNotNull('seri_port')->where('seri_port', '!=', '')->get(['id', 'seri_port']) as $pc) {
            DB::table('konsol_pcleri')->where('id', $pc->id)->update(['seri_portlar' => json_encode([$pc->seri_port])]);
        }
        /* Şimdiye kadar PC başına tek açık iş vardı: hepsi "otomatik/PC'nin portu". */
        DB::table('konsol_isleri')->whereIn('durum', ['bekliyor', 'calisiyor'])->update(['acik_port' => '']);

        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->unique(['pc_id', 'acik_port']);
        });
    }

    public function down(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->dropUnique(['pc_id', 'acik_port']);
            $table->dropColumn(['seri_port', 'acik_port']);
        });
        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->dropColumn('seri_portlar');
        });
    }
};
