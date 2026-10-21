<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * KONSOLDAN KURULUM: ZTP yerine loader'dan kurulum; cihazı loader'a indirmek
 * dahil her adım kendiliğinden.
 *
 *   konsol_pcleri     konsol kablosunun takılı olduğu PC'ler; panel onlara
 *                     WinRM/SSH ile bağlanıp ajanı kurar. Parola ŞİFRELİ.
 *                     cihaz_ip/gecit: switch'e loader'da verilecek adres.
 *   konsol_isleri     bir kurulum ya da yalnız konsol oturumu. root_parola
 *                     ŞİFRELİ ve iş kapanınca silinir.
 *   konsol_olaylari   adımlar
 *   konsol_ciktilari  konsolda görülen her şey (parça parça)
 *   konsol_girdileri  panelden konsola elle yazılanlar
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('konsol_pcleri', function (Blueprint $table): void {
            $table->id();
            $table->string('ad', 60);
            $table->string('platform', 10);
            $table->string('adres', 120);
            $table->string('gorunen_adres', 45)->nullable();
            $table->unsignedSmallInteger('port');
            $table->string('kullanici', 64);
            $table->text('parola');
            $table->string('seri_port', 60)->nullable();
            $table->string('cihaz_ip', 18);
            $table->string('gecit', 15);
            $table->string('dizin', 120);
            $table->string('ssh_parmak_izi', 120)->nullable();
            $table->foreignId('jeton_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->timestamp('son_kurulum_at')->nullable();
            $table->string('ajan_mesaj', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('konsol_isleri', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('pc_id')->constrained('konsol_pcleri')->cascadeOnDelete();
            $table->string('tur', 10);
            $table->string('ad', 120);
            $table->string('goruntu_etiket', 120)->nullable();
            $table->string('goruntu_adres', 300)->nullable();
            $table->string('beklenen_surum', 40)->nullable();
            $table->string('yapilandirma_turu', 10)->default('yok');
            $table->string('yedek_cihaz', 120)->nullable();
            $table->string('yedek_dosya', 255)->nullable();
            $table->text('root_parola')->nullable();
            $table->string('durum', 12)->default('bekliyor');
            $table->string('asama', 20)->nullable();
            $table->string('son_mesaj', 500)->nullable();
            $table->foreignId('ajan_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('cikti_bayt')->default(0);
            $table->timestamps();
            $table->index(['pc_id', 'durum']);
        });

        Schema::create('konsol_olaylari', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('is_id')->constrained('konsol_isleri')->cascadeOnDelete();
            $table->string('asama', 20);
            $table->text('mesaj')->nullable();
            $table->string('kaynak', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('konsol_ciktilari', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('is_id')->constrained('konsol_isleri')->cascadeOnDelete();
            $table->longText('metin');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('konsol_girdileri', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('is_id')->constrained('konsol_isleri')->cascadeOnDelete();
            $table->text('metin');
            $table->foreignId('kullanici_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('gonderildi_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('konsol_girdileri');
        Schema::dropIfExists('konsol_ciktilari');
        Schema::dropIfExists('konsol_olaylari');
        Schema::dropIfExists('konsol_isleri');
        Schema::dropIfExists('konsol_pcleri');
    }
};
