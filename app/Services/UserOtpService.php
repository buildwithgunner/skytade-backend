<?php

namespace App\Services;

use App\Models\User;
use App\Models\LoginChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserOtpService
{
    public function __construct(
        private AuditLogService $auditLogs,
        private NotificationService $notifications,
    ) {
    }

    public function issueOtp(User $user, ?string $ipAddress = null): LoginChallenge
    {
        LoginChallenge::query()
            ->where('user_id', $user->id)
            ->where('context', 'signup_otp')
            ->whereNull('consumed_at')
            ->delete();

        $code = (string) random_int(100000, 999999);

        $challenge = LoginChallenge::create([
            'user_id' => $user->id,
            'context' => 'signup_otp',
            'challenge_token' => (string) Str::uuid(),
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        $auditLog = $this->auditLogs->record('user_otp_challenge_issued', $user, [
            'subject' => $challenge,
            'severity' => 'info',
            'ip_address' => $ipAddress,
            'data' => [
                'email' => $user->email,
                'context' => 'signup_otp',
            ],
        ]);

        $channels = $this->notifications->deliverUserOtp($user, $code, $auditLog);
        $challenge->forceFill(['delivery_channels' => $channels])->save();

        return $challenge->fresh();
    }

    public function verifyOtp(string $challengeToken, string $code, ?string $ipAddress = null): User
    {
        $challenge = LoginChallenge::query()
            ->with('user')
            ->where('challenge_token', $challengeToken)
            ->where('context', 'signup_otp')
            ->first();

        if (! $challenge || ! $challenge->user || ! $challenge->isActive()) {
            throw ValidationException::withMessages([
                'code' => ['The verification challenge is invalid or expired.'],
            ]);
        }

        if ($challenge->attempts >= 5) {
            throw ValidationException::withMessages([
                'code' => ['Too many invalid verification attempts. Please request a new OTP.'],
            ]);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->forceFill([
                'attempts' => $challenge->attempts + 1,
                'last_attempt_at' => now(),
            ])->save();

            $this->auditLogs->record('user_otp_challenge_failed', $challenge->user, [
                'subject' => $challenge,
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

        $challenge->user->forceFill([
            'email_verified_at' => now(),
            'account_status' => 'active',
        ])->save();

        $this->auditLogs->record('user_otp_challenge_verified', $challenge->user, [
            'subject' => $challenge,
            'severity' => 'info',
            'ip_address' => $ipAddress,
        ]);

        return $challenge->user;
    }
}
