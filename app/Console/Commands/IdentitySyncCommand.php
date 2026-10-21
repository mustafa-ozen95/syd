<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\CenterClient;
use App\Services\Identity\IdentitySyncer;
use Illuminate\Console\Command;

/**
 * Merkezdeki erişim değişikliklerini bu panele taşır.
 *
 * YEDEK YOLDUR, asıl yol değil: merkez değişimi anında bildiriyor
 * (bkz. IdentityRefreshController). Zamanlayıcı bu turu gecede bir çağırır
 * (bkz. routes/console.php) ve kaybolan bir bildirimin kalıcı ayrışmaya
 * dönüşmesini engeller.
 *
 * Ara katman (VerifyIdentityStatus) yalnızca O AN İSTEK YAPAN kişiyi
 * soruyor; bu tur, panele hiç uğramayan hesapları da kapatır — erişimi
 * kalkan birinin oturumu bir daha hiç açılmasa bile hesap kapalı olmalı.
 */
class IdentitySyncCommand extends Command
{
    protected $signature = 'kimlik:esitle';

    protected $description = 'Merkezdeki hesap durumlarını bu panele işler';

    public function handle(CenterClient $merkez, IdentitySyncer $esitleyici): int
    {
        if (! $merkez->acikMi()) {
            $this->components->info('Merkezle giriş kapalı; eşitlenecek bir şey yok.');

            return self::SUCCESS;
        }

        $sonuc = $esitleyici->esitle();

        $this->components->info(sprintf(
            'Sorulan: %d · kapatılan: %d · açılan: %d',
            $sonuc['sorulan'],
            $sonuc['kapatilan'],
            $sonuc['acilan'],
        ));

        return self::SUCCESS;
    }
}
