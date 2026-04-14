<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\VideoResource;
use App\Models\Category;
use App\Models\Video;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = auth('sanctum')->user() ?? $request->user();

        $trending = Video::query()
            ->withApiResourceData($viewer)
            ->discoverable()
            ->orderByDesc('views_count')
            ->latest()
            ->limit(12)
            ->get();

        $liveStreams = Video::query()
            ->withApiResourceData($viewer)
            ->discoverable()
            ->where('is_live', true)
            ->orderByDesc('live_started_at')
            ->latest()
            ->limit(12)
            ->get();

        return response()->json([
            'message' => __('messages.home.retrieved'),
            'data' => [
                'trending' => VideoResource::collection($trending),
                'categories' => CategoryResource::collection(Category::query()->orderBy('name')->get()),
                'liveStreams' => VideoResource::collection($liveStreams),
            ],
        ]);
    }

    public function categories(): JsonResponse
    {
        SupportedLocales::apply(request());

        return response()->json([
            'message' => __('messages.categories.retrieved'),
            'data' => [
                'categories' => CategoryResource::collection(Category::query()->orderBy('name')->get()),
            ],
        ]);
    }
}