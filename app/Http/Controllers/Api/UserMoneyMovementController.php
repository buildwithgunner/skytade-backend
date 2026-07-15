<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MoneyMovement;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserMoneyMovementController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Return the authenticated user's own money movements.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = max(1, min((int) $request->integer('limit', 50), 100));

        $movements = MoneyMovement::query()
            ->where('user_id', $user->id)
            ->with(['approver:id,name'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($m) => [
                'id'           => $m->id,
                'type'         => $m->type,
                'amount'       => (float) $m->amount,
                'currency'     => $m->currency,
                'status'       => $m->status,
                'description'  => $m->description,
                'review_notes' => $m->review_notes,
                'created_at'   => $m->created_at,
                'approved_at'  => $m->approved_at,
                'approver'     => $m->approver?->only(['id', 'name']),
            ]);

        return response()->json([
            'movements' => $movements,
            'summary'   => [
                'total_deposited'  => (float) MoneyMovement::where('user_id', $user->id)->where('type', 'deposit')->where('status', 'approved')->sum('amount'),
                'total_withdrawn'  => (float) MoneyMovement::where('user_id', $user->id)->where('type', 'withdrawal')->where('status', 'approved')->sum('amount'),
                'pending_count'    => MoneyMovement::where('user_id', $user->id)->where('status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * Client submits a deposit or withdrawal request.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'type'               => ['required', Rule::in(['deposit', 'withdrawal'])],
            'amount'             => ['required', 'numeric', 'min:10'],
            'currency'           => ['nullable', 'string', 'size:3'],
            'payment_method'     => ['nullable', 'string', 'max:100'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:500'],
        ]);

        $movement = MoneyMovement::create([
            'user_id'            => $user->id,
            'type'               => $validated['type'],
            'amount'             => $validated['amount'],
            'currency'           => strtoupper($validated['currency'] ?? 'USD'),
            'status'             => 'pending',
            'external_reference' => $validated['external_reference'] ?? null,
            'description'        => $validated['payment_method']
                ? ($validated['description'] ?? '') . ' | Method: ' . $validated['payment_method']
                : ($validated['description'] ?? null),
        ]);

        // Notify the user
        $this->notifications->createNotification([
            'audience'          => 'user',
            'recipient_user_id' => $user->id,
            'title'             => ucfirst($movement->type) . ' request submitted',
            'severity'          => 'info',
            'type'              => 'money_movement_submitted',
            'data'              => [
                'message'  => 'Your ' . $movement->type . ' request of $' . number_format($movement->amount, 2) . ' is pending broker review.',
                'amount'   => $movement->amount,
                'currency' => $movement->currency,
            ],
            'action_url'        => '/platform',
        ]);

        // Notify admin
        $this->notifications->createNotification([
            'audience' => 'admin',
            'title'    => 'New ' . $movement->type . ' request from ' . $user->name,
            'severity' => 'info',
            'type'     => 'new_money_movement',
            'data'     => [
                'message'  => $user->name . ' has submitted a ' . $movement->type . ' of $' . number_format($movement->amount, 2),
                'amount'   => $movement->amount,
                'user_name' => $user->name,
                'user_email' => $user->email,
            ],
            'action_url' => '/admin/money-movements',
        ]);

        return response()->json([
            'message'  => ucfirst($movement->type) . ' request submitted successfully. Pending broker approval.',
            'movement' => $movement,
        ], 201);
    }
}
