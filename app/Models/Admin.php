<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const DEFAULT_ADMIN_PERMISSIONS = [
        'view_admin_dashboard',
        'view_security_center',
        'view_investment_requests',
        'review_investment_requests',
        'manage_request_statuses',
        'view_user_profiles',
        'manage_user_statuses',
        'view_notifications',
        'manage_notifications',
        'view_audit_logs',
        'view_money_movements',
        'manage_money_movements',
        'reconcile_transactions',
        'manage_user_finances',
        'manage_investment_packages',
        'review_user_documents',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'staff_id',
        'account_status',
        'admin_permissions',
        'last_login_at',
        'last_login_ip',
        'last_mfa_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'admin_permissions' => 'array',
        'last_login_at' => 'datetime',
        'last_mfa_at' => 'datetime',
    ];

    public function hasAdminPermission(string $permission): bool
    {
        return in_array($permission, $this->resolvedAdminPermissions(), true);
    }

    public function resolvedAdminPermissions(): array
    {
        return $this->admin_permissions ?: self::DEFAULT_ADMIN_PERMISSIONS;
    }

    public function isAdmin(): bool
    {
        return true;
    }

    public function loginChallenges(): HasMany
    {
        return $this->hasMany(LoginChallenge::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'admin_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_admin_id');
    }

    public function investmentApprovals(): HasMany
    {
        return $this->hasMany(InvestmentRequestApproval::class, 'admin_id');
    }
}
