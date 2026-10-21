<?php

declare(strict_types=1);

namespace App\Konsol\Models;

use Illuminate\Database\Eloquent\Model;

/** İşin bir adımı: kim (ajan ya da kişi) ne zaman ne bildirdi. */
class KonsolOlayi extends Model
{
    protected $table = 'konsol_olaylari';

    protected $fillable = ['is_id', 'asama', 'mesaj', 'kaynak'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
