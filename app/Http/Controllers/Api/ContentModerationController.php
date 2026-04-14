<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateContentModerationCaseRequest;
use App\Http\Resources\ContentModerationCaseResource;
use App\Models\Comment;
use App\Models\ContentModerationCase;
use App\Models\Video;
use App\Services\ContentModerationService;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentModerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $status = trim($request->string('status')->toString());
        $contentType = trim($request->string('contentType')->toString());
        $riskLevel = trim($request->string('riskLevel')->toString());

        $cases = PaginatedJson::paginate(
            ContentModerationCase::query()
                ->with(['reviewer', 'moderatable'])
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->when($contentType !== '', fn ($query) => $query->where('content_type', $contentType))
                ->when($riskLevel !== '', fn ($query) => $query->where('ai_risk_level', $riskLevel))
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
            ],
        ]);
    }

    public function show(Request $request, ContentModerationCase $contentModerationCase): JsonResponse
    {
        SupportedLocales::apply($request);

        $contentModerationCase->load(['reviewer', 'moderatable']);

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

        $moderationCase = $moderationService->applyManualDecision(
            $contentModerationCase,
            $request->user(),
            $request->validated('action'),
            $request->validated('notes'),
            $request->validated('reason'),
        );

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

        return response()->json([
            'message' => __('messages.moderation.comment_rescanned'),
            'data' => [
                'case' => new ContentModerationCaseResource($moderationCase),
            ],
        ]);
    }
}