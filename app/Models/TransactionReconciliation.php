<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'money_movement_id',
        'performed_by',
        'internal_amount',
        'external_amount',
        'difference_amount',
        'external_reference',
        'status',
        'notes',
        'reconciled_at',
    ];

    protected $casts = [
        'internal_amount' => 'float',
        'external_amount' => 'float',
        'difference_amount' => 'float',
        'reconciled_at' => 'datetime',
    ];

    public function moneyMovement(): BelongsTo
    {
        return $this->belongsTo(MoneyMovement::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'performed_by');
    }
}
