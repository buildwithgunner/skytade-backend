<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'minimum_amount',
        'maximum_amount',
        'roi_percent',
        'duration_days',
        'bonus_percent',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'minimum_amount' => 'float',
        'maximum_amount' => 'float',
        'roi_percent' => 'float',
        'bonus_percent' => 'float',
        'is_active' => 'boolean',
    ];
}
