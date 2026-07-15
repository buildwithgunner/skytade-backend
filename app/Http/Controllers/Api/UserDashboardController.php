<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestmentPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->load([
            'documents' => fn ($query) => $query->latest()->limit(6)->with('reviewer:id,name,email'),
            'moneyMovements' => fn ($query) => $query->latest()->limit(8)->with('approver:id,name,email'),
            'investmentRequests' => fn ($query) => $query->latest()->limit(8)->with('reviewer:id,name,email'),
            'notifications' => fn ($query) => $query->latest()->limit(8),
        ]);

        return response()->json([
            'welcome_message' => 'Welcome to Skytrade Limited make a deposit to get started',
            'summary' => [
                'balance' => (float) $user->account_balance,
                'total_profit' => (float) $user->total_profit,
                'bonus' => (float) $user->bonus_balance,
                'approved_requests' => $user->investmentRequests->where('status', 'approved')->count(),
                'pending_documents' => $user->documents->where('status', 'pending')->count(),
            ],
            'compliance' => [
                'kyc_completed' => (bool) $user->kyc_completed,
                'suitability_completed' => (bool) $user->suitability_completed,
            ],
            'documents' => $user->documents,
            'transactions' => $user->moneyMovements,
            'requests' => $user->investmentRequests,
            'notifications' => $user->notifications,
            'packages' => InvestmentPackage::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
