<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Config şablonları (Kurulum sayfası): sıfır switch'e yüklenecek hazır
 * yapılandırmalar. Yedek klasörlerinden AYRI durur: yedek deposu FTP'den
 * eşitleniyor, oradaki bir klasör yeniden adlandırılsa eşitleme eskisini
 * geri getirirdi. Metin parola özetleri taşır: şifreli saklanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_sablonlari', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('ad', 120)->unique();
            $table->string('dosya', 200);
            $table->longText('metin');
            $table->unsignedInteger('boyut')->default(0);
            $table->unsignedSmallInteger('sira')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_sablonlari');
    }
};
