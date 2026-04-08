<?php

namespace App\Http\Controllers\Api;

use App\Events\UserNotificationChanged;
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
            'message' => __('messages.notifications.retrieved'),
            'data' => [
                'notifications' => UserNotificationResource::collection($notifications),
            ],
        ]);
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        $this->ensureOwner($request, $notification);

        $notification->forceFill(['read_at' => now()])->save();
        event(new UserNotificationChanged($notification->user_id, 'updated', $notification->fresh()));

        return response()->json([
            'message' => __('messages.notifications.marked_read'),
            'data' => [
                'notification' => new UserNotificationResource($notification),
            ],
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $notifications = UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->get();

        $readAt = now();

        $notifications->each(function (UserNotification $notification) use ($readAt): void {
            $notification->forceFill(['read_at' => $readAt])->save();
            event(new UserNotificationChanged($notification->user_id, 'updated', $notification->fresh()));
        });

        return response()->json([
            'message' => __('messages.notifications.all_marked_read'),
        ]);
    }

    public function destroy(Request $request, UserNotification $notification): JsonResponse
    {
        $this->ensureOwner($request, $notification);

        event(new UserNotificationChanged($notification->user_id, 'deleted', notificationId: $notification->id));
        $notification->delete();

        return response()->json([
            'message' => __('messages.notifications.deleted'),
        ]);
    }

    private function ensureOwner(Request $request, UserNotification $notification): void
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
    }
}