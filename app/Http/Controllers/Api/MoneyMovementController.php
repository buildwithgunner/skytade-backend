<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MoneyMovement;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MoneyMovementController extends Controller
{
    public function __construct(
        private LedgerService $ledgerService,
        private NotificationService $notifications,
        private AuditLogService $auditLogs,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 25), 100));

        $movements = MoneyMovement::query()
            ->with(['user:id,name,email', 'approver:id,name,email', 'entries.account', 'reconciliation'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'movements' => $movements,
            'summary' => [
                'pending_count' => MoneyMovement::where('status', 'pending')->count(),
                'reconciliation_exceptions' => MoneyMovement::where('reconciliation_status', 'exception')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'type' => ['required', Rule::in(['deposit', 'withdrawal'])],
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = User::findOrFail($validated['user_id']);

        $movement = $this->ledgerService->createMovement([
            'user_id' => $user->id,
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'currency' => strtoupper($validated['currency'] ?? 'USD'),
            'external_reference' => $validated['external_reference'] ?? null,
            'description' => $validated['description'] ?? null,
            'review_notes' => $validated['review_notes'] ?? null,
        ], $request->user(), $request->ip());

        $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $user->id,
            'title' => ucfirst($movement->type) . ' submitted for operations review',
            'severity' => 'critical',
            'type' => 'money_movement_submitted',
            'data' => [
                'message' => 'Your ' . $movement->type . ' instruction is pending operational review.',
                'amount' => $movement->amount,
                'currency' => $movement->currency,
            ],
            'action_url' => '/platform',
        ]);

        return response()->json([
            'message' => 'Money movement created successfully.',
            'movement' => $movement->load(['user', 'entries.account', 'reconciliation']),
        ], 201);
    }

    public function approve(Request $request, MoneyMovement $moneyMovement): JsonResponse
    {
        $movement = $this->ledgerService->approveAndPost($moneyMovement, $request->user(), $request->ip());

        $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $movement->user_id,
            'title' => ucfirst($movement->type) . ' approved',
            'severity' => 'critical',
            'type' => 'money_movement_approved',
            'data' => [
                'message' => 'Your ' . $movement->type . ' instruction has been approved and posted.',
                'amount' => $movement->amount,
                'currency' => $movement->currency,
                'movement_id' => $movement->id,
            ],
            'action_url' => '/platform',
        ]);

        return response()->json([
            'message' => 'Money movement approved and posted successfully.',
            'movement' => $movement,
        ]);
    }

    public function reject(Request $request, MoneyMovement $moneyMovement): JsonResponse
    {
        if ($moneyMovement->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending transactions can be rejected.',
            ], 422);
        }

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $moneyMovement->update([
            'status' => 'rejected',
            'review_notes' => $validated['review_notes'] ?? null,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $this->notifications->createNotification([
            'audience' => 'user',
            'recipient_user_id' => $moneyMovement->user_id,
            'title' => ucfirst($moneyMovement->type) . ' rejected',
            'severity' => 'warning',
            'type' => 'money_movement_rejected',
            'data' => [
                'message' => 'Your ' . $moneyMovement->type . ' instruction was rejected.',
                'amount' => $moneyMovement->amount,
                'currency' => $moneyMovement->currency,
                'review_notes' => $validated['review_notes'] ?? null,
            ],
            'action_url' => '/user/dashboard',
        ]);

        $this->auditLogs->record('money_movement_rejected', $request->user(), [
            'subject' => $moneyMovement,
            'severity' => 'warning',
            'ip_address' => $request->ip(),
            'data' => [
                'type' => $moneyMovement->type,
                'amount' => $moneyMovement->amount,
                'review_notes' => $validated['review_notes'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'Money movement rejected successfully.',
            'movement' => $moneyMovement->fresh(['user', 'approver', 'entries.account', 'reconciliation']),
        ]);
    }

    public function reconcile(Request $request, MoneyMovement $moneyMovement): JsonResponse
    {
        $validated = $request->validate([
            'external_amount' => ['required', 'numeric', 'min:0'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $reconciliation = $this->ledgerService->reconcile(
            movement: $moneyMovement,
            actor: $request->user(),
            externalAmount: (float) $validated['external_amount'],
            externalReference: $validated['external_reference'] ?? null,
            notes: $validated['notes'] ?? null,
            ipAddress: $request->ip(),
        );

        if ($reconciliation->status === 'exception') {
            $this->notifications->createNotification([
                'audience' => 'admin',
                'title' => 'Transaction reconciliation exception',
                'severity' => 'critical',
                'type' => 'reconciliation_exception',
                'data' => [
                    'message' => 'A money movement failed reconciliation and requires immediate review.',
                    'movement_id' => $moneyMovement->id,
                    'difference_amount' => $reconciliation->difference_amount,
                ],
                'action_url' => '/admin-dashboard',
            ]);
        }

        return response()->json([
            'message' => 'Money movement reconciled successfully.',
            'reconciliation' => $reconciliation,
            'movement' => $moneyMovement->fresh(['user', 'approver', 'entries.account', 'reconciliation']),
        ]);
    }
}
