<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\User;
use App\Services\Authorization\PermissionRegistry;

/**
 * API jetonunun yetkisini SAHİBİNİNKİYLE kesiştirir (kardeş paneldeki
 * kapının aynısı).
 *
 *   1. Jeton bu "kaynak:aksiyon"u taşıyor mu? Taşımıyorsa burada biter.
 *   2. Taşıyorsa karar normal zincire BIRAKILIR — sahibinin rolü,
 *      istisnaları, yönetici geçişi.
 *
 * VARSAYILAN KAPALIDIR: sözlükte olmayan her yetenek ("admin" dahil) jetonla
 * reddedilir. Yönetici jetonu da yalnız kendi listesindekini yapar.
 */
class TokenAbilityGate
{
    public function __construct(
        private ApiTokenContext $context,
        private PermissionRegistry $registry,
    ) {}

    /** @return bool|null false: reddet. null: normal zincire bırak. */
    public function check(User $user, string $ability): ?bool
    {
        $token = $this->context->get();

        if ($token === null) {
            return null;
        }

        [$resource, $action] = array_pad(explode(':', $ability, 2), 2, '');

        if (! $this->registry->supports($resource, $action)) {
            return false;
        }

        return $token->permits($resource, $action) ? null : false;
    }
}
