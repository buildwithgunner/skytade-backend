<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MoneyMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'investment_request_id',
        'type',
        'amount',
        'currency',
        'status',
        'reconciliation_status',
        'external_reference',
        'description',
        'approved_by',
        'approved_at',
        'posted_at',
        'reconciled_at',
        'review_notes',
    ];

    protected $casts = [
        'amount' => 'float',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(InvestmentRequest::class, 'investment_request_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function reconciliation(): HasOne
    {
        return $this->hasOne(TransactionReconciliation::class);
    }
}
