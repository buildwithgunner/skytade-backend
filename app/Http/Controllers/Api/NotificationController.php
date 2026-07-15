<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function adminIndex(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 25), 100));
        $notifications = Notification::where('audience', 'admin')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'summary' => [
                'unread_count' => Notification::where('audience', 'admin')->whereNull('read_at')->count(),
                'high_severity_count' => Notification::where('audience', 'admin')
                    ->whereIn('severity', ['warning', 'critical'])
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function userIndex(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 25), 100));
        $notifications = Notification::where('recipient_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'notifications' => $notifications,
            'summary' => [
                'unread_count' => Notification::where('recipient_user_id', $request->user()->id)->whereNull('read_at')->count(),
                'high_severity_count' => Notification::where('recipient_user_id', $request->user()->id)
                    ->whereIn('severity', ['warning', 'critical'])
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Notification::query();

        if ($user->isAdmin() && $request->routeIs('admin.*')) {
            $query->where('audience', 'admin');
        } else {
            $query->where('recipient_user_id', $user->id);
        }

        $query->whereNull('read_at')->update([
            'read_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notifications marked as read successfully.',
        ]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $request->user();
        $canReadAdminNotification = $user->isAdmin() && $notification->audience === 'admin';
        $canReadOwnNotification = (int) $notification->recipient_user_id === (int) $user->id;

        if (! $canReadAdminNotification && ! $canReadOwnNotification) {
            return response()->json([
                'message' => 'You do not have permission to modify this notification.',
            ], 403);
        }

        $notification->update([
            'read_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notification marked as read successfully.',
            'notification' => $notification,
        ]);
    }
}
