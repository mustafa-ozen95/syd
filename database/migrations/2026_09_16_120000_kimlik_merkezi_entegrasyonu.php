<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KİMLİK YÖNETİM MERKEZİ İLE GİRİŞ.
 *
 * Bu panel önceden kendi kullanıcı adı/parolasıyla çalışıyordu. Aynı kişi
 * her panelde aynı kişi olsun diye kimlik merkezden alınır.
 *
 * İKİ ŞEY GEREKİYOR:
 *
 *   e-posta   Merkez kimliği ADRESLE eşleştiriyor; bu tabloda e-posta alanı
 *             hiç yoktu (giriş `username` ile yapılıyordu). Alan NULL
 *             bırakılabilir: merkeze bağlanmamış yerel hesaplar çalışmaya
 *             devam etsin.
 *
 *   bağ       Kimlik ile yerel hesabın kalıcı bağı. Kişi merkezde
 *             e-postasını değiştirse de bağ kopmasın diye eşleşme
 *             `subject` üzerinden tutulur; e-posta yalnızca ilk eşleşmede
 *             ve kayıt için saklanır.
 *
 * YEREL GİRİŞ KAPANMIYOR. Merkez erişilemez olduğunda panel kilitlenmemeli;
 * parolayla giriş yerinde duruyor (bkz. CenterLoginController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->unique()->after('username');
            }
        });

        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id();

            /* Hesap silinirse bağ da gider: sahipsiz bağ kimseyi tanımlamaz. */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /* Bugün tek sağlayıcı var ("kimlik"); alan ikincisine yer bırakıyor. */
            $table->string('provider', 30);
            $table->string('subject');
            $table->string('email')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            /* Bir kimlik tek bir hesaba bağlanır. */
            $table->unique(['provider', 'subject']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'email')) {
                $table->dropColumn('email');
            }
        });
    }
};
