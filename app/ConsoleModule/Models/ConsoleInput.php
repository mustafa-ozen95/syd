<?php

declare(strict_types=1);

namespace App\ConsoleModule\Models;

use Illuminate\Database\Eloquent\Model;

/** Panelden konsola elle yazılan; ajan bir sonraki aktarımda alır. */
class ConsoleInput extends Model
{
    protected $table = 'konsol_girdileri';

    protected $fillable = ['is_id', 'metin', 'kullanici_id', 'gonderildi_at'];

    protected function casts(): array
    {
        return ['gonderildi_at' => 'immutable_datetime'];
    }
}
