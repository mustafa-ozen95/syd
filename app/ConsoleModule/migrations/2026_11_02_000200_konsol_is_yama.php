<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Yapılandırmayı özelleştir": iş açılırken yedeğin üstüne uygulanacak
 * set/delete yaması (şifreli; parola özetleri taşır, iş kapanınca silinir)
 * ve ekranda/kayıtta gösterilecek, sır içermeyen özeti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->text('yama')->nullable()->after('yedek_dosya');
            $table->string('yama_ozeti', 500)->nullable()->after('yama');
            $table->string('uplink_arayuz', 20)->nullable()->after('yama_ozeti');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->dropColumn(['yama', 'yama_ozeti', 'uplink_arayuz']);
        });
    }
};
