<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 25), 100));

        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'logs' => $logs,
            'summary' => [
                'critical_count' => AuditLog::where('severity', 'critical')->count(),
                'recent_count' => $logs->count(),
            ],
        ]);
    }
}
