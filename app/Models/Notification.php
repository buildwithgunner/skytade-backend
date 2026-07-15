<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'audience',
        'recipient_user_id',
        'recipient_admin_id',
        'title',
        'severity',
        'type',
        'data',
        'action_url',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recipient_admin_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }
}
