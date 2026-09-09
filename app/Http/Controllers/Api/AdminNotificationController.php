<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserNotificationResource;
use App\Models\UserNotification;
use App\Support\PaginatedJson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $type = trim($request->string('type')->toString());

        $notifications = PaginatedJson::paginate(
            UserNotification::query()->with('user')
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('title', 'like', '%'.$query.'%')
                            ->orWhere('body', 'like', '%'.$query.'%')
                            ->orWhere('type', 'like', '%'.$query.'%')
                            ->orWhereHas('user', function (Builder $userQuery) use ($query): void {
                                $userQuery->where('name', 'like', '%'.$query.'%')
                                    ->orWhere('username', 'like', '%'.$query.'%')
                                    ->orWhere('email', 'like', '%'.$query.'%');
                            });
                    });
                })
                ->when($status !== '', function (Builder $builder) use ($status): void {
                    if ($status === 'unread') {
                        $builder->whereNull('read_at');
                        return;
                    }

                    $builder->whereNotNull('read_at');
                })
                ->when($type !== '', fn (Builder $builder) => $builder->where('type', $type))
                ->when($request->filled('from'), fn (Builder $builder) => $builder->whereDate('created_at', '>=', $request->date('from')))
                ->when($request->filled('to'), fn (Builder $builder) => $builder->whereDate('created_at', '<=', $request->date('to')))
                ->latest(),
            $request,
            15,
            100,
        );

        return response()->json([
            'message' => __('messages.notifications.retrieved'),
            'data' => [
                'notifications' => PaginatedJson::items($request, $notifications, UserNotificationResource::class),
            ],
            'meta' => [
                'notifications' => PaginatedJson::meta($notifications),
                'summary' => [
                    'total' => UserNotification::query()->count(),
                    'unread' => UserNotification::query()->whereNull('read_at')->count(),
                    'read' => UserNotification::query()->whereNotNull('read_at')->count(),
                ],
            ],
        ]);
    }
}
