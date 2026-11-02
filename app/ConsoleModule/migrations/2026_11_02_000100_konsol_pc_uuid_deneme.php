<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * PC'nin adreste ve formda görünen kimliği uuid: sıralı birincil anahtar
 * dışarı çıkmaz (ConsoleJob'daki gibi).
 *
 * Ajan kurulumunun geri çekilmesi: PC'ye ulaşılamayınca kurulum her dakika
 * değil, giderek seyrek denenir (en çok 15 dakikada bir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->unsignedInteger('ajan_deneme_sayisi')->default(0)->after('ajan_mesaj');
            $table->timestamp('ajan_sonraki_deneme_at')->nullable()->after('ajan_deneme_sayisi');
        });

        foreach (DB::table('konsol_pcleri')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('konsol_pcleri')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_pcleri', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'ajan_deneme_sayisi', 'ajan_sonraki_deneme_at']);
        });
    }
};
