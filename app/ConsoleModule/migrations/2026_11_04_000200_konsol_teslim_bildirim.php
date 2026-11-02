<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 *   teslim_config     kurulum bitince ajanın cihazdan okuduğu yapılandırma
 *                     (şifreli; parola özetleri ve SNMP topluluklarını
 *                     taşır). GEÇİCİ: console.final_config_keep_hours sonra
 *                     console:agents siler.
 *   teslim_config_at  alındığı an
 *   bildirildi_at     işi açana "iş bitti" bildirimi gösterildi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->longText('teslim_config')->nullable();
            $table->timestamp('teslim_config_at')->nullable();
            $table->timestamp('bildirildi_at')->nullable();
        });

        /* Önceden bitmiş işler için ilk yoklamada bildirim yağmasın. */
        DB::table('konsol_isleri')->whereNotIn('durum', ['bekliyor', 'calisiyor'])->update(['bildirildi_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->dropColumn(['teslim_config', 'teslim_config_at', 'bildirildi_at']);
        });
    }
};
