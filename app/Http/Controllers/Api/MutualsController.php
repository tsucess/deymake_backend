<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
