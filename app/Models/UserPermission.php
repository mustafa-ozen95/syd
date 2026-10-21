<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kişiye özel yetki İSTİSNASI.
 *
 * Rol canlı okunur; bu satır onun üstüne biner: `izin` rolün vermediğini
 * ekler, `yasak` verdiğini keser. Satırı olmayan yerde rol geçerlidir
 * (bkz. UserPermissionResolver).
 */
class UserPermission extends Model
{
    public const IZIN = 'izin';

    public const YASAK = 'yasak';

    protected $table = 'kullanici_izinleri';

    protected $fillable = ['user_id', 'kaynak', 'aksiyon', 'etki'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
