<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContentModerationCaseResource;
use App\Http\Resources\VideoReportResource;
use App\Http\Resources\VideoResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Video;
use App\Services\ContentModerationService;
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
        $moderationStatus = trim($request->string('moderationStatus')->toString());
        $live = mb_strtolower(trim($request->string('live')->toString()));
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $videos = PaginatedJson::paginate(
            $this->videoQuery($request)
                ->when($query !== '', function (Builder $builder) use ($query): void {
                    $builder->where(function (Builder $inner) use ($query): void {
                        $inner->where('title', 'like', '%'.$query.'%')
                            ->orWhere('caption', 'like', '%'.$query.'%')
                            ->orWhere('description', 'like', '%'.$query.'%')
                            ->orWhere('public_id', 'like', '%'.$query.'%')
                            ->orWhereHas('user', function (Builder $userQuery) use ($query): void {
                                $userQuery->where('name', 'like', '%'.$query.'%')
                                    ->orWhere('username', 'like', '%'.$query.'%');
                            });

                        if (ctype_digit($query)) {
                            $inner->orWhere('id', (int) $query);
                        }
                    });
                })
                ->when($status === 'live', fn (Builder $b) => $b->where('is_live', true))
                ->when($status === 'published', fn (Builder $b) => $b->where('is_draft', false)->where('is_live', false))
                ->when($status === 'draft', fn (Builder $b) => $b->where('is_draft', true))
                ->when($status === 'removed', fn (Builder $b) => $b->where('moderation_status', 'removed'))
                ->when(in_array($type, ['video', 'live', 'photo'], true), fn (Builder $b) => $b->where('type', $type))
                ->when(in_array($moderationStatus, ['visible', 'restricted', 'removed', 'pending_review'], true), fn (Builder $b) => $b->where('moderation_status', $moderationStatus))
                ->when(in_array($live, ['1', 'true', 'yes'], true), fn (Builder $b) => $b->where('is_live', true))
                ->when(in_array($live, ['0', 'false', 'no'], true), fn (Builder $b) => $b->where('is_live', false))
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

        $video->load([
            'user' => fn ($query) => $query->withProfileAggregates($request->user()),
            'upload',
            'moderationCase' => fn ($query) => $query->with('reviewer'),
        ]);

        return response()->json([
            'message' => __('messages.admin.video_retrieved'),
            'data' => [
                'video' => new VideoResource($video),
                'moderationCase' => $video->moderationCase
                    ? new ContentModerationCaseResource($video->moderationCase)
                    : null,
                'reports' => $this->reportsSummary($video),
            ],
        ]);
    }

    public function update(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'moderationStatus' => ['sometimes', Rule::in(['visible', 'restricted', 'removed', 'pending_review'])],
            'visibility' => ['sometimes', 'string', 'max:32'],
            'moderationNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin = $request->user();
        $previousStatus = $video->moderation_status;
        $nextStatus = $validated['moderationStatus'] ?? $previousStatus;

        $video->forceFill(array_filter([
            'moderation_status' => $validated['moderationStatus'] ?? null,
            'visibility' => $validated['visibility'] ?? null,
            'moderation_notes' => $validated['moderationNotes'] ?? null,
            'moderated_by' => isset($validated['moderationStatus']) ? $admin->id : $video->moderated_by,
            'moderated_at' => isset($validated['moderationStatus']) ? now() : $video->moderated_at,
        ], fn ($value) => $value !== null))->save();

        if (isset($validated['moderationStatus']) && $nextStatus !== $previousStatus) {
            $this->recordVideoAudit($request, $admin, $video, $this->moderationAction($previousStatus, $nextStatus), [
                'from' => $previousStatus,
                'to' => $nextStatus,
                'notes' => $validated['moderationNotes'] ?? null,
            ]);
        }

        $video->load([
            'user' => fn ($query) => $query->withProfileAggregates($admin),
            'upload',
            'moderationCase' => fn ($query) => $query->with('reviewer'),
        ]);

        return response()->json([
            'message' => __('messages.admin.video_updated'),
            'data' => [
                'video' => new VideoResource($video),
                'moderationCase' => $video->moderationCase
                    ? new ContentModerationCaseResource($video->moderationCase)
                    : null,
            ],
        ]);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $admin = $request->user();
        $previousStatus = $video->moderation_status;

        $video->forceFill([
            'moderation_status' => 'removed',
            'moderated_by' => $admin->id,
            'moderated_at' => now(),
        ])->save();

        $this->recordVideoAudit($request, $admin, $video, 'admin.video_deleted', [
            'from' => $previousStatus,
            'videoId' => $video->id,
            'ownerId' => $video->user_id,
        ]);

        $video->delete();

        return response()->json([
            'message' => __('messages.admin.video_deleted'),
        ]);
    }

    public function reports(Request $request, Video $video): JsonResponse
    {
        SupportedLocales::apply($request);

        $reports = PaginatedJson::paginate(
            $video->reports()->with(['user', 'reviewer', 'video.user'])->latest(),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.video_reports_retrieved'),
            'data' => [
                'reports' => PaginatedJson::items($request, $reports, VideoReportResource::class),
            ],
            'meta' => [
                'reports' => PaginatedJson::meta($reports),
            ],
        ]);
    }

    public function rescan(Request $request, Video $video, ContentModerationService $moderationService): JsonResponse
    {
        SupportedLocales::apply($request);

        $moderationCase = $moderationService->scanVideo($video);

        $this->recordVideoAudit($request, $request->user(), $video, 'admin.video_rescanned', [
            'status' => $moderationCase->status,
            'aiRiskLevel' => $moderationCase->ai_risk_level,
        ]);

        return response()->json([
            'message' => __('messages.moderation.video_rescanned'),
            'data' => [
                'moderationCase' => new ContentModerationCaseResource($moderationCase),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportsSummary(Video $video): array
    {
        return [
            'total' => $video->reports()->count(),
            'pending' => $video->reports()->where('status', 'pending')->count(),
            'recent' => VideoReportResource::collection(
                $video->reports()->with(['user', 'reviewer', 'video.user'])->latest()->limit(5)->get()
            ),
        ];
    }

    private function moderationAction(?string $previous, string $next): string
    {
        return match ($next) {
            'restricted' => 'admin.video_restricted',
            'removed' => 'admin.video_removed',
            'pending_review' => 'admin.video_flagged',
            default => in_array($previous, ['removed', 'restricted'], true) ? 'admin.video_restored' : 'admin.video_approved',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordVideoAudit(Request $request, User $admin, Video $video, string $action, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => $action,
            'auditable_type' => $video->getMorphClass(),
            'auditable_id' => $video->id,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
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
