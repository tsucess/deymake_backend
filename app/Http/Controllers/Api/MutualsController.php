<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mutuals feed controller.
 *
 * Returns the "people you and a target both follow" feed used by the
 * Mutuals page.
 *
 * Routes: GET /mutuals/feed.
 * Frontend consumers: pages/Mutual.jsx via api.getMutualsFeed().
 * See PROJECT_OVERVIEW.md §3.10 for the full data-flow map.
 */
class MutualsController extends Controller
{
    public function feed(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = $request->user();

        $mutualIds = $viewer->mutuals()->pluck('users.id');

        $videos = collect();

        if ($mutualIds->isNotEmpty()) {
            $videos = Video::query()
                ->withApiResourceData($viewer)
                ->discoverable()
                ->whereIn('user_id', $mutualIds)
                ->latest()
                ->limit(20)
                ->get();
        }

        return response()->json([
            'message' => __('messages.mutuals.feed_retrieved'),
            'data' => [
                'videos' => VideoResource::collection($videos),
                'source' => 'mutuals',
                'hasMutuals' => $mutualIds->isNotEmpty(),
            ],
        ]);
    }
}
