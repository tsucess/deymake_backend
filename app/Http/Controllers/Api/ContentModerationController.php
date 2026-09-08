<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContentModerationCaseRequest;
use App\Http\Resources\ContentModerationCaseResource;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\ContentModerationCase;
use App\Models\User;
use App\Models\Video;
use App\Services\ContentModerationService;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Content moderation controller.
 *
 * Admin-facing moderation queues and case actions. Reports arrive via
 * VideoInteractionController@report and CommentController; the scoring
 * engine lives in ContentModerationService.
 *
 * Routes: admin moderation queue + case actions in routes/api.php.
 * Frontend consumers: Admin/Pages/Reports.jsx,
 * Admin/Components/ContentModeration/*.
 * Related: ContentModerationCase model, ContentModerationService.
 * See PROJECT_OVERVIEW.md §3.25 for the full data-flow map.
 */
class ContentModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $contentType = trim($request->string('contentType')->toString());
        $riskLevel = trim($request->string('riskLevel')->toString());
        $from = $request->date('from');
        $to = $request->date('to');

        $cases = PaginatedJson::paginate(
            ContentModerationCase::query()
                ->with($this->caseRelations())
                ->when($contentType !== '', fn (Builder $b) => $b->where('content_type', $contentType))
                ->when($status !== '', fn (Builder $b) => $b->where('status', $status))
                ->when($riskLevel !== '', fn (Builder $b) => $b->where('ai_risk_level', $riskLevel))
                ->when($from, fn (Builder $b) => $b->where('created_at', '>=', $from->copy()->startOfDay()))
                ->when($to, fn (Builder $b) => $b->where('created_at', '<=', $to->copy()->endOfDay()))
                ->when($query !== '', fn (Builder $b) => $this->applySearch($b, $query))
                ->latest(),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.moderation.queue_retrieved'),
            'data' => [
                'cases' => PaginatedJson::items($request, $cases, ContentModerationCaseResource::class),
            ],
            'meta' => [
                'cases' => PaginatedJson::meta($cases),
                'summary' => $this->summary($contentType),
            ],
        ]);
    }

    public function show(Request $request, ContentModerationCase $contentModerationCase): JsonResponse
    {
        SupportedLocales::apply($request);

        $contentModerationCase->load($this->caseRelations());

        return response()->json([
            'message' => __('messages.moderation.case_retrieved'),
            'data' => [
                'case' => new ContentModerationCaseResource($contentModerationCase),
            ],
        ]);
    }

    public function update(
        UpdateContentModerationCaseRequest $request,
        ContentModerationCase $contentModerationCase,
        ContentModerationService $moderationService,
    ): JsonResponse {
        SupportedLocales::apply($request);

        $previousStatus = $contentModerationCase->status;

        $moderationCase = $moderationService->applyManualDecision(
            $contentModerationCase,
            $request->user(),
            $request->validated('action'),
            $request->validated('notes'),
            $request->validated('reason'),
        );

        $this->recordAudit(
            $request,
            $request->user(),
            $moderationCase->getMorphClass(),
            $moderationCase->id,
            $this->decisionAction($moderationCase->content_type, $request->validated('action')),
            [
                'contentType' => $moderationCase->content_type,
                'from' => $previousStatus,
                'to' => $moderationCase->status,
                'notes' => $request->validated('notes'),
                'reason' => $request->validated('reason'),
            ],
        );

        $moderationCase->load($this->caseRelations());

        return response()->json([
            'message' => __('messages.moderation.case_updated'),
            'data' => [
                'case' => new ContentModerationCaseResource($moderationCase),
            ],
        ]);
    }

    public function rescanVideo(Request $request, Video $video, ContentModerationService $moderationService): JsonResponse
    {
        SupportedLocales::apply($request);

        $moderationCase = $moderationService->scanVideo($video);

        $this->recordAudit($request, $request->user(), $moderationCase->getMorphClass(), $moderationCase->id, 'admin.video_rescanned', [
            'contentType' => 'video',
            'videoId' => $video->id,
            'aiRiskLevel' => $moderationCase->ai_risk_level,
            'aiScore' => (int) $moderationCase->ai_score,
        ]);

        $moderationCase->load($this->caseRelations());

        return response()->json([
            'message' => __('messages.moderation.video_rescanned'),
            'data' => [
                'case' => new ContentModerationCaseResource($moderationCase),
            ],
        ]);
    }

    public function rescanComment(Request $request, Comment $comment, ContentModerationService $moderationService): JsonResponse
    {
        SupportedLocales::apply($request);

        $moderationCase = $moderationService->scanComment($comment);

        $this->recordAudit($request, $request->user(), $moderationCase->getMorphClass(), $moderationCase->id, 'admin.comment_rescanned', [
            'contentType' => 'comment',
            'commentId' => $comment->id,
            'aiRiskLevel' => $moderationCase->ai_risk_level,
            'aiScore' => (int) $moderationCase->ai_score,
        ]);

        $moderationCase->load($this->caseRelations());

        return response()->json([
            'message' => __('messages.moderation.comment_rescanned'),
            'data' => [
                'case' => new ContentModerationCaseResource($moderationCase),
            ],
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function caseRelations(): array
    {
        return [
            'reviewer',
            'moderatable' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    Comment::class => ['user', 'video.user'],
                    Video::class => ['user'],
                ]);
            },
        ];
    }

    private function applySearch(Builder $builder, string $query): Builder
    {
        return $builder->where(function (Builder $inner) use ($query): void {
            $inner->where('ai_summary', 'like', '%'.$query.'%')
                ->orWhere('review_notes', 'like', '%'.$query.'%')
                ->orWhere('action_reason', 'like', '%'.$query.'%')
                ->orWhereHasMorph('moderatable', [Comment::class], function (Builder $commentQuery) use ($query): void {
                    $commentQuery->where('body', 'like', '%'.$query.'%')
                        ->orWhereHas('user', fn (Builder $userQuery) => $this->matchUser($userQuery, $query));
                })
                ->orWhereHasMorph('moderatable', [Video::class], function (Builder $videoQuery) use ($query): void {
                    $videoQuery->where('title', 'like', '%'.$query.'%')
                        ->orWhere('caption', 'like', '%'.$query.'%')
                        ->orWhereHas('user', fn (Builder $userQuery) => $this->matchUser($userQuery, $query));
                });

            if (ctype_digit($query)) {
                $inner->orWhere('id', (int) $query);
            }
        });
    }

    private function matchUser(Builder $userQuery, string $query): Builder
    {
        return $userQuery->where('name', 'like', '%'.$query.'%')
            ->orWhere('username', 'like', '%'.$query.'%')
            ->orWhere('email', 'like', '%'.$query.'%');
    }

    /**
     * @return array<string, int>
     */
    private function summary(string $contentType): array
    {
        $base = fn () => ContentModerationCase::query()
            ->when($contentType !== '', fn (Builder $b) => $b->where('content_type', $contentType));

        return [
            'total' => $base()->count(),
            'pendingReview' => $base()->where('status', 'pending_review')->count(),
            'approved' => $base()->where('status', 'approved')->count(),
            'restricted' => $base()->where('status', 'restricted')->count(),
            'removed' => $base()->where('status', 'removed')->count(),
        ];
    }

    private function decisionAction(string $contentType, string $action): string
    {
        $verb = match ($action) {
            'approve' => 'approved',
            'restrict' => 'restricted',
            'remove' => 'removed',
            default => 'updated',
        };

        return 'admin.'.($contentType === 'comment' ? 'comment_' : 'video_').$verb;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordAudit(Request $request, User $admin, string $auditableType, int $auditableId, string $action, array $metadata): void
    {
        AuditLog::create([
            'user_id' => $admin->id,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}