<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentRequestApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_request_id',
        'admin_id',
        'decision',
        'sequence',
        'notes',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(InvestmentRequest::class, 'investment_request_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
