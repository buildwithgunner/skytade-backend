<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    public function record(string $eventType, $actor = null, array $context = []): AuditLog
    {
        $subject = $context['subject'] ?? null;
        $occurredAt = $context['occurred_at'] ?? now();
        $data = $context['data'] ?? [];
        $severity = $context['severity'] ?? 'info';
        $previousHash = AuditLog::query()->latest('id')->value('event_hash');

        $actorUserId = $actor instanceof User ? $actor->id : ($context['actor_user_id'] ?? null);
        $adminId = $actor instanceof Admin ? $actor->id : ($context['admin_id'] ?? null);

        $fingerprint = [
            'event_type' => $eventType,
            'actor_user_id' => $actorUserId,
            'admin_id' => $adminId,
            'subject_type' => $subject instanceof Model ? $subject::class : ($context['subject_type'] ?? null),
            'subject_id' => $subject instanceof Model ? $subject->getKey() : ($context['subject_id'] ?? null),
            'severity' => $severity,
            'ip_address' => $context['ip_address'] ?? null,
            'data' => $data,
            'occurred_at' => $occurredAt->toIso8601String(),
        ];

        return AuditLog::create([
            'actor_user_id' => $actorUserId,
            'admin_id' => $adminId,
            'event_type' => $eventType,
            'subject_type' => $fingerprint['subject_type'],
            'subject_id' => $fingerprint['subject_id'],
            'severity' => $severity,
            'ip_address' => $fingerprint['ip_address'],
            'data' => $data,
            'previous_hash' => $previousHash,
            'event_hash' => hash('sha256', ($previousHash ?? 'root') . '|' . json_encode($fingerprint, JSON_THROW_ON_ERROR)),
            'occurred_at' => $occurredAt,
        ]);
    }
}
