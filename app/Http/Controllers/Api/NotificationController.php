<?php

namespace App\Http\Controllers\Api;

use App\Events\UserNotificationChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use App\Models\CoinPurchase;
use App\Models\GiftTransaction;
use App\Models\Payment;
use App\Models\WalletTransaction;
use App\Support\PaginatedJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications controller.
 *
 * In-app notification list and read/delete actions for the authenticated
 * user.
 *
 * Routes: list / mark-read / mark-all-read / delete under the
 * notifications prefix in routes/api.php.
 * Frontend consumers: pages/Notifications.jsx, Layout/NotificationButton.jsx,
 * Layout/RealtimeNotificationPopup.jsx (uses services/realtime.js polling).
 * Related: UserNotification model, UserNotificationResource.
 * See PROJECT_OVERVIEW.md §3.22 for the full data-flow map.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = trim($request->string('type')->toString());
        $read = $request->query('read');
        $notifications = PaginatedJson::paginate(UserNotification::query()
            ->where('user_id', $request->user()->id)
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($read === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($read === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->latest(), $request, 30, 100);

        return response()->json([
            'message' => __('messages.notifications.retrieved'),
            'data' => [
                'notifications' => PaginatedJson::items($request, $notifications, UserNotificationResource::class),
            ],
            'meta' => ['notifications' => PaginatedJson::meta($notifications)],
        ]);
    }

    public function activity(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $items = collect()
            ->concat(UserNotification::query()->where('user_id', $userId)->latest()->limit(50)->get()->map(fn (UserNotification $item) => [
                'id' => 'notification-'.$item->id, 'type' => 'notification', 'title' => $item->title, 'body' => $item->body, 'status' => $item->read_at ? 'read' : 'unread', 'createdAt' => $item->created_at?->toISOString(),
            ]))
            ->concat(CoinPurchase::query()->where('user_id', $userId)->latest()->limit(50)->get()->map(fn (CoinPurchase $item) => [
                'id' => 'coin-purchase-'.$item->id, 'type' => 'coin_purchase', 'title' => 'Coin purchase', 'body' => $item->coins.' coins', 'status' => $item->status, 'createdAt' => $item->purchased_at?->toISOString() ?? $item->created_at?->toISOString(),
            ]))
            ->concat(GiftTransaction::query()->where(fn ($query) => $query->where('sender_id', $userId)->orWhere('recipient_id', $userId))->latest('sent_at')->limit(50)->get()->map(fn (GiftTransaction $item) => [
                'id' => 'gift-'.$item->id, 'type' => 'gift', 'title' => $item->sender_id === $userId ? 'Gift sent' : 'Gift received', 'body' => $item->gift_name.' x'.$item->quantity, 'status' => $item->status, 'createdAt' => $item->sent_at?->toISOString(),
            ]))
            ->concat(WalletTransaction::query()->where('user_id', $userId)->latest('occurred_at')->limit(50)->get()->map(fn (WalletTransaction $item) => [
                'id' => 'wallet-'.$item->id, 'type' => 'wallet', 'title' => $item->description ?: str_replace('_', ' ', $item->type), 'body' => strtoupper($item->direction).' '.$item->amount.' '.$item->currency, 'status' => $item->status, 'createdAt' => $item->occurred_at?->toISOString() ?? $item->created_at?->toISOString(),
            ]))
            ->sortByDesc('createdAt')->values();

        return response()->json(['data' => ['activity' => $items->forPage(max(1, $request->integer('page', 1)), min(50, max(1, $request->integer('per_page', 20))))->values()], 'meta' => ['total' => $items->count()]]);
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

    public function clear(Request $request): JsonResponse
    {
        UserNotification::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => __('messages.notifications.deleted')]);
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