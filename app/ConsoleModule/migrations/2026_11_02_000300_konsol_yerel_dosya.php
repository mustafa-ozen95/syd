<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Panelin listeleri dışında, operatörün bilgisayarından seçilen dosyalar
 * (ikisi de isteğe bağlı, yalnız o iş için):
 *   yedek_metni     yüklenen yedeğin metni; şifreli, iş kapanınca silinir.
 *   gecici_goruntu  TFTP dizinine yalnız bu iş için konan Junos dosyası;
 *                   iş kapanınca panel siler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->longText('yedek_metni')->nullable()->after('yedek_dosya');
            $table->string('gecici_goruntu', 255)->nullable()->after('goruntu_adres');
        });
    }

    public function down(): void
    {
        Schema::table('konsol_isleri', function (Blueprint $table): void {
            $table->dropColumn(['yedek_metni', 'gecici_goruntu']);
        });
    }
};
