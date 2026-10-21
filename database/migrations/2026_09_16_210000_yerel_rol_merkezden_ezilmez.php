<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PANELDE ELLE VERİLEN ROL, MERKEZDEN EZİLMEZ.
 *
 * Rol merkezden geliyor (zamanlanmış eşitleme turu yazıyor) ve bu
 * doğru: kişinin kimliği orada tanımlı. Ama bir kişi YALNIZCA BU PANELDE
 * farklı bir rolde olabilir — merkezde başka bir rolde olan biri bu panelin
 * yöneticisi olabilir.
 *
 * İşaret olmasaydı yönetici ekrandan rolü değiştirir, bir sonraki
 * eşitleme onu sessizce geri alırdı: kimse ne olduğunu anlamazdı.
 *
 * İşaret YALNIZCA ekrandan rol değiştirildiğinde konur. Merkezden açılan ya
 * da merkezden eşitlenen hesapta kapalı kalır, yani varsayılan davranış
 * "merkez ne diyorsa o" olmayı sürdürür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('rol_yerel')->default(false)->after('rol_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('rol_yerel');
        });
    }
};
