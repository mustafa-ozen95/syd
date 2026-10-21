<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Authorization: Bearer ..." başlığındaki jetonu kullanıcıya çevirir —
 * Kardeş paneldeki koruyucunun aynısı.
 *
 * Oturum çerezi KABUL EDİLMEZ: API ayrı bir kapıdır. Jeton düz metin
 * saklanmadığı için arama özet üzerinden yapılır. Reddin sebebi (süresi
 * dolmuş, iptal, adres) yanıtta ayırt edilmez; son kullanım kaydına yalnız
 * başarılı kullanım geçer.
 */
class ApiTokenGuard
{
    public function __construct(private ApiTokenContext $context) {}

    public function __invoke(Request $request): ?User
    {
        $this->context->clear();

        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            return null;
        }

        $token = ApiToken::query()
            ->with('user.rol')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if ($token === null || ! $token->isUsable() || ! $token->allowsIp($request->ip())) {
            return null;
        }

        $user = $token->user;

        // Kapatılmış hesabın jetonu da çalışmaz.
        if ($user === null || ! $user->isActive()) {
            return null;
        }

        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->save();

        $this->context->set($token);

        return $user;
    }
}
