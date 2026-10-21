<?php

declare(strict_types=1);

namespace App\ConsoleModule\Models;

use Illuminate\Database\Eloquent\Model;

/** Konsolda görülenin bir parçası; sıra kimlikten gelir. */
class ConsoleOutput extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'konsol_ciktilari';

    protected $fillable = ['is_id', 'metin'];
}
