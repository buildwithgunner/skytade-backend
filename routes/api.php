<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\MoneyMovementController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\SiteContentController;
use App\Http\Controllers\Api\UserDashboardController;
use App\Http\Controllers\Api\UserDocumentController;
use App\Http\Controllers\Api\UserMoneyMovementController;
use Illuminate\Support\Facades\Route;

Route::get('/site-content', [SiteContentController::class, 'show']);

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
Route::post('/auth/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:5,1');
Route::post('/auth/admin/verify-mfa', [AuthController::class, 'verifyAdminMfa'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {

    Route::post('/requests', [RequestController::class, 'store']);
    Route::get('/requests', [RequestController::class, 'myRequests']);

    Route::get('/user/compliance-status', [ComplianceController::class, 'getProfileStatus']);
    Route::post('/user/kyc', [ComplianceController::class, 'submitKYC']);
    Route::post('/user/suitability', [ComplianceController::class, 'submitSuitability']);
    Route::get('/notifications', [NotificationController::class, 'userIndex']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('user.notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::get('/user/dashboard', [UserDashboardController::class, 'show']);
    Route::get('/user/documents', [UserDocumentController::class, 'index']);
    Route::post('/user/documents', [UserDocumentController::class, 'store']);
    Route::get('/user/transactions', [UserMoneyMovementController::class, 'index']);
    Route::post('/user/transactions', [UserMoneyMovementController::class, 'store']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/overview', [AdminController::class, 'overview'])
            ->middleware('admin.permission:view_admin_dashboard')
            ->name('admin.overview');
        Route::get('/users', [AdminController::class, 'users'])
            ->middleware('admin.permission:view_user_profiles')
            ->name('admin.users.index');
        Route::get('/users/{user}', [AdminController::class, 'showUser'])
            ->middleware('admin.permission:view_user_profiles')
            ->name('admin.users.show');
        Route::put('/users/{user}/status', [AdminController::class, 'updateUserStatus'])
            ->middleware('admin.permission:manage_user_statuses')
            ->name('admin.users.status');
        Route::post('/users/{user}/finance', [AdminController::class, 'updateUserFinance'])
            ->middleware('admin.permission:manage_user_finances')
            ->name('admin.users.finance');
        Route::get('/requests', [RequestController::class, 'index'])
            ->middleware('admin.permission:view_investment_requests')
            ->name('admin.requests.index');
        Route::put('/requests/{investmentRequest}/status', [RequestController::class, 'updateStatus'])
            ->middleware('admin.permission:manage_request_statuses')
            ->name('admin.requests.status');
        Route::get('/notifications', [NotificationController::class, 'adminIndex'])
            ->middleware('admin.permission:view_notifications')
            ->name('admin.notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->middleware('admin.permission:manage_notifications')
            ->name('admin.notifications.read-all');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->middleware('admin.permission:manage_notifications')
            ->name('admin.notifications.read');
        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('admin.permission:view_audit_logs')
            ->name('admin.audit-logs.index');
        Route::get('/money-movements', [MoneyMovementController::class, 'index'])
            ->middleware('admin.permission:view_money_movements')
            ->name('admin.money-movements.index');
        Route::post('/money-movements', [MoneyMovementController::class, 'store'])
            ->middleware('admin.permission:manage_money_movements')
            ->name('admin.money-movements.store');
        Route::put('/money-movements/{moneyMovement}/approve', [MoneyMovementController::class, 'approve'])
            ->middleware('admin.permission:manage_money_movements')
            ->name('admin.money-movements.approve');
        Route::put('/money-movements/{moneyMovement}/reject', [MoneyMovementController::class, 'reject'])
            ->middleware('admin.permission:manage_money_movements')
            ->name('admin.money-movements.reject');
        Route::post('/money-movements/{moneyMovement}/reconcile', [MoneyMovementController::class, 'reconcile'])
            ->middleware('admin.permission:reconcile_transactions')
            ->name('admin.money-movements.reconcile');
        Route::get('/packages', [AdminController::class, 'packages'])
            ->middleware('admin.permission:manage_investment_packages')
            ->name('admin.packages.index');
        Route::post('/packages', [AdminController::class, 'storePackage'])
            ->middleware('admin.permission:manage_investment_packages')
            ->name('admin.packages.store');
        Route::put('/packages/{investmentPackage}', [AdminController::class, 'updatePackage'])
            ->middleware('admin.permission:manage_investment_packages')
            ->name('admin.packages.update');
        Route::get('/documents', [AdminController::class, 'documents'])
            ->middleware('admin.permission:review_user_documents')
            ->name('admin.documents.index');
        Route::put('/documents/{userDocument}/review', [AdminController::class, 'reviewDocument'])
            ->middleware('admin.permission:review_user_documents')
            ->name('admin.documents.review');
    });
});
