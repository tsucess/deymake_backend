<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ExploreRisingCreatorResource;
use App\Http\Resources\VideoResource;
use App\Models\Category;
use App\Models\Video;
use App\Services\ExploreHashtagService;
use App\Services\ExploreRisingCreatorService;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ExploreController extends Controller
{
    protected const TOP_VIDEO_LIMIT = 12;

    protected const TRENDING_HASHTAG_LIMIT = 10;

    protected const RISING_CREATOR_LIMIT = 6;

    protected const ENGAGEMENT_WINDOW_DAYS = 7;

    protected const GUEST_CACHE_TTL_SECONDS = 90;

    public function index(
        Request $request,
        ExploreHashtagService $hashtagService,
        ExploreRisingCreatorService $risingCreatorService,
    ): JsonResponse {
        SupportedLocales::apply($request);

        $viewer = auth('sanctum')->user() ?? $request->user();
        $category = $this->resolveCategory($request->query('category'));
        $categoryId = $category?->id;
        $locale = app()->getLocale();

        $build = function () use ($viewer, $category, $categoryId, $hashtagService, $risingCreatorService): array {
            $hero = Video::query()
                ->withApiResourceData($viewer)
                ->discoverable()
                ->when($categoryId, fn (Builder $q) => $q->where('category_id', $categoryId))
                ->where('created_at', '>=', Carbon::now()->subDays(self::ENGAGEMENT_WINDOW_DAYS))
                ->orderByDesc('views_count')
                ->latest()
                ->first();

            $trendingHashtags = $hashtagService->trending($categoryId, self::TRENDING_HASHTAG_LIMIT, self::ENGAGEMENT_WINDOW_DAYS);
            $risingCreators = $risingCreatorService->rising($viewer, $categoryId, self::RISING_CREATOR_LIMIT, self::ENGAGEMENT_WINDOW_DAYS);
            $topVideos = $this->topVideosQuery($viewer, $categoryId)->limit(self::TOP_VIDEO_LIMIT)->get();

            return [
                'message' => __('messages.explore.retrieved'),
                'data' => [
                    'categories' => CategoryResource::collection(Category::query()->orderBy('name')->get())->toArray(request()),
                    'activeCategory' => $category ? (new CategoryResource($category))->toArray(request()) : null,
                    'hero' => $hero ? (new VideoResource($hero))->toArray(request()) : null,
                    'trendingHashtags' => $trendingHashtags,
                    'risingCreators' => ExploreRisingCreatorResource::collection($risingCreators)->toArray(request()),
                    'topVideos' => VideoResource::collection($topVideos)->toArray(request()),
                ],
            ];
        };

        if ($viewer) {
            return response()->json($build());
        }

        $cacheKey = 'explore:index:'.$locale.':'.($categoryId ?? 'all');
        $payload = Cache::remember($cacheKey, self::GUEST_CACHE_TTL_SECONDS, $build);

        return response()->json($payload);
    }

    public function videos(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = auth('sanctum')->user() ?? $request->user();
        $category = $this->resolveCategory($request->query('category'));
        $locale = app()->getLocale();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) $request->query('per_page', 12);

        $build = function () use ($viewer, $category, $request): array {
            $query = $this->topVideosQuery($viewer, $category?->id);
            $videos = PaginatedJson::paginate($query, $request, 12, 50);

            return [
                'message' => __('messages.explore.videos_retrieved'),
                'data' => [
                    'videos' => PaginatedJson::items($request, $videos, VideoResource::class),
                    'activeCategory' => $category ? (new CategoryResource($category))->toArray(request()) : null,
                ],
                'meta' => [
                    'videos' => PaginatedJson::meta($videos),
                ],
            ];
        };

        if ($viewer) {
            return response()->json($build());
        }

        $cacheKey = 'explore:videos:'.$locale.':'.($category?->id ?? 'all').':p'.$page.':pp'.$perPage;
        $payload = Cache::remember($cacheKey, self::GUEST_CACHE_TTL_SECONDS, $build);

        return response()->json($payload);
    }

    protected function topVideosQuery($viewer, ?int $categoryId): Builder
    {
        $since = Carbon::now()->subDays(self::ENGAGEMENT_WINDOW_DAYS);

        return Video::query()
            ->withApiResourceData($viewer)
            ->discoverable()
            ->when($categoryId, fn (Builder $q) => $q->where('category_id', $categoryId))
            ->withCount([
                'likes as recent_likes_count' => fn ($q) => $q->where('video_interactions.created_at', '>=', $since),
                'reposts as recent_reposts_count' => fn ($q) => $q->where('video_interactions.created_at', '>=', $since),
                'comments as recent_comments_count' => fn ($q) => $q
                    ->where('moderation_status', 'visible')
                    ->where('created_at', '>=', $since),
            ])
            ->orderByRaw('(recent_likes_count + recent_reposts_count + recent_comments_count) DESC')
            ->orderByDesc('views_count')
            ->latest();
    }

    protected function resolveCategory(?string $key): ?Category
    {
        if ($key === null || $key === '' || strtolower($key) === 'trending') {
            return null;
        }

        return Category::query()
            ->where('slug', $key)
            ->orWhere('name', $key)
            ->first();
    }
}
