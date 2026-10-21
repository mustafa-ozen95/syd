<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Yetki talebi: kullanıcı kendi profilinden yedekleri görmek ister, admin
 * panelden onaylar/reddeder. Onay kişiye yedek istisnası yazar (bkz.
 * UserController::onaylaYetkiTalebi).
 */
class PermissionRequest extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'perm_yedek', 'durum'];

    protected function casts(): array
    {
        return [
            'perm_yedek' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $talep) {
            $talep->created_at ??= now();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
