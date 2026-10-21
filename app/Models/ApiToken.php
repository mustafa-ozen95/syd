<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Dışarıya verilen bir API jetonu — kardeş paneldeki modelin aynısı.
 *
 * Jetonun kendisi SAKLANMAZ; yalnızca sha256 özeti tutulur. Kaybolan jeton
 * geri gösterilemez, yenisi üretilir (bkz. rotate).
 *
 * Jeton SAHİBİNİ AŞAMAZ: yetkisi, jetonun kendi listesi ile sahibinin panel
 * yetkilerinin KESİŞİMİDİR (bkz. TokenAbilityGate). Sahibinin yetkisi
 * daraltılınca jeton da daralır.
 */
class ApiToken extends Model
{
    /** Önekin ve sırrın ayracı: "syd_<sır>". */
    public const SEPARATOR = '_';

    protected $fillable = [
        'name',
        'user_id',
        'created_by',
        'token_hash',
        'prefix',
        'abilities',
        'allowed_ips',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        /* Adreste uuid görünür; sıralı birincil anahtar dışarı çıkmaz. */
        static::creating(function (self $token): void {
            $token->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Yeni bir sır üretir ve özetini döndürür. Sır YALNIZCA burada görülür;
     * çağıran onu kullanıcıya bir kez gösterir ve unutur.
     *
     * @return array{plain: string, hash: string, prefix: string}
     */
    public static function mint(): array
    {
        $prefix = (string) config('api.prefix');
        $secret = Str::random(max(32, (int) config('api.secret_bytes')));
        $plain = $prefix.self::SEPARATOR.$secret;

        return [
            'plain' => $plain,
            'hash' => hash('sha256', $plain),
            // Ekranda jetonu tanımak için; sır değildir.
            'prefix' => $prefix.self::SEPARATOR.substr($secret, 0, 6),
        ];
    }

    /** @return array<string, list<string>> kaynak => aksiyonlar */
    public function abilityMap(): array
    {
        return $this->abilities ?? [];
    }

    /** Jetonun KENDİ listesi; sahibinin yetkisi ayrıca sorulur. */
    public function permits(string $resource, string $action): bool
    {
        return in_array($action, $this->abilityMap()[$resource] ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * İstek bu adresten kabul edilir mi? Liste boşsa her yerden. Kural tek
     * IP ya da ağ (198.51.100.0/24) olabilir.
     */
    public function allowsIp(?string $ip): bool
    {
        $izinli = $this->allowed_ips ?? [];

        if ($izinli === []) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, array_map('strval', $izinli));
    }

    /**
     * İptal eder, SİLMEZ: kimin hangi jetonla ne yaptığı kayıtta durur ve
     * iptal edilmiş jetonun adı da o kaydın parçasıdır.
     */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
    }

    /**
     * Yeni bir sır üretir; eskisi anında geçersiz olur. revoked_at'e
     * DOKUNULMAZ: sır değiştirmek iptal edilmişi yeniden açmaz.
     *
     * @return string düz metin jeton; yalnızca burada görülür
     */
    public function rotate(): string
    {
        $yeni = self::mint();

        $this->forceFill([
            'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'],
            'last_used_at' => null,
            'last_used_ip' => null,
        ])->save();

        return $yeni['plain'];
    }

    /** @param  Builder<$this>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
