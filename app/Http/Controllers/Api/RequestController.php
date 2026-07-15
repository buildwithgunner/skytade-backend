<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestmentRequest;
use App\Models\InvestmentRequestApproval;
use App\Services\AuditLogService;
use App\Services\LedgerService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RequestController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private AuditLogService $auditLogs,
        private LedgerService $ledgerService,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Administrator accounts cannot submit retail investment requests.',
            ], 422);
        }

        if (! $user->kyc_completed) {
            return response()->json([
                'message' => 'KYC verification is required before making an investment request.',
                'requires_kyc' => true,
            ], 422);
        }

        if (! $user->suitability_completed) {
            return response()->json([
                'message' => 'Suitability profile is required before making an investment request.',
                'requires_suitability' => true,
            ], 422);
        }

        $validated = $request->validate([
            'amount'         => ['required', 'numeric', 'min:100'],
            'asset_type'     => ['required', 'string', Rule::in([
                'Equities',
                'Forex',
                'Cryptocurrencies',
                'Commodities',
                'Bonds',
                'Real Estate',
            ])],
            'message'        => ['nullable', 'string', 'max:1000'],
            'funding_source' => ['required', 'string', Rule::in([
                'Linked Bank Account',
                'Debit Card',
                'Wire Transfer',
                'Fiat Wallet',
            ])],
            'frequency'      => ['required', 'string', 'in:one-time,daily,weekly,monthly'],
            'attested'       => ['required', 'boolean', 'accepted'],
        ], [
            'attested.accepted' => 'You must acknowledge all risk disclosures before proceeding.',
        ]);

        [$riskScore, $riskFlags] = $this->buildRiskProfile($user, $validated);
        $requiresDualApproval = $this->requiresDualApproval($validated['amount'], $riskScore);

        $investmentRequest = InvestmentRequest::create([
            'user_id'        => $user->id,
            'amount'         => $validated['amount'],
            'asset_type'     => $validated['asset_type'],
            'message'        => $validated['message'] ?? null,
            'funding_source' => $validated['funding_source'],
            'frequency'      => $validated['frequency'],
            'attested'       => true,
            'status'         => 'pending',
            'requires_dual_approval' => $requiresDualApproval,
            'approval_state' => 'pending',
            'risk_score'     => $riskScore,
            'risk_flags'     => $riskFlags,
        ]);

        $this->notifications->createNotification([
            'audience' => 'admin',
            'title' => 'Investment request submitted',
            'severity' => $requiresDualApproval ? 'critical' : ($riskScore >= 60 ? 'warning' : 'info'),
            'type' => 'new_request',
            'data' => [
                'message' => $requiresDualApproval
                    ? 'A high-value or high-risk investment request requires dual approval.'
                    : 'A new investment request is ready for review.',
                'user_name'      => $user->name,
                'user_email'     => $user->email,
                'amount'         => $investmentRequest->amount,
                'asset_type'     => $investmentRequest->asset_type,
                'funding_source' => $investmentRequest->funding_source,
                'frequency'      => $investmentRequest->frequency,
                'request_id'     => $investmentRequest->id,
                'risk_tolerance' => $user->risk_tolerance_scenario,
                'goals'          => $user->investment_goals,
                'risk_score'     => $riskScore,
                'risk_flags'     => $riskFlags,
                'requires_dual_approval' => $requiresDualApproval,
            ],
            'action_url' => '/admin-dashboard',
        ]);

        $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $user->id,
            'title' => 'Investment request received',
            'severity' => 'success',
            'type' => 'request_submitted',
            'data' => [
                'amount' => $investmentRequest->amount,
                'asset_type' => $investmentRequest->asset_type,
                'status' => $investmentRequest->status,
            ],
            'action_url' => '/platform',
        ]);

        $this->auditLogs->record('investment_request_submitted', $user, [
            'subject' => $investmentRequest,
            'severity' => $requiresDualApproval ? 'critical' : 'info',
            'ip_address' => $request->ip(),
            'data' => [
                'amount' => $investmentRequest->amount,
                'asset_type' => $investmentRequest->asset_type,
                'risk_score' => $investmentRequest->risk_score,
                'requires_dual_approval' => $investmentRequest->requires_dual_approval,
            ],
        ]);

        return response()->json([
            'message' => 'Investment request submitted successfully.',
            'request' => $investmentRequest->load(['user', 'approvals.admin:id,name,email']),
        ], 201);
    }

    public function myRequests(Request $request): JsonResponse
    {
        $requests = InvestmentRequest::where('user_id', $request->user()->id)
            ->with(['reviewer:id,name,email', 'approvals.admin:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'requests' => $requests,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'awaiting_secondary_approval', 'approved', 'rejected'])],
            'risk' => ['nullable', Rule::in(['high', 'all'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = InvestmentRequest::with([
                'user',
                'reviewer:id,name,email',
                'approvals.admin:id,name,email',
            ]);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (($validated['risk'] ?? null) === 'high') {
            $query->where('risk_score', '>=', 60);
        }

        $requests = $query
            ->orderByDesc('created_at')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json([
            'requests' => $requests,
            'summary' => [
                'pending_count' => InvestmentRequest::where('status', 'pending')->count(),
                'awaiting_secondary_approval_count' => InvestmentRequest::where('status', 'awaiting_secondary_approval')->count(),
                'high_risk_pending_count' => InvestmentRequest::where('status', 'pending')->where('risk_score', '>=', 60)->count(),
            ],
        ]);
    }

    public function updateStatus(Request $request, InvestmentRequest $investmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $investmentRequest->load(['user', 'approvals.admin:id,name,email']);
        $admin = $request->user();

        if (in_array($investmentRequest->status, ['approved', 'rejected'], true)) {
            return response()->json([
                'message' => 'Only requests that are still in review can be updated from the admin console.',
            ], 422);
        }

        if ($investmentRequest->approvals->contains('admin_id', $admin->id)) {
            return response()->json([
                'message' => 'A second approval must come from a different administrator.',
            ], 422);
        }

        $notes = $validated['review_notes'] ?? null;

        DB::transaction(function () use ($investmentRequest, $validated, $admin, $notes) {
            InvestmentRequestApproval::create([
                'investment_request_id' => $investmentRequest->id,
                'admin_id' => $admin->id,
                'decision' => $validated['status'],
                'sequence' => $investmentRequest->approval_count + 1,
                'notes' => $notes,
            ]);

            if ($validated['status'] === 'rejected') {
                $investmentRequest->update([
                    'status' => 'rejected',
                    'approval_state' => 'rejected',
                    'approval_count' => $investmentRequest->approval_count + 1,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                    'review_notes' => $notes,
                ]);

                return;
            }

            if ($investmentRequest->requires_dual_approval && $investmentRequest->approval_count === 0) {
                $investmentRequest->update([
                    'status' => 'awaiting_secondary_approval',
                    'approval_state' => 'awaiting_secondary_approval',
                    'approval_count' => 1,
                    'review_notes' => $notes,
                ]);

                return;
            }

            $investmentRequest->update([
                'status' => 'approved',
                'approval_state' => 'approved',
                'approval_count' => $investmentRequest->approval_count + 1,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
            ]);
        });

        $investmentRequest = $investmentRequest->fresh(['user', 'reviewer:id,name,email', 'approvals.admin:id,name,email']);

        if ($investmentRequest->status === 'awaiting_secondary_approval') {
            $this->notifications->createNotification([
                'audience' => 'admin',
                'title' => 'Secondary approval required',
                'severity' => 'critical',
                'type' => 'secondary_approval_required',
                'data' => [
                    'message' => 'A separate administrator must complete the final approval for this investment request.',
                    'request_id' => $investmentRequest->id,
                    'amount' => $investmentRequest->amount,
                    'asset_type' => $investmentRequest->asset_type,
                    'review_notes' => $notes,
                ],
                'action_url' => '/admin-dashboard',
            ]);

            $this->auditLogs->record('investment_request_primary_approved', $admin, [
                'subject' => $investmentRequest,
                'severity' => 'critical',
                'ip_address' => $request->ip(),
                'data' => [
                    'request_id' => $investmentRequest->id,
                    'amount' => $investmentRequest->amount,
                    'risk_score' => $investmentRequest->risk_score,
                ],
            ]);

            return response()->json([
                'message' => 'Primary approval recorded. Secondary approval is now required.',
                'request' => $investmentRequest,
            ]);
        }

        $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $investmentRequest->user_id,
            'title' => $investmentRequest->status === 'approved' ? 'Investment request approved' : 'Investment request rejected',
            'severity' => $investmentRequest->status === 'approved' ? 'success' : 'warning',
            'type' => 'request_status_updated',
            'data' => [
                'request_id' => $investmentRequest->id,
                'status' => $investmentRequest->status,
                'review_notes' => $notes,
                'amount' => $investmentRequest->amount,
                'asset_type' => $investmentRequest->asset_type,
            ],
            'action_url' => '/platform',
        ]);

        if ($investmentRequest->status === 'approved' && ! $investmentRequest->moneyMovements()->exists()) {
            $movement = $this->ledgerService->createMovement([
                'user_id' => $investmentRequest->user_id,
                'investment_request_id' => $investmentRequest->id,
                'type' => 'deposit',
                'amount' => $investmentRequest->amount,
                'currency' => 'USD',
                'status' => 'approved',
                'description' => 'Capital funding for approved investment request #' . $investmentRequest->id,
            ], $admin, $request->ip());

            $this->ledgerService->approveAndPost($movement, $admin, $request->ip());
        }

        $this->auditLogs->record('investment_request_finalized', $admin, [
            'subject' => $investmentRequest,
            'severity' => $investmentRequest->status === 'approved' ? 'info' : 'warning',
            'ip_address' => $request->ip(),
            'data' => [
                'request_id' => $investmentRequest->id,
                'status' => $investmentRequest->status,
                'user_name' => $investmentRequest->user?->name,
                'user_email' => $investmentRequest->user?->email,
            ],
        ]);

        return response()->json([
            'message' => 'Request status updated successfully.',
            'request' => $investmentRequest->load(['user', 'reviewer:id,name,email', 'approvals.admin:id,name,email']),
        ]);
    }

    private function buildRiskProfile($user, array $validated): array
    {
        $score = 0;
        $flags = [];

        if ((float) $validated['amount'] >= 10000) {
            $score += 25;
            $flags[] = 'high_amount';
        }

        if ($validated['asset_type'] === 'Cryptocurrencies') {
            $score += 20;
            $flags[] = 'volatile_asset_class';
        }

        if ($validated['frequency'] !== 'one-time') {
            $score += 15;
            $flags[] = 'recurring_instruction';
        }

        if ($user->knowledge_level === 'Novice' && in_array($validated['asset_type'], ['Forex', 'Cryptocurrencies'], true)) {
            $score += 25;
            $flags[] = 'novice_high_risk_asset';
        }

        if ($user->source_of_funds === 'Other') {
            $score += 15;
            $flags[] = 'manual_source_of_funds_review';
        }

        return [min($score, 100), $flags];
    }

    private function requiresDualApproval(float|string $amount, int $riskScore): bool
    {
        return (float) $amount >= 10000 || $riskScore >= 60;
    }
}
