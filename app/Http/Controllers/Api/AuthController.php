<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Models\LoginChallenge;
use App\Services\AdminMfaService;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\UserOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private AuditLogService $auditLogs,
        private AdminMfaService $adminMfa,
        private UserOtpService $userOtp,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('admins', 'email'),
            ],
            'password' => ['required', 'string', 'confirmed', Password::min(6)->letters()->numbers()],
            'role' => ['required', Rule::in(['user'])],
            'account_type' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'account_type' => $validated['account_type'] ?? null,
            'account_status' => 'pending_otp',
        ]);

        $this->notifications->createNotification([
            'audience' => 'admin',
            'title' => 'New user onboarding',
            'severity' => 'info',
            'type' => 'new_user',
            'data' => [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => $user->role,
                'account_type' => $user->account_type,
            ],
            'action_url' => '/admin-dashboard',
        ]);

        $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $user->id,
            'title' => 'Welcome to Skytrade',
            'severity' => 'success',
            'type' => 'welcome',
            'data' => [
                'message' => 'Complete your compliance profile to unlock investment requests and portfolio operations.',
            ],
            'action_url' => '/platform',
        ]);

        $this->auditLogs->record('user_registered', [
            'subject' => $user,
            'severity' => 'info',
            'ip_address' => $request->ip(),
            'data' => [
                'role' => $user->role,
                'account_type' => $user->account_type,
            ],
        ]);

        $challenge = $this->userOtp->issueOtp($user, $request->ip());

        return response()->json([
            'message' => 'OTP sent to your email. Please verify.',
            'challenge_token' => $challenge->challenge_token,
            'user' => $user,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);

        if ($validated['role'] === 'admin') {
            return $this->adminLogin($request, $validated);
        }

        return $this->userLogin($request, $validated);
    }

    protected function userLogin(Request $request, array $validated): JsonResponse
    {
        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password) || $user->role !== 'user') {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->account_status === 'pending_otp') {
            $challenge = $this->userOtp->issueOtp($user, $request->ip());
            return response()->json([
                'otp_required' => true,
                'challenge_token' => $challenge->challenge_token,
            ], 202);
        }

        if ($user->account_status !== 'active') {
            return response()->json([
                'message' => 'Your account is currently restricted. Please contact support.',
            ], 423);
        }

        $this->auditLogs->record('user_login_completed', [
            'subject' => $user,
            'severity' => 'info',
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'user' => $user,
            'permissions' => [],
            'token' => $this->issueUserToken($user, $request),
        ]);
    }

    protected function adminLogin(Request $request, array $validated): JsonResponse
    {
        $admin = Admin::where('email', $validated['email'])->first();

        if (! $admin || ! Hash::check($validated['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($admin->account_status !== 'active') {
            return response()->json([
                'message' => 'Your account is currently restricted. Please contact support.',
            ], 423);
        }

        $challenge = $this->adminMfa->issueChallenge($admin, $request->ip());

        return response()->json([
            'mfa_required' => true,
            'challenge_token' => $challenge->challenge_token,
        ], 202);
    }

    public function verifyAdminMfa(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $admin = $this->adminMfa->verifyChallenge(
            $validated['challenge_token'],
            $validated['code'],
            $request->ip(),
        );

        return response()->json([
            'user' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'admin',
                'staff_id' => $admin->staff_id,
            ],
            'permissions' => $admin->resolvedAdminPermissions(),
            'token' => $this->issueAdminToken($admin, $request),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        if ($admin) {
            return response()->json([
                'user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => 'admin',
                    'staff_id' => $admin->staff_id,
                ],
                'permissions' => $admin->resolvedAdminPermissions(),
            ]);
        }

        $user = $request->user();
        return response()->json([
            'user' => $user,
            'permissions' => [],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();
        $request->user('admin')?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    protected function issueUserToken(User $user, Request $request): string
    {
        $user->tokens()->delete();
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->auditLogs->record('user_session_issued', [
            'subject' => $user,
            'severity' => 'info',
            'ip_address' => $request->ip(),
        ]);

        return $user->createToken('user-token', ['investor:*'])->plainTextToken;
    }

    protected function issueAdminToken(Admin $admin, Request $request): string
    {
        $admin->tokens()->delete();
        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $this->auditLogs->record('admin_login_completed', [
            'subject' => $admin,
            'actor_type' => Admin::class,
            'actor_id' => $admin->id,
            'severity' => 'info',
            'ip_address' => $request->ip(),
        ]);

        return $admin->createToken('admin-token', ['admin:*'])->plainTextToken;
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $user = $this->userOtp->verifyOtp(
            $validated['challenge_token'],
            $validated['code'],
            $request->ip()
        );

        return response()->json([
            'user' => $user,
            'permissions' => [],
            'token' => $this->issueUserToken($user, $request),
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
        ]);

        $challenge = LoginChallenge::where('challenge_token', $validated['challenge_token'])
            ->where('context', 'signup_otp')
            ->first();

        if (! $challenge || ! $challenge->user || ! $challenge->isActive()) {
            throw ValidationException::withMessages([
                'challenge_token' => ['The verification session is invalid or has expired.'],
            ]);
        }

        $newChallenge = $this->userOtp->issueOtp($challenge->user, $request->ip(), true);

        return response()->json([
            'message' => 'A new verification code has been sent.',
            'challenge_token' => $newChallenge->challenge_token,
        ]);
    }
}
