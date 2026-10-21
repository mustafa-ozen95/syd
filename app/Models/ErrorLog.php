<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Panele düşen uygulama hatası. Dosya günlüğüne EK bir görünüm; aynı hata
 * tekrar ederse yeni satır açılmaz, sayaç artar.
 */
class ErrorLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'signature', 'level', 'type', 'message', 'file', 'line',
        'context_label', 'method', 'user_id', 'actor_name', 'ip_address',
        'trace', 'occurrences', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
