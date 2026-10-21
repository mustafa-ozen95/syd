<?php

declare(strict_types=1);

namespace App\ConsoleModule\Contracts;

/**
 * Kurulabilecek görüntülerin listesi. Modülün panele bağlı parçalarından
 * biri: SYD tablodan okur, başka bir panel başka yerden (ör. SYD'nin API'si).
 *
 * "adres" switch'in loader'da görüntüyü çekeceği yerdir (tftp://sunucu/dosya).
 *
 * @phpstan-type Goruntu array{anahtar: string, model: string, surum: string, etiket: string, dosya: string, adres: string}
 */
interface ImageSource
{
    /** @return list<Goruntu> */
    public function secenekler(): array;

    /** @return Goruntu|null */
    public function bul(string $anahtar): ?array;
}
