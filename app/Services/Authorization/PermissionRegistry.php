<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use InvalidArgumentException;

/**
 * config/permissions.php dosyasının tek okuma noktası.
 *
 * Kaynak anahtarları ve aksiyonlar uygulamanın her yerinde bu sınıf üzerinden
 * sorulur; config() çağrısı controller, view veya request içine dağılmaz.
 */
class PermissionRegistry
{
    /** @return array<string, string> aksiyon => etiket */
    public function actions(): array
    {
        return config('permissions.actions', []);
    }

    /** @return array<string, array<string, mixed>> */
    public function resources(): array
    {
        return config('permissions.resources', []);
    }

    /** @return array<int, string> */
    public function resourceKeys(): array
    {
        return array_keys($this->resources());
    }

    /** @return array<string, mixed> */
    public function resource(string $resource): array
    {
        $definition = $this->resources()[$resource] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Tanımsız yetki kaynağı: {$resource}");
        }

        return $definition;
    }

    /** @return array<int, string> */
    public function actionsFor(string $resource): array
    {
        return $this->resource($resource)['actions'] ?? ['view'];
    }

    public function supports(string $resource, string $action): bool
    {
        return isset($this->resources()[$resource])
            && in_array($action, $this->actionsFor($resource), true);
    }

    public function label(string $resource): string
    {
        return $this->resource($resource)['label'] ?? $resource;
    }

    public function actionLabel(string $action): string
    {
        return $this->actions()[$action] ?? $action;
    }
}
