<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\LoginChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminMfaService
{
    public function __construct(
        private AuditLogService $auditLogs,
        private NotificationService $notifications,
    ) {
    }

    public function issueChallenge(Admin $admin, ?string $ipAddress = null): LoginChallenge
    {
        LoginChallenge::query()
            ->where('admin_id', $admin->id)
            ->where('context', 'admin_login')
            ->whereNull('consumed_at')
            ->delete();

        $code = (string) random_int(100000, 999999);

        $challenge = LoginChallenge::create([
            'admin_id' => $admin->id,
            'context' => 'admin_login',
            'challenge_token' => (string) Str::uuid(),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        $auditLog = $this->auditLogs->record('admin_mfa_challenge_issued', $admin, [
            'subject' => $challenge,
            'severity' => 'info',
            'ip_address' => $ipAddress,
            'data' => [
                'email' => $admin->email,
                'context' => 'admin_login',
            ],
        ]);

        $channels = $this->notifications->deliverLoginChallenge($admin, $code, $auditLog);
        $challenge->forceFill(['delivery_channels' => $channels])->save();

        return $challenge->fresh();
    }

    public function verifyChallenge(string $challengeToken, string $code, ?string $ipAddress = null): Admin
    {
        $challenge = LoginChallenge::query()
            ->with('admin')
            ->where('challenge_token', $challengeToken)
            ->first();

        if (! $challenge || ! $challenge->admin || ! $challenge->isActive()) {
            throw ValidationException::withMessages([
                'code' => ['The verification challenge is invalid or expired.'],
            ]);
        }

        if ($challenge->attempts >= 5) {
            throw ValidationException::withMessages([
                'code' => ['Too many invalid verification attempts. Start the login flow again.'],
            ]);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->forceFill([
                'attempts' => $challenge->attempts + 1,
                'last_attempt_at' => now(),
            ])->save();

            $this->auditLogs->record('admin_mfa_challenge_failed', [
                'subject' => $challenge,
                'admin_id' => $challenge->admin_id,
                'severity' => 'warning',
                'ip_address' => $ipAddress,
                'data' => [
                    'attempts' => $challenge->attempts,
                ],
            ]);

            throw ValidationException::withMessages([
                'code' => ['The verification code is incorrect.'],
            ]);
        }

        $challenge->forceFill([
            'consumed_at' => now(),
            'last_attempt_at' => now(),
            'attempts' => $challenge->attempts + 1,
        ])->save();

        $challenge->admin->forceFill([
            'last_mfa_at' => now(),
        ])->save();

        $this->auditLogs->record('admin_mfa_challenge_verified', $challenge->admin, [
            'subject' => $challenge,
            'severity' => 'info',
            'ip_address' => $ipAddress,
        ]);

        return $challenge->admin;
    }
}
