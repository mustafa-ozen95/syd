<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'rol_id',
        'rol_yerel',
        'password',
        'aktif',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
            'rol_yerel' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** Merkezden gelen kimlik bağları. */
    public function kimlikler(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rol_id');
    }

    /**
     * Yöneticilik ROLDEDİR.
     *
     * Tek doğruluk kaynağı rol kaydıdır (bkz. roller_veriye_tasindi göçü).
     * Rolsüz kullanıcı yönetici değildir — eksik veri yetki üretmemeli.
     */
    public function isAdmin(): bool
    {
        return $this->rol?->yonetici === true;
    }

    /** Hesap açık mı? Kapalı hesabın ne oturumu ne jetonu çalışır. */
    public function isActive(): bool
    {
        return $this->aktif === true;
    }

    /**
     * Kişiye özel yetki istisnaları (bkz. UserPermissionResolver).
     *
     * @return HasMany<UserPermission, $this>
     */
    public function izinler(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /** @return HasMany<ApiToken, $this> */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function permissionRequests()
    {
        return $this->hasMany(PermissionRequest::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
