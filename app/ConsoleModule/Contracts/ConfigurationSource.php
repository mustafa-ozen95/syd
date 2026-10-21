<?php

declare(strict_types=1);

namespace App\ConsoleModule\Contracts;

/**
 * Cihazların kayıtlı yapılandırmaları (yedekler). SYD'de yedek deposu; bu
 * kaynağı olmayan bir panel boş liste döner ve "yedekten" seçenekleri
 * görünmez.
 */
interface ConfigurationSource
{
    /** Yedeği olan cihazlar, adla sıralı. @return list<string> */
    public function cihazlar(): array;

    /** Cihazın yedekleri, en yenisi önce. @return list<array{dosya: string, zaman: ?int}> */
    public function yedekler(string $cihaz): array;

    /**
     * Yedeğin DÜZ METNİ (sıkıştırılmışsa açılmış). Dosya verilmezse en son
     * yedek. Yoksa null.
     */
    public function icerik(string $cihaz, ?string $dosya = null): ?string;
}
