<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Notifications retrieved successfully.',
            'data' => [
                'notifications' => UserNotificationResource::collection($notifications),
            ],
        ]);
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        $this->ensureOwner($request, $notification);

        $notification->forceFill(['read_at' => now()])->save();

        return response()->json([
            'message' => 'Notification marked as read successfully.',
            'data' => [
                'notification' => new UserNotificationResource($notification),
            ],
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read successfully.',
        ]);
    }

    public function destroy(Request $request, UserNotification $notification): JsonResponse
    {
        $this->ensureOwner($request, $notification);

        $notification->delete();

        return response()->json([
            'message' => 'Notification deleted successfully.',
        ]);
    }

    private function ensureOwner(Request $request, UserNotification $notification): void
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
    }
}