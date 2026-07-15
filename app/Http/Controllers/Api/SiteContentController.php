<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SiteContentController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'brand' => [
                'name' => 'Skytrade',
                'heroTitle' => 'Skytrade Capital',
                'eyebrow' => 'Investment broker',
                'summary' => 'Skytrade helps investors access global markets with professional brokerage tools, live analytics, portfolio reporting, and support for disciplined long-term wealth building.',
            ],
            'homeFeatures' => [
                ['title' => 'Brokerage access', 'text' => 'Trade major global markets from one account with clear pricing and account-level reporting.'],
                ['title' => 'Portfolio oversight', 'text' => 'Track holdings, deposits, account movements, and investment performance with a clean dashboard.'],
                ['title' => 'Investor support', 'text' => 'Get broker support for onboarding, funding, account security, and platform workflows.'],
            ],
            'stats' => [
                ['value' => '$734.5M', 'suffix' => '', 'label' => 'Client assets monitored'],
                ['value' => '53', 'suffix' => '', 'label' => 'Global markets covered'],
                ['value' => '4.8', 'suffix' => 's', 'label' => 'Average execution speed'],
            ],
            'performanceFeatures' => [
                'Transparent spread monitoring',
                'Multi-market execution reports',
                'Risk-weighted portfolio snapshots',
            ],
            'accountSummary' => [
                ['value' => '+18.20%', 'label' => 'YTD return', 'tone' => 'positive'],
                ['value' => '+$28,269', 'label' => 'Net gain', 'tone' => 'positive'],
                ['value' => '$124,367', 'label' => 'Portfolio value', 'tone' => 'neutral'],
                ['value' => '-$313', 'label' => 'Daily movement', 'tone' => 'negative'],
                ['value' => '21', 'label' => 'Active holdings', 'tone' => 'neutral'],
                ['value' => '63.92%', 'label' => 'Diversified', 'tone' => 'neutral'],
            ],
            'testimonials' => [
                ['name' => 'Misha Grant', 'role' => 'Private investor', 'text' => 'Skytrade made it easier to compare markets, follow my portfolio, and move quickly when opportunities opened up. The platform feels focused and professional.'],
                ['name' => 'Nazar Buch', 'role' => 'Business owner', 'text' => 'I wanted a broker that could support long-term investing and active trading in one place. Skytrade gives me clear reporting, useful market insight, and responsive support.'],
                ['name' => 'Amara Wells', 'role' => 'Portfolio client', 'text' => 'The account view is clear, the onboarding process is simple, and the broker support team understands both investing and active trading workflows.'],
            ],
        ]);
    }
}
