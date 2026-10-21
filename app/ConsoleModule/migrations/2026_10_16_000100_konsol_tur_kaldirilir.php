<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Yalnız konsol" işi kaldırıldı;
 * her iş loader'dan kurulumdur. Canlı konsol ve elle yazma kurulum işinde
 * duruyor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->dropColumn('tur');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->string('tur', 10)->default('kurulum')->after('pc_id');
        });
    }
};
