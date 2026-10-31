<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * İşin ne yaptığı: Junos kurulumu (sonra istenirse yedek) ya da yalnız yedek
 * yükleme (Junos'u kurulu cihaz). Önceki işler kurulumdur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->string('islem', 10)->default('kurulum')->after('pc_id');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->dropColumn('islem');
        });
    }
};
