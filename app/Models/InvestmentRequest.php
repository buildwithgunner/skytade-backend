<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'asset_type',
        'message',
        'funding_source',
        'frequency',
        'attested',
        'status',
        'requires_dual_approval',
        'approval_state',
        'approval_count',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'risk_score',
        'risk_flags',
    ];

    protected $casts = [
        'amount'   => 'float',
        'attested' => 'boolean',
        'requires_dual_approval' => 'boolean',
        'reviewed_at' => 'datetime',
        'risk_flags' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(InvestmentRequestApproval::class);
    }

    public function moneyMovements(): HasMany
    {
        return $this->hasMany(MoneyMovement::class);
    }
}
