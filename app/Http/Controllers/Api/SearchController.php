<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\VideoResource;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Support\PaginatedJson;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function global(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);
        $viewer = auth('sanctum')->user() ?? $request->user();
        $videos = $this->paginatedResource($request, $query === ''
            ? PaginatedJson::empty($request, 10, 25)
            : PaginatedJson::paginate($this->videosQuery($query, $viewer), $request, 10, 25), VideoResource::class);
        $creators = $this->paginatedResource($request, $query === ''
            ? PaginatedJson::empty($request, 10, 25)
            : PaginatedJson::paginate($this->usersQuery($query), $request, 10, 25), ProfileResource::class);
        $categories = $this->paginatedResource($request, $query === ''
            ? PaginatedJson::empty($request, 10, 25)
            : PaginatedJson::paginate($this->categoriesQuery($query), $request, 10, 25), CategoryResource::class);

        return response()->json([
            'message' => 'Search results retrieved successfully.',
            'data' => [
                'videos' => $videos['items'],
                'creators' => $creators['items'],
                'categories' => $categories['items'],
            ],
            'meta' => [
                'videos' => $videos['meta'],
                'creators' => $creators['meta'],
                'categories' => $categories['meta'],
            ],
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);
        $viewer = auth('sanctum')->user() ?? $request->user();
        $videos = $this->paginatedResource($request, $query === ''
            ? PaginatedJson::empty($request, 5, 10)
            : PaginatedJson::paginate($this->videosQuery($query, $viewer), $request, 5, 10), VideoResource::class);
        $creators = $this->paginatedResource($request, $query === ''
            ? PaginatedJson::empty($request, 5, 10)
            : PaginatedJson::paginate($this->usersQuery($query), $request, 5, 10), ProfileResource::class);
        $categories = $this->paginatedResource($request, $query === ''
            ? PaginatedJson::empty($request, 5, 10)
            : PaginatedJson::paginate($this->categoriesQuery($query), $request, 5, 10), CategoryResource::class);

        return response()->json([
            'message' => 'Search suggestions retrieved successfully.',
            'data' => [
                'videos' => $videos['items'],
                'creators' => $creators['items'],
                'categories' => $categories['items'],
            ],
            'meta' => [
                'videos' => $videos['meta'],
                'creators' => $creators['meta'],
                'categories' => $categories['meta'],
            ],
        ]);
    }

    public function videos(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);
        $viewer = auth('sanctum')->user() ?? $request->user();

        $videos = $query === ''
            ? PaginatedJson::empty($request, 12, 25)
            : PaginatedJson::paginate($this->videosQuery($query, $viewer), $request, 12, 25);

        return response()->json([
            'message' => 'Video search results retrieved successfully.',
            'data' => [
                'videos' => PaginatedJson::items($request, $videos, VideoResource::class),
            ],
            'meta' => [
                'videos' => PaginatedJson::meta($videos),
            ],
        ]);
    }

    public function creators(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);

        return $this->singleCollectionResponse(
            $request,
            'Creator search results retrieved successfully.',
            'creators',
            $query === ''
                ? PaginatedJson::empty($request, 12, 25)
                : PaginatedJson::paginate($this->usersQuery($query), $request, 12, 25),
            ProfileResource::class,
        );
    }

    public function categories(Request $request): JsonResponse
    {
        $query = $this->normalizedQuery($request);

        return $this->singleCollectionResponse(
            $request,
            'Category search results retrieved successfully.',
            'categories',
            $query === ''
                ? PaginatedJson::empty($request, 12, 25)
                : PaginatedJson::paginate($this->categoriesQuery($query), $request, 12, 25),
            CategoryResource::class,
        );
    }

    private function singleCollectionResponse(Request $request, string $message, string $key, LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => [
                $key => PaginatedJson::items($request, $paginator, $resourceClass),
            ],
            'meta' => [
                $key => PaginatedJson::meta($paginator),
            ],
        ]);
    }

    private function paginatedResource(Request $request, LengthAwarePaginator $paginator, string $resourceClass): array
    {
        return [
            'items' => PaginatedJson::items($request, $paginator, $resourceClass),
            'meta' => PaginatedJson::meta($paginator),
        ];
    }

    private function videosQuery(string $query, ?User $viewer = null)
    {
        return Video::query()
            ->withApiResourceData($viewer)
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
            ->withProfileAggregates()
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

    private function normalizedQuery(Request $request): string
    {
        return preg_replace('/\s+/', ' ', trim($request->string('q')->toString())) ?? '';
    }
}