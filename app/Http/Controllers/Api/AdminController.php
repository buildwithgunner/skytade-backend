<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestmentPackage;
use App\Models\InvestmentRequest;
use App\Models\MoneyMovement;
use App\Models\Notification;
use App\Models\UserDocument;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private NotificationService $notifications, 
        private AuditLogService $auditLogs,
    ) {
    }

    public function overview(Request $request): JsonResponse
    {
        $metrics = [
            'total_investors' => User::where('role', 'user')->count(),
            'pending_requests' => InvestmentRequest::where('status', 'pending')->count(),
            'approved_requests' => InvestmentRequest::where('status', 'approved')->count(),
            'awaiting_secondary_approval' => InvestmentRequest::where('status', 'awaiting_secondary_approval')->count(),
            'flagged_requests' => InvestmentRequest::where('risk_score', '>=', 60)->count(),
            'restricted_users' => User::where('role', 'user')->where('account_status', '!=', 'active')->count(),
            'pending_kyc' => User::where('role', 'user')->where('kyc_completed', false)->count(),
            'pending_suitability' => User::where('role', 'user')->where('suitability_completed', false)->count(),
            'unread_admin_notifications' => Notification::where('audience', 'admin')->whereNull('read_at')->count(),
            'approved_volume' => (float) InvestmentRequest::where('status', 'approved')->sum('amount'),
            'pending_reconciliations' => MoneyMovement::where('reconciliation_status', 'pending')->count(),
            'pending_documents' => UserDocument::where('status', 'pending')->count(),
            'total_client_balance' => (float) User::where('role', 'user')->sum('account_balance'),
            'total_client_profit' => (float) User::where('role', 'user')->sum('total_profit'),
        ];

        $securityAlerts = InvestmentRequest::with(['user:id,name,email,account_status'])
            ->where('risk_score', '>=', 60)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $recentlyActiveInvestors = User::query()
            ->where('role', 'user')
            ->whereNotNull('last_login_at')
            ->orderByDesc('last_login_at')
            ->limit(6)
            ->get([
                'id',
                'name',
                'email',
                'account_status',
                'last_login_at',
                'last_login_ip',
            ])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_status' => $user->account_status,
                'last_login_at' => $user->last_login_at,
                'last_login_ip' => $this->maskIpAddress($user->last_login_ip),
            ])
            ->values();

        return response()->json([
            'metrics' => $metrics,
            'security_alerts' => $securityAlerts,
            'recently_active_investors' => $recentlyActiveInvestors,
            'permissions' => $request->user()->resolvedAdminPermissions(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'review', 'suspended'])],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->where('role', 'user')
            ->withCount([
                'tokens as active_sessions_count',
                'investmentRequests as total_requests_count',
                'documents as documents_count',
            ]);

        if (! empty($validated['status'])) {
            $query->where('account_status', $validated['status']);
        }

        if (! empty($validated['search'])) {
            $query->where(function ($builder) use ($validated) {
                $builder
                    ->where('name', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('email', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('account_type', 'like', '%' . $validated['search'] . '%');
            });
        }

        $users = $query
            ->orderByDesc('created_at')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get([
                'id',
                'name',
                'email',
                'account_type',
                'account_status',
                'kyc_completed',
                'suitability_completed',
                'account_balance',
                'total_profit',
                'bonus_balance',
                'last_login_at',
                'last_login_ip',
                'created_at',
            ])
            ->map(fn (User $user) => $this->transformUserListItem($user))
            ->values();

        return response()->json([
            'users' => $users,
        ]);
    }

    public function showUser(User $user): JsonResponse
    {
        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Administrative accounts are not exposed from this endpoint.',
            ], 422);
        }

        $user->load([
            'investmentRequests' => fn ($query) => $query
                ->latest()
                ->limit(5)
                ->with('reviewer:id,name,email'),
            'notifications' => fn ($query) => $query
                ->latest()
                ->limit(5),
            'documents' => fn ($query) => $query
                ->latest()
                ->limit(8)
                ->with('reviewer:id,name,email'),
            'moneyMovements' => fn ($query) => $query
                ->latest()
                ->limit(8)
                ->with('approver:id,name,email'),
        ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_type' => $user->account_type,
                'account_status' => $user->account_status,
                'kyc_completed' => $user->kyc_completed,
                'suitability_completed' => $user->suitability_completed,
                'last_login_at' => $user->last_login_at,
                'last_login_ip' => $this->maskIpAddress($user->last_login_ip),
                'created_at' => $user->created_at,
                'phone' => $user->phone,
                'notification_channels' => $user->notification_channels,
                'push_channel_key' => $user->push_channel_key ? 'configured' : null,
                'address' => $user->address,
                'zip_code' => $user->zip_code,
                'dob' => $user->dob,
                'government_id' => $user->government_id,
                'annual_income' => $user->annual_income,
                'employment_status' => $user->employment_status,
                'source_of_funds' => $user->source_of_funds,
                'knowledge_level' => $user->knowledge_level,
                'experience_assets' => $user->experience_assets,
                'risk_tolerance_scenario' => $user->risk_tolerance_scenario,
                'investment_goals' => $user->investment_goals,
                'account_balance' => $user->account_balance,
                'total_profit' => $user->total_profit,
                'bonus_balance' => $user->bonus_balance,
                'active_sessions_count' => $user->tokens()->count(),
                'recent_requests' => $user->investmentRequests,
                'recent_notifications' => $user->notifications,
                'documents' => $user->documents,
                'recent_transactions' => $user->moneyMovements,
            ],
        ]);
    }

    public function updateUserStatus(Request $request, User $user): JsonResponse
    {
        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Admin accounts cannot be modified from this endpoint.',
            ], 422);
        }

        $validated = $request->validate([
            'account_status' => ['required', Rule::in(['active', 'review', 'suspended'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update([
            'account_status' => $validated['account_status'],
        ]);

        $notification = $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $user->id,
            'title' => 'Account status updated',
            'severity' => $validated['account_status'] === 'suspended' ? 'critical' : ($validated['account_status'] === 'active' ? 'success' : 'warning'),
            'type' => 'account_status_updated',
            'data' => [
                'account_status' => $validated['account_status'],
                'reason' => $validated['reason'] ?? null,
                'message' => 'Your account status changed to ' . $validated['account_status'] . '.',
            ],
            'action_url' => '/platform',
        ]);

        $this->auditLogs->record('user_account_status_updated', $request->user(), [
            'subject' => $user,
            'severity' => $validated['account_status'] === 'suspended' ? 'critical' : 'info',
            'ip_address' => $request->ip(),
            'data' => [
                'target_user_name' => $user->name,
                'target_user_email' => $user->email,
                'account_status' => $validated['account_status'],
                'reason' => $validated['reason'] ?? null,
                'notification_id' => $notification->id,
            ],
        ]);

        return response()->json([
            'message' => 'User status updated successfully.',
            'user' => $this->transformUserListItem($user->fresh()->loadCount([
                'tokens as active_sessions_count',
                'investmentRequests as total_requests_count',
                'documents as documents_count',
            ])),
        ]);
    }

    public function updateUserFinance(Request $request, User $user): JsonResponse
    {
        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Admin accounts cannot be updated from this endpoint.',
            ], 422);
        }

        $validated = $request->validate([
            'action' => ['required', Rule::in(['add_profit', 'reduce_profit', 'add_bonus', 'reduce_bonus'])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $amount = round((float) $validated['amount'], 2);
        $action = $validated['action'];
        $actor = $request->user();
        $note = $validated['note'] ?? null;

        DB::transaction(function () use ($user, $amount, $action) {
            $user->refresh();

            if ($action === 'add_profit') {
                $user->increment('total_profit', $amount);
                $user->increment('account_balance', $amount);

                return;
            }

            if ($action === 'reduce_profit') {
                $reduction = min($user->total_profit, $amount);
                $user->forceFill([
                    'total_profit' => round($user->total_profit - $reduction, 2),
                    'account_balance' => round(max(0, $user->account_balance - $reduction), 2),
                ])->save();

                return;
            }

            if ($action === 'add_bonus') {
                $user->increment('bonus_balance', $amount);
                $user->increment('account_balance', $amount);

                return;
            }

            $reduction = min($user->bonus_balance, $amount);
            $user->forceFill([
                'bonus_balance' => round($user->bonus_balance - $reduction, 2),
                'account_balance' => round(max(0, $user->account_balance - $reduction), 2),
            ])->save();
        });

        $updatedUser = $user->fresh()->loadCount([
            'tokens as active_sessions_count',
            'investmentRequests as total_requests_count',
            'documents as documents_count',
        ]);

        $label = Str::headline(str_replace('_', ' ', $action));

        $notification = $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $user->id,
            'title' => $label . ' applied',
            'severity' => str_contains($action, 'reduce') ? 'warning' : 'success',
            'type' => 'wallet_adjustment',
            'data' => [
                'message' => $label . ' of $' . number_format($amount, 2) . ' has been applied to your account.',
                'action' => $action,
                'amount' => $amount,
                'note' => $note,
            ],
            'action_url' => '/user/dashboard',
        ]);

        $this->auditLogs->record('user_finance_adjusted', $actor, [
            'subject' => $updatedUser,
            'severity' => str_contains($action, 'reduce') ? 'warning' : 'info',
            'ip_address' => $request->ip(),
            'data' => [
                'target_user_name' => $updatedUser->name,
                'target_user_email' => $updatedUser->email,
                'action' => $action,
                'amount' => $amount,
                'note' => $note,
                'notification_id' => $notification->id,
            ],
        ]);

        return response()->json([
            'message' => 'Client balance updated successfully.',
            'user' => $this->transformUserListItem($updatedUser),
        ]);
    }

    public function packages(): JsonResponse
    {
        return response()->json([
            'packages' => InvestmentPackage::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storePackage(Request $request): JsonResponse
    {
        $validated = $this->validatePackage($request);

        $package = InvestmentPackage::create($validated);

        $this->auditLogs->record('investment_package_created', $request->user(), [
            'subject' => $package,
            'severity' => 'info',
            'ip_address' => $request->ip(),
            'data' => [
                'name' => $package->name,
                'minimum_amount' => $package->minimum_amount,
                'roi_percent' => $package->roi_percent,
            ],
        ]);

        return response()->json([
            'message' => 'Investment package created successfully.',
            'package' => $package,
        ], 201);
    }

    public function updatePackage(Request $request, InvestmentPackage $investmentPackage): JsonResponse
    {
        $validated = $this->validatePackage($request, $investmentPackage);

        $investmentPackage->update($validated);

        $this->auditLogs->record('investment_package_updated', $request->user(), [
            'subject' => $investmentPackage,
            'severity' => 'info',
            'ip_address' => $request->ip(),
            'data' => [
                'name' => $investmentPackage->name,
                'is_active' => $investmentPackage->is_active,
            ],
        ]);

        return response()->json([
            'message' => 'Investment package updated successfully.',
            'package' => $investmentPackage->fresh(),
        ]);
    }

    public function documents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'type' => ['nullable', 'string', 'max:80'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = UserDocument::query()->with(['user:id,name,email', 'reviewer:id,name,email']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $documents = $query
            ->latest()
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json([
            'documents' => $documents,
            'summary' => [
                'pending_count' => UserDocument::where('status', 'pending')->count(),
                'approved_count' => UserDocument::where('status', 'approved')->count(),
                'rejected_count' => UserDocument::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function reviewDocument(Request $request, UserDocument $userDocument): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $userDocument->update([
            'status' => $validated['status'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $userDocument->user_id,
            'title' => 'Document ' . $validated['status'],
            'severity' => $validated['status'] === 'approved' ? 'success' : 'warning',
            'type' => 'document_reviewed',
            'data' => [
                'message' => 'Your ' . $userDocument->type . ' document was ' . $validated['status'] . '.',
                'review_notes' => $validated['review_notes'] ?? null,
            ],
            'action_url' => '/user/dashboard',
        ]);

        $this->auditLogs->record('user_document_reviewed', $request->user(), [
            'subject' => $userDocument,
            'severity' => $validated['status'] === 'approved' ? 'info' : 'warning',
            'ip_address' => $request->ip(),
            'data' => [
                'document_type' => $userDocument->type,
                'status' => $validated['status'],
                'target_user_id' => $userDocument->user_id,
            ],
        ]);

        return response()->json([
            'message' => 'Document review saved successfully.',
            'document' => $userDocument->fresh(['user:id,name,email', 'reviewer:id,name,email']),
        ]);
    }

    private function transformUserListItem(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account_type' => $user->account_type,
            'account_status' => $user->account_status,
            'kyc_completed' => $user->kyc_completed,
            'suitability_completed' => $user->suitability_completed,
            'account_balance' => (float) $user->account_balance,
            'total_profit' => (float) $user->total_profit,
            'bonus_balance' => (float) $user->bonus_balance,
            'last_login_at' => $user->last_login_at,
            'last_login_ip' => $this->maskIpAddress($user->last_login_ip),
            'created_at' => $user->created_at,
            'active_sessions_count' => (int) ($user->active_sessions_count ?? 0),
            'total_requests_count' => (int) ($user->total_requests_count ?? 0),
            'documents_count' => (int) ($user->documents_count ?? 0),
        ];
    }

    private function validatePackage(Request $request, ?InvestmentPackage $package = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:140',
                Rule::unique('investment_packages', 'slug')->ignore($package?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'minimum_amount' => ['required', 'numeric', 'min:0'],
            'maximum_amount' => ['nullable', 'numeric', 'gte:minimum_amount'],
            'roi_percent' => ['required', 'numeric', 'min:0'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'bonus_percent' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $validated['slug'] = Str::slug($validated['slug'] ?: $validated['name']);
        $validated['bonus_percent'] = (float) ($validated['bonus_percent'] ?? 0);
        $validated['is_active'] = (bool) ($validated['is_active'] ?? true);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);

        return $validated;
    }

    private function maskIpAddress(?string $ipAddress): ?string
    {
        if (! $ipAddress) {
            return null;
        }

        if (str_contains($ipAddress, ':')) {
            $segments = explode(':', $ipAddress);
            $visibleSegments = array_slice($segments, 0, 3);

            return implode(':', $visibleSegments) . ':xxxx';
        }

        $segments = explode('.', $ipAddress);

        if (count($segments) !== 4) {
            return $ipAddress;
        }

        return $segments[0] . '.' . $segments[1] . '.*.*';
    }
}
