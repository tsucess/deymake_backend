<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\VideoResource;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function global(Request $request): JsonResponse
    {
        $query = $request->string('q')->toString();

        return response()->json([
            'message' => 'Search results retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($this->videosQuery($query)->limit(10)->get()),
                'creators' => ProfileResource::collection($this->usersQuery($query)->limit(10)->get()),
                'categories' => CategoryResource::collection($this->categoriesQuery($query)->limit(10)->get()),
            ],
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $query = $request->string('q')->toString();

        return response()->json([
            'message' => 'Search suggestions retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($this->videosQuery($query)->limit(5)->get()),
                'creators' => ProfileResource::collection($this->usersQuery($query)->limit(5)->get()),
                'categories' => CategoryResource::collection($this->categoriesQuery($query)->limit(5)->get()),
            ],
        ]);
    }

    public function videos(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Video search results retrieved successfully.',
            'data' => [
                'videos' => VideoResource::collection($this->videosQuery($request->string('q')->toString())->get()),
            ],
        ]);
    }

    public function creators(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Creator search results retrieved successfully.',
            'data' => [
                'creators' => ProfileResource::collection($this->usersQuery($request->string('q')->toString())->get()),
            ],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Category search results retrieved successfully.',
            'data' => [
                'categories' => CategoryResource::collection($this->categoriesQuery($request->string('q')->toString())->get()),
            ],
        ]);
    }

    private function videosQuery(string $query)
    {
        return Video::query()
            ->with(['user', 'category', 'upload'])
            ->where('is_draft', false)
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($nested) use ($query): void {
                    $nested->where('title', 'like', '%'.$query.'%')
                        ->orWhere('caption', 'like', '%'.$query.'%')
                        ->orWhere('description', 'like', '%'.$query.'%');
                });
            })
            ->latest();
    }

    private function usersQuery(string $query)
    {
        return User::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('email', 'like', '%'.$query.'%');
            })
            ->orderBy('name');
    }

    private function categoriesQuery(string $query)
    {
        return Category::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%')
                    ->orWhere('slug', 'like', '%'.$query.'%');
            })
            ->orderBy('name');
    }
}