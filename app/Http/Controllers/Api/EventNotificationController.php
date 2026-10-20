<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\EventCreatedNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventNotificationController extends Controller
{
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user()
            ->unreadNotifications()
            ->where('type', EventCreatedNotification::class)
            ->count();

        return response()->json(['success' => true, 'data' => ['event_count' => $count]]);
    }

    public function markEventsRead(Request $request): JsonResponse
    {
        $markedRead = $request->user()
            ->unreadNotifications()
            ->where('type', EventCreatedNotification::class)
            ->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => ['marked_read' => $markedRead]]);
    }
}
