<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function createNotification(array $attributes, ?AuditLog $auditLog = null, ?Collection $overrideRecipients = null): Notification
    {
        $notification = Notification::create($attributes);

        if (($notification->severity ?? null) === 'critical') {
            $this->deliverCriticalNotification($notification, $auditLog, $overrideRecipients);
        }

        return $notification;
    }

    public function deliverCriticalNotification(Notification $notification, ?AuditLog $auditLog = null, ?Collection $overrideRecipients = null): void
    {
        $recipients = $overrideRecipients ?? $this->resolveRecipientsForNotification($notification);
        $message = $notification->data['message']
            ?? $notification->data['reason']
            ?? $notification->data['review_notes']
            ?? $notification->type;

        foreach ($recipients as $recipient) {
            foreach ($this->channelsForRecipient($recipient, true) as $channel) {
                $this->deliverMessage(
                    notification: $notification,
                    auditLog: $auditLog,
                    recipient: $recipient,
                    channel: $channel,
                    title: $notification->title ?? 'Skytrade critical alert',
                    message: (string) $message,
                    payload: [
                        'type' => $notification->type,
                        'severity' => $notification->severity,
                        'action_url' => $notification->action_url,
                    ],
                );
            }
        }
    }

    public function deliverLoginChallenge(Admin $admin, string $code, ?AuditLog $auditLog = null, ?array $channels = null): array
    {
        $deliveryChannels = $channels ?: $this->channelsForRecipient($admin, true);
        $message = "Your Skytrade admin verification code is {$code}. It expires in 10 minutes.";

        foreach ($deliveryChannels as $channel) {
            $this->deliverMessage(
                notification: null,
                auditLog: $auditLog,
                recipient: $admin,
                channel: $channel,
                title: 'Skytrade admin login verification',
                message: $message,
                payload: ['context' => 'admin_login'],
            );
        }

        return $deliveryChannels;
    }

    public function deliverUserOtp(User $user, string $code, ?AuditLog $auditLog = null): array
    {
        $deliveryChannels = ['email'];
        $message = "Your Skytrade verification code is {$code}. It expires in 10 minutes.";

        foreach ($deliveryChannels as $channel) {
            $this->deliverMessage(
                notification: null,
                auditLog: $auditLog,
                recipient: $user,
                channel: $channel,
                title: 'Skytrade sign up verification',
                message: $message,
                payload: ['context' => 'signup_otp'],
            );
        }

        return $deliveryChannels;
    }

    public function channelsForRecipient(Model $recipient, bool $critical = false): array
    {
        $configured = $recipient->notification_channels ?? null;

        if (! is_array($configured) || $configured === []) {
            $configured = ['email'];

            if ($critical && ! empty($recipient->phone)) {
                $configured[] = 'sms';
            }

            if ($critical && (! empty($recipient->push_channel_key) || $recipient instanceof Admin)) {
                $configured[] = 'push';
            }
        }

        return array_values(array_filter(array_unique($configured), function (string $channel) use ($recipient): bool {
            return match ($channel) {
                'email' => ! empty($recipient->email),
                'sms' => ! empty($recipient->phone),
                'push' => ! empty($recipient->push_channel_key) || $recipient instanceof Admin,
                default => false,
            };
        }));
    }

    protected function resolveRecipientsForNotification(Notification $notification): Collection
    {
        if ($notification->recipient_user_id) {
            $recipient = User::find($notification->recipient_user_id);
            return new Collection($recipient ? [$recipient] : []);
        }

        if ($notification->recipient_admin_id) {
            $recipient = Admin::find($notification->recipient_admin_id);
            return new Collection($recipient ? [$recipient] : []);
        }

        if ($notification->audience === 'admin') {
            return Admin::query()
                ->where('account_status', 'active')
                ->get();
        }

        return new Collection();
    }

    protected function deliverMessage(
        ?Notification $notification,
        ?AuditLog $auditLog,
        Model $recipient,
        string $channel,
        string $title,
        string $message,
        array $payload = [],
    ): NotificationDelivery {
        $destination = $this->destinationForRecipient($recipient, $channel);
        $isAdmin = $recipient instanceof Admin;
        $recipientUserId = $isAdmin ? null : ($recipient instanceof User ? $recipient->id : null);
        $recipientAdminId = $isAdmin ? $recipient->id : null;

        if (! $destination) {
            return NotificationDelivery::create([
                'notification_id' => $notification?->id,
                'audit_log_id' => $auditLog?->id,
                'recipient_user_id' => $recipientUserId,
                'recipient_admin_id' => $recipientAdminId,
                'channel' => $channel,
                'status' => 'skipped',
                'error_message' => 'No destination configured for requested channel.',
                'payload' => $payload,
                'failed_at' => now(),
            ]);
        }

        try {
            if ($channel === 'email') {
                Mail::raw($message, function ($mail) use ($recipient, $title) {
                    $mail->to($recipient->email)->subject($title);
                });

                return NotificationDelivery::create([
                    'notification_id' => $notification?->id,
                    'audit_log_id' => $auditLog?->id,
                    'recipient_user_id' => $recipientUserId,
                    'recipient_admin_id' => $recipientAdminId,
                    'channel' => $channel,
                    'destination' => $destination,
                    'provider' => (string) config('mail.default', 'mail'),
                    'status' => 'sent',
                    'payload' => $payload,
                    'sent_at' => now(),
                    'delivered_at' => now(),
                ]);
            }

            logger()->info('Skytrade notification delivery', [
                'channel' => $channel,
                'destination' => $destination,
                'title' => $title,
                'message' => $message,
                'payload' => $payload,
            ]);

            return NotificationDelivery::create([
                'notification_id' => $notification?->id,
                'audit_log_id' => $auditLog?->id,
                'recipient_user_id' => $recipientUserId,
                'recipient_admin_id' => $recipientAdminId,
                'channel' => $channel,
                'destination' => $destination,
                'provider' => $channel === 'sms' ? 'simulated_sms' : 'simulated_push',
                'status' => 'sent',
                'payload' => $payload,
                'sent_at' => now(),
                'delivered_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            return NotificationDelivery::create([
                'notification_id' => $notification?->id,
                'audit_log_id' => $auditLog?->id,
                'recipient_user_id' => $recipientUserId,
                'recipient_admin_id' => $recipientAdminId,
                'channel' => $channel,
                'destination' => $destination,
                'provider' => $channel === 'email' ? (string) config('mail.default', 'mail') : 'simulated',
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'payload' => $payload,
                'failed_at' => now(),
            ]);
        }
    }

    protected function destinationForRecipient(Model $recipient, string $channel): ?string
    {
        return match ($channel) {
            'email' => $recipient->email ?? null,
            'sms' => $recipient->phone ?? null,
            'push' => $recipient->push_channel_key ?? ($recipient instanceof Admin ? $recipient->email : null),
            default => null,
        };
    }
}
