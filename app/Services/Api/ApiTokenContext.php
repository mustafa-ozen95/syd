<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiToken;

/**
 * İsteği yapan API jetonu. Oturumla gelen isteklerde boştur ve hiçbir
 * daraltma yapılmaz. İstek başına tektir; kapsayıcıda singleton durur.
 */
class ApiTokenContext
{
    private ?ApiToken $token = null;

    public function set(ApiToken $token): void
    {
        $this->token = $token;
    }

    /** Önceki isteğin jetonu sonrakine taşınmasın (bkz. ApiTokenGuard). */
    public function clear(): void
    {
        $this->token = null;
    }

    public function get(): ?ApiToken
    {
        return $this->token;
    }
}
