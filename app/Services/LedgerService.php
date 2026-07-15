<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MoneyMovement;
use App\Models\TransactionReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    public function __construct(private AuditLogService $auditLogs)
    {
    }

    public function createMovement(array $attributes, User|Admin|null $actor = null, ?string $ipAddress = null): MoneyMovement
    {
        $movement = MoneyMovement::create($attributes);

        $this->auditLogs->record('money_movement_created', $actor, [
            'subject' => $movement,
            'severity' => 'info',
            'ip_address' => $ipAddress,
            'data' => [
                'type' => $movement->type,
                'amount' => $movement->amount,
                'currency' => $movement->currency,
                'external_reference' => $movement->external_reference,
            ],
        ]);

        return $movement;
    }

    public function approveAndPost(MoneyMovement $movement, Admin $approver, ?string $ipAddress = null): MoneyMovement
    {
        if (in_array($movement->status, ['posted', 'reconciled'], true)) {
            throw ValidationException::withMessages([
                'movement' => ['This money movement has already been posted to the ledger.'],
            ]);
        }

        [$debitAccount, $creditAccount] = $this->accountsForMovementType($movement->type);

        return DB::transaction(function () use ($movement, $approver, $debitAccount, $creditAccount, $ipAddress) {
            $this->ensureDefaultAccounts();

            $movement->forceFill([
                'status' => 'posted',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'posted_at' => now(),
            ])->save();

            $entryMeta = [
                'movement_type' => $movement->type,
                'external_reference' => $movement->external_reference,
            ];

            LedgerEntry::create([
                'money_movement_id' => $movement->id,
                'ledger_account_id' => LedgerAccount::where('code', $debitAccount)->value('id'),
                'user_id' => $movement->user_id,
                'entry_type' => 'debit',
                'amount' => $movement->amount,
                'currency' => $movement->currency,
                'reference_type' => MoneyMovement::class,
                'reference_id' => $movement->id,
                'description' => $movement->description,
                'meta' => $entryMeta,
                'effective_at' => now(),
            ]);

            if ($movement->user) {
                $balanceDelta = $movement->type === 'withdrawal'
                    ? -1 * $movement->amount
                    : $movement->amount;

                $movement->user->increment('account_balance', $balanceDelta);
            }

            LedgerEntry::create([
                'money_movement_id' => $movement->id,
                'ledger_account_id' => LedgerAccount::where('code', $creditAccount)->value('id'),
                'user_id' => $movement->user_id,
                'entry_type' => 'credit',
                'amount' => $movement->amount,
                'currency' => $movement->currency,
                'reference_type' => MoneyMovement::class,
                'reference_id' => $movement->id,
                'description' => $movement->description,
                'meta' => $entryMeta,
                'effective_at' => now(),
            ]);

            $this->auditLogs->record('money_movement_posted', $approver, [
                'subject' => $movement,
                'severity' => 'info',
                'ip_address' => $ipAddress,
                'data' => [
                    'type' => $movement->type,
                    'amount' => $movement->amount,
                    'currency' => $movement->currency,
                ],
            ]);

            return $movement->fresh(['entries.account', 'reconciliation', 'user', 'approver']);
        });
    }

    public function reconcile(
        MoneyMovement $movement,
        Admin $actor,
        float $externalAmount,
        ?string $externalReference = null,
        ?string $notes = null,
        ?string $ipAddress = null,
    ): TransactionReconciliation {
        if (! in_array($movement->status, ['posted', 'reconciled'], true)) {
            throw ValidationException::withMessages([
                'movement' => ['Only posted money movements can be reconciled.'],
            ]);
        }

        $difference = round($externalAmount - $movement->amount, 2);
        $status = abs($difference) < 0.01 ? 'matched' : 'exception';

        $reconciliation = TransactionReconciliation::updateOrCreate(
            ['money_movement_id' => $movement->id],
            [
                'performed_by' => $actor->id,
                'internal_amount' => $movement->amount,
                'external_amount' => $externalAmount,
                'difference_amount' => $difference,
                'external_reference' => $externalReference ?: $movement->external_reference,
                'status' => $status,
                'notes' => $notes,
                'reconciled_at' => now(),
            ],
        );

        $movement->forceFill([
            'reconciliation_status' => $status,
            'status' => $status === 'matched' ? 'reconciled' : 'posted',
            'external_reference' => $externalReference ?: $movement->external_reference,
            'reconciled_at' => now(),
        ])->save();

        $this->auditLogs->record('money_movement_reconciled', $actor, [
            'subject' => $movement,
            'severity' => $status === 'matched' ? 'info' : 'critical',
            'ip_address' => $ipAddress,
            'data' => [
                'internal_amount' => $movement->amount,
                'external_amount' => $externalAmount,
                'difference_amount' => $difference,
                'status' => $status,
            ],
        ]);

        return $reconciliation->fresh(['moneyMovement.user', 'performedBy']);
    }

    public function ensureDefaultAccounts(): void
    {
        $accounts = [
            [
                'code' => 'PLATFORM_SETTLEMENT_CASH',
                'name' => 'Platform Settlement Cash',
                'type' => 'asset',
                'allows_negative' => false,
            ],
            [
                'code' => 'INVESTOR_FUNDS_PAYABLE',
                'name' => 'Investor Funds Payable',
                'type' => 'liability',
                'allows_negative' => false,
            ],
            [
                'code' => 'INVESTOR_WITHDRAWALS_PAYABLE',
                'name' => 'Investor Withdrawals Payable',
                'type' => 'liability',
                'allows_negative' => false,
            ],
        ];

        foreach ($accounts as $account) {
            LedgerAccount::query()->updateOrCreate(
                ['code' => $account['code']],
                [
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'currency' => 'USD',
                    'allows_negative' => $account['allows_negative'],
                ],
            );
        }
    }

    protected function accountsForMovementType(string $type): array
    {
        return match ($type) {
            'deposit' => ['PLATFORM_SETTLEMENT_CASH', 'INVESTOR_FUNDS_PAYABLE'],
            'withdrawal' => ['INVESTOR_WITHDRAWALS_PAYABLE', 'PLATFORM_SETTLEMENT_CASH'],
            default => throw ValidationException::withMessages([
                'type' => ['Unsupported money movement type.'],
            ]),
        };
    }
}
