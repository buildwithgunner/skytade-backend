<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_id',
        'audit_log_id',
        'recipient_user_id',
        'recipient_admin_id',
        'channel',
        'destination',
        'provider',
        'status',
        'error_message',
        'payload',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recipient_admin_id');
    }
}
