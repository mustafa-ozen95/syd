<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Kurulum sayfasındaki Junos görüntüsü: model, sürüm ve TFTP sunucusundaki
 * dosya adı. Kurulum komutu buradan kurulur (bkz. komut).
 */
class JunosImage extends Model
{
    protected $table = 'junos_goruntuleri';

    protected $fillable = ['model', 'surum', 'dosya', 'sira'];

    protected function casts(): array
    {
        return ['sira' => 'integer'];
    }

    /** Switch'in CLI'ında çalıştırılacak komut. */
    public function komut(): string
    {
        return 'install --format '.rtrim((string) config('junos.tftp'), '/').'/'.$this->dosya;
    }

    /** @param  Builder<$this>  $query */
    public function scopeSirali(Builder $query): void
    {
        $query->orderBy('sira')->orderBy('id');
    }
}
