<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'money_movement_id',
        'ledger_account_id',
        'user_id',
        'entry_type',
        'amount',
        'currency',
        'reference_type',
        'reference_id',
        'description',
        'meta',
        'effective_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'meta' => 'array',
        'effective_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function moneyMovement(): BelongsTo
    {
        return $this->belongsTo(MoneyMovement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
