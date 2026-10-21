<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ZTP KALDIRILDI: yerini loader'dan kurulum (Konsol modülü) aldı. Modülün
 * tabloları ve göç kayıtları silinir.
 *
 * Ajanlara verilmiş API jetonları SİLİNMEZ: hepsi iptal edilmiş durumda ve
 * kimin hangi jetonla ne yaptığı kaydının parçası (bkz. ApiToken::revoke).
 *
 * Geri dönüş yolu göç değil, yayın öncesi alınan veritabanı yedeğidir.
 */
return new class extends Migration
{
    private const TABLOLAR = ['ztp_olaylari', 'ztp_isleri', 'ztp_ayarlari', 'ztp_saha_pcleri'];

    /** Modülün kendi klasöründen koşan göçler; dosyaları artık yok. */
    private const GOCLER = [
        '2026_10_14_000400_ztp',
        '2026_10_14_000500_ztp_saha_pcleri',
        '2026_10_15_000100_ztp_otomatik_goruntu_yedek',
        '2026_10_15_000200_ztp_saha_pc_gorunen_adres',
        '2026_10_15_000300_ztp_anlik_ajan',
        '2026_10_15_000400_ztp_kurulum_karti',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLOLAR as $tablo) {
            Schema::dropIfExists($tablo);
        }

        Schema::enableForeignKeyConstraints();

        DB::table('migrations')->whereIn('migration', self::GOCLER)->delete();
    }

    public function down(): void
    {
        //
    }
};
