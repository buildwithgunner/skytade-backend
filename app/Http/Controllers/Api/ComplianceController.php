<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComplianceController extends Controller
{
    /**
     * Return current user's compliance status and profile data.
     */
    public function getProfileStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'kyc_completed' => $user->kyc_completed,
            'suitability_completed' => $user->suitability_completed,
        ]);
    }

    /**
     * Save KYC identity and address details.
     */
    public function submitKYC(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone'         => ['required', 'string', 'max:30'],
            'address'       => ['required', 'string', 'max:500'],
            'zip_code'      => ['required', 'string', 'max:20'],
            'dob'           => ['required', 'date', 'before:-18 years'],
            'government_id' => ['required', 'string', 'max:100'],
        ], [
            'dob.before' => 'You must be at least 18 years old to register.',
        ]);

        $user = $request->user();
        $user->update(array_merge($validated, ['kyc_completed' => true]));

        Notification::create([
            'audience' => 'admin',
            'title' => 'Investor KYC completed',
            'severity' => 'info',
            'type' => 'compliance_update',
            'data' => [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'stage' => 'kyc',
            ],
            'action_url' => '/admin-dashboard',
        ]);

        Notification::create([
            'audience' => 'user',
            'recipient_user_id' => $user->id,
            'title' => 'Identity verification saved',
            'severity' => 'success',
            'type' => 'compliance_update',
            'data' => [
                'message' => 'Your identity profile is on file. Complete the suitability questionnaire to unlock investment requests.',
                'stage' => 'kyc',
            ],
            'action_url' => '/platform',
        ]);

        return response()->json([
            'message' => 'KYC information saved successfully.',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * Save investor suitability and risk profile.
     */
    public function submitSuitability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'annual_income'          => ['required', 'string', Rule::in([
                'Under $25,000', '$25,000–$50,000', '$50,000–$100,000',
                '$100,000–$250,000', 'Over $250,000',
            ])],
            'employment_status'      => ['required', 'string', Rule::in([
                'Employed (Full-time)', 'Employed (Part-time)', 'Self-Employed',
                'Business Owner', 'Retired', 'Student', 'Unemployed',
            ])],
            'source_of_funds'        => ['required', 'string', Rule::in([
                'Salary', 'Business Revenue', 'Investments', 'Inheritance', 'Savings', 'Other',
            ])],
            'knowledge_level'        => ['required', 'string', Rule::in(['Novice', 'Intermediate', 'Professional'])],
            'experience_assets'      => ['required', 'array', 'min:1'],
            'experience_assets.*'    => ['string', Rule::in([
                'Stocks & Equities', 'Bonds & Fixed Income', 'Forex', 'Cryptocurrencies',
                'Real Estate', 'Commodities', 'ETFs & Mutual Funds', 'None',
            ])],
            'risk_tolerance_scenario' => ['required', 'string', Rule::in([
                'sell_all', 'sell_some', 'hold', 'buy_more',
            ])],
            'investment_goals'       => ['required', 'string', Rule::in([
                'Capital Preservation', 'Passive Income', 'Long-term Wealth Accumulation',
                'Short-term Gains', 'Diversification',
            ])],
        ]);

        $user = $request->user();
        $user->update(array_merge($validated, ['suitability_completed' => true]));

        Notification::create([
            'audience' => 'admin',
            'title' => 'Investor suitability completed',
            'severity' => 'info',
            'type' => 'compliance_update',
            'data' => [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'stage' => 'suitability',
                'knowledge_level' => $validated['knowledge_level'],
                'investment_goals' => $validated['investment_goals'],
            ],
            'action_url' => '/admin-dashboard',
        ]);

        Notification::create([
            'audience' => 'user',
            'recipient_user_id' => $user->id,
            'title' => 'Suitability profile approved for use',
            'severity' => 'success',
            'type' => 'compliance_update',
            'data' => [
                'message' => 'Your compliance profile is complete. You can now submit investment requests for review.',
                'stage' => 'suitability',
            ],
            'action_url' => '/platform',
        ]);

        return response()->json([
            'message' => 'Suitability profile saved successfully.',
            'user' => $user->fresh(),
        ]);
    }
}
