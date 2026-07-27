<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin video controller.
 *
 * Operator listing / take-down / visibility / feature toggles for
 * uploaded and live videos across the platform.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/Video.jsx.
 * Related: Video model, VideoResource, ContentModerationController.
 * See PROJECT_OVERVIEW.md §3.27 for the full data-flow map.
 */
class AdminVideoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $type = trim($request->string('type')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $videos = PaginatedJson::paginate(
            $this->videoQuery($request)
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('title', 'like', '%'.$query.'%')
                            ->orWhere('caption', 'like', '%'.$query.'%')
                            ->orWhere('description', 'like', '%'.$query.'%');
                    });
                })
                ->when($status === 'live', fn (Builder $b) => $b->where('is_live', true))
                ->when($status === 'published', fn (Builder $b) => $b->where('is_draft', false)->where('is_live', false))
                ->when($status === 'draft', fn (Builder $b) => $b->where('is_draft', true))
                ->when($status === 'removed', fn (Builder $b) => $b->where('moderation_status', 'removed'))
                ->when(in_array($type, ['video', 'live', 'photo'], true), fn (Builder $b) => $b->where('type', $type))
                ->when($sort === 'oldest', fn (Builder $b) => $b->oldest())
                ->when($sort === 'most_viewed', fn (Builder $b) => $b->orderByDesc('views_count')->latest())
                ->when(! in_array($sort, ['oldest', 'most_viewed'], true), fn (Builder $b) => $b->latest()),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.videos_retrieved'),
            'data' => [
                'videos' => PaginatedJson::items($request, $videos, VideoResource::class),
            ],
            'meta' => [
                'videos' => PaginatedJson::meta($videos),
                'summary' => [
                    'totalVideos' => Video::query()->count(),
                    'liveVideos' => Video::query()->where('is_live', true)->count(),
                    'publishedVideos' => Video::query()->where('is_draft', false)->count(),
                    'draftVideos' => Video::query()->where('is_draft', true)->count(),
                    'removedVideos' => Video::query()->where('moderation_status', 'removed')->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $video->load(['user' => fn ($query) => $query->withProfileAggregates($request->user()), 'upload']);

        return response()->json([
            'message' => __('messages.admin.video_retrieved'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function update(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'moderationStatus' => ['sometimes', Rule::in(['approved', 'restricted', 'removed', 'pending'])],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted', 'private'])],
            'moderationNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $video->forceFill(array_filter([
            'moderation_status' => $validated['moderationStatus'] ?? null,
            'visibility' => $validated['visibility'] ?? null,
            'moderation_notes' => $validated['moderationNotes'] ?? null,
            'moderated_by' => isset($validated['moderationStatus']) ? $request->user()->id : $video->moderated_by,
            'moderated_at' => isset($validated['moderationStatus']) ? now() : $video->moderated_at,
        ], fn ($value) => $value !== null))->save();

        $video->load(['user' => fn ($query) => $query->withProfileAggregates($request->user()), 'upload']);

        return response()->json([
            'message' => __('messages.admin.video_updated'),
            'data' => [
                'video' => new VideoResource($video),
            ],
        ]);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $video->forceFill([
            'moderation_status' => 'removed',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ])->save();

        $video->delete();

        return response()->json([
            'message' => __('messages.admin.video_deleted'),
        ]);
    }

    private function videoQuery(Request $request): Builder
    {
        return Video::query()->with([
            'user' => fn ($query) => $query->withProfileAggregates($request->user()),
            'upload',
        ]);
    }
}
