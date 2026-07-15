<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'account_type',
        'account_status',
        'last_login_at',
        'last_login_ip',
        // KYC
        'phone',
        'notification_channels',
        'push_channel_key',
        'address',
        'zip_code',
        'dob',
        'government_id',
        // Financial Status
        'annual_income',
        'employment_status',
        'source_of_funds',
        // Investment Experience
        'knowledge_level',
        'experience_assets',
        // Risk & Goals
        'risk_tolerance_scenario',
        'investment_goals',
        // Compliance Flags
        'kyc_completed',
        'suitability_completed',
        'account_balance',
        'total_profit',
        'bonus_balance',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'phone' => 'encrypted',
        'notification_channels' => 'array',
        'push_channel_key' => 'encrypted',
        'address' => 'encrypted',
        'zip_code' => 'encrypted',
        'dob' => 'encrypted',
        'government_id' => 'encrypted',
        'annual_income' => 'encrypted',
        'employment_status' => 'encrypted',
        'source_of_funds' => 'encrypted',
        'experience_assets' => 'array',
        'kyc_completed' => 'boolean',
        'suitability_completed' => 'boolean',
        'account_balance' => 'float',
        'total_profit' => 'float',
        'bonus_balance' => 'float',
    ];

    public function isAdmin(): bool
    {
        return false;
    }

    public function investmentRequests(): HasMany
    {
        return $this->hasMany(InvestmentRequest::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_user_id');
    }

    public function loginChallenges(): HasMany
    {
        return $this->hasMany(LoginChallenge::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    public function moneyMovements(): HasMany
    {
        return $this->hasMany(MoneyMovement::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(UserDocument::class)->latest();
    }

    public function approvedRequests(): HasMany
    {
        return $this->hasMany(InvestmentRequest::class)->where('status', 'approved');
    }
}
