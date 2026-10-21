<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etkinlik kaydı DENETİM KAYDINA dönüşür.
 *
 * Tablo bir işlem sayacı olarak doğmuştu: üç değerli bir enum
 * ve kullanıcı kimliği. Denetim kaydı olabilmesi için üç eksiği vardı:
 *
 *  1. TÜR ÜÇ DEĞERLE SINIRLIYDI. Merkezle giriş kaydı yazılmaya çalışılıyor
 *     ("merkez-giris") ama enum'da böyle bir değer yok — MariaDB sıkı kipte
 *     bunu reddeder, yani merkezle giriş son adımında düşerdi. Sütun artık
 *     serbest bir dize; var olan üç değer aynen çalışıyor (istatistik ekranı
 *     onlara bakıyor).
 *
 *  2. NE OLDUĞU YAZILMIYORDU. Yalnızca tür vardı; hangi kayda, hangi
 *     kullanıcıya dokunulduğu ve nereden gelindiği yoktu.
 *
 *  3. DEĞİŞTİRİLEBİLİRDİ. Kayıt satırları artık HMAC-SHA256 ile zincirlenir:
 *     her satır bir öncekinin özetini taşır. Bir satırı sonradan değiştiren
 *     ya da silen zinciri kırar ve `denetim:dogrula` kırılmanın yerini
 *     söyler. Amaç kaydı gizlemek değil, DEĞİŞTİRİLMEDİĞİNİ kanıtlamaktır.
 *
 * Ayrıca user_id'nin YABANCI ANAHTARI KALDIRILIR.
 *
 * Eskiden cascade siliniyordu: bir hesabı silmek onun bütün denetim kaydını
 * da götürüyordu — denetim kaydının varlık sebebine aykırı. "NULL'a düşür"
 * de çözüm değil: satırı DEĞİŞTİRİR ve zincir kırılır (özet user_id'yi de
 * kapsıyor). Denetim satırı canlı bir ilişki değil TARİHSEL BİR KAYITTIR;
 * kullanıcı silinse bile kimliği ve o günkü adı satırda kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });

        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->index('user_id');

            $table->string('tur', 60)->change();

            /*
             * İşi yapanın O GÜNKÜ adı. Hesap sonradan silinse ya da adı
             * değişse bile denetim satırı kimin yaptığını söylemeye devam
             * etmeli; kullanıcı tablosuna bakmak bunu veremez.
             */
            $table->string('kullanici_adi', 100)->nullable()->after('user_id');

            $table->text('aciklama')->nullable()->after('tur');

            /* Olayın konusu: kullanıcı ya da başka bir kayıt. */
            $table->nullableMorphs('konu');

            $table->string('ip_adresi', 45)->nullable();

            /*
             * Zincir alanları TOPLU ATAMAYA KAPALIDIR (bkz. ActivityLog):
             * onları yalnızca ActivityChain yazar. Açık olsalardı bir
             * controller kendi özetini uydurabilir ve zincirin varlık sebebi
             * ortadan kalkardı.
             */
            $table->string('onceki_ozet', 64)->nullable();
            $table->string('ozet', 64)->nullable();
            $table->unsignedSmallInteger('zincir_surumu')->nullable();

            $table->index(['tur', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['tur', 'created_at']);
            $table->dropIndex(['user_id']);
            $table->dropColumn([
                'kullanici_adi', 'aciklama', 'konu_type', 'konu_id',
                'ip_adresi', 'onceki_ozet', 'ozet', 'zincir_surumu',
            ]);
        });
    }
};
