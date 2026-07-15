<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'admin_id',
        'context',
        'challenge_token',
        'code_hash',
        'delivery_channels',
        'expires_at',
        'consumed_at',
        'attempts',
        'last_attempt_at',
    ];

    protected $casts = [
        'delivery_channels' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function isActive(): bool
    {
        return $this->consumed_at === null && $this->expires_at?->isFuture();
    }
}
