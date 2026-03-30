<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\VideoResource;
use App\Models\Category;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewer = auth('sanctum')->user() ?? $request->user();

        $trending = Video::query()
            ->withApiResourceData($viewer)
            ->where('is_draft', false)
            ->orderByDesc('views_count')
            ->latest()
            ->limit(12)
            ->get();

        $liveStreams = Video::query()
            ->withApiResourceData($viewer)
            ->where('is_draft', false)
            ->where('is_live', true)
            ->latest()
            ->limit(12)
            ->get();

        return response()->json([
            'message' => 'Homepage data retrieved successfully.',
            'data' => [
                'trending' => VideoResource::collection($trending),
                'categories' => CategoryResource::collection(Category::query()->orderBy('name')->get()),
                'liveStreams' => VideoResource::collection($liveStreams),
            ],
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'message' => 'Categories retrieved successfully.',
            'data' => [
                'categories' => CategoryResource::collection(Category::query()->orderBy('name')->get()),
            ],
        ]);
    }
}