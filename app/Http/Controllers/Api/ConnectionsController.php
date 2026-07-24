<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connections feed controller.
 *
 * Returns the "people you follow" feed used by the Connections page.
 *
 * Routes: GET /connections/feed.
 * Frontend consumers: pages/Connections.jsx via api.getConnectionsFeed().
 * See PROJECT_OVERVIEW.md §3.10 for the full data-flow map.
 */
class ConnectionsController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = $request->user();

        $subscribedCreatorIds = $viewer->subscribedCreators()->pluck('users.id');

        $query = Video::query()
            ->withApiResourceData($viewer)
            ->discoverable()
            ->where('user_id', '!=', $viewer->id);

        if ($subscribedCreatorIds->isNotEmpty()) {
            $query->whereIn('user_id', $subscribedCreatorIds)
                ->latest();
        } else {
            $query->orderByDesc('views_count')->latest();
        }

        $videos = $query->limit(20)->get();

        return response()->json([
            'message' => __('messages.connections.feed_retrieved'),
            'data' => [
                'videos' => VideoResource::collection($videos),
                'source' => $subscribedCreatorIds->isNotEmpty() ? 'subscriptions' : 'trending',
            ],
        ]);
    }
}
