<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Activity\ActivityChain;
use Illuminate\Console\Command;

/**
 * Denetim kaydının zincirini doğrular.
 *
 * Zamanlayıcı her gece çağırır; sonuç önbellekte durur, ekran oradan okur.
 * Elle de çalıştırılabilir:
 *
 *     docker exec syd php artisan audit:verify
 */
class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Denetim kaydının hash zincirini doğrular';

    public function handle(ActivityChain $zincir): int
    {
        $sonuc = $zincir->dogrula();

        $this->components->info(sprintf(
            'Bakılan: %d · korumasız (zincirden önceki): %d',
            $sonuc['bakilan'],
            $sonuc['korumasiz'],
        ));

        if ($sonuc['saglam']) {
            $this->components->info('Zincir sağlam.');

            return self::SUCCESS;
        }

        /*
         * Kırık zincir SESSİZ GEÇİLMEZ: komut hata koduyla döner ki
         * zamanlayıcının çıktısı ve dönüş kodu bunu görünür kılsın.
         */
        $this->components->error("ZİNCİR KIRIK. İlk tutmayan satır: #{$sonuc['kirik_id']}");

        return self::FAILURE;
    }
}
