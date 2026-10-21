<?php

declare(strict_types=1);

namespace App\Konsol\Models;

use Illuminate\Database\Eloquent\Model;

/** Konsolda görülenin bir parçası; sıra kimlikten gelir. */
class KonsolCiktisi extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'konsol_ciktilari';

    protected $fillable = ['is_id', 'metin'];
}
