<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'currency',
        'allows_negative',
    ];

    protected $casts = [
        'allows_negative' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
