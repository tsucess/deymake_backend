<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateVideoReportRequest;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VideoReportResource;
use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Models\Comment;
use App\Models\Membership;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $recentUsers = User::query()->latest()->limit(5)->get();
        $recentChallenges = Challenge::query()->withApiResourceData($request->user())->latest()->limit(5)->get();
        $recentReports = $this->videoReportQuery()->latest()->limit(5)->get();
        $recentVideos = Video::query()
            ->with(['user' => fn ($query) => $query->withProfileAggregates($request->user())])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Video $video) => [
                'id' => $video->id,
                'title' => $video->title,
                'caption' => $video->caption,
                'thumbnailUrl' => $video->thumbnail_url,
                'isDraft' => (bool) $video->is_draft,
                'isLive' => (bool) $video->is_live,
                'createdAt' => $video->created_at?->toISOString(),
                'author' => $video->user ? [
                    'id' => $video->user->id,
                    'fullName' => $video->user->name,
                    'username' => $video->user->username,
                ] : null,
            ])
            ->values();

        return response()->json([
            'message' => __('messages.admin.dashboard_retrieved'),
            'data' => [
                'summary' => [
                    'totalUsers' => User::query()->count(),
                    'activeUsers' => User::query()->where('last_active_at', '>=', now()->subDay())->count(),
                    'totalCreators' => User::query()->has('videos')->count(),
                    'totalVideos' => Video::query()->count(),
                    'publishedVideos' => Video::query()->where('is_draft', false)->count(),
                    'liveVideos' => Video::query()->where('is_live', true)->count(),
                    'totalComments' => Comment::query()->count(),
                    'activeMemberships' => Membership::query()->where('status', 'active')->count(),
                    'totalChallenges' => Challenge::query()->count(),
                    'openChallenges' => Challenge::query()
                        ->where('status', 'published')
                        ->where('submission_starts_at', '<=', now())
                        ->where(fn (Builder $query) => $query->whereNull('submission_ends_at')->orWhere('submission_ends_at', '>=', now()))
                        ->count(),
                    'challengeSubmissions' => ChallengeSubmission::query()->count(),
                    'pendingVideoReports' => VideoReport::query()->where('status', 'pending')->count(),
                    'reviewedVideoReports' => VideoReport::query()->whereIn('status', ['reviewed', 'dismissed', 'escalated'])->count(),
                ],
                'recentUsers' => UserResource::collection($recentUsers),
                'recentVideos' => $recentVideos,
                'recentChallenges' => ChallengeResource::collection($recentChallenges),
                'recentVideoReports' => VideoReportResource::collection($recentReports),
            ],
        ]);
    }

    public function videoReports(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $status = trim($request->string('status')->toString());

        $reports = PaginatedJson::paginate(
            $this->videoReportQuery()
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->latest(),
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

    public function updateVideoReport(UpdateVideoReportRequest $request, VideoReport $videoReport): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();

        $videoReport->forceFill([
            'status' => $validated['status'],
            'resolution_notes' => $validated['resolutionNotes'] ?? null,
            'reviewed_by' => $validated['status'] === 'pending' ? null : $request->user()->id,
            'reviewed_at' => $validated['status'] === 'pending' ? null : now(),
        ])->save();

        $videoReport->load([
            'user',
            'reviewer',
            'video.user' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        return response()->json([
            'message' => __('messages.admin.video_report_updated'),
            'data' => [
                'report' => new VideoReportResource($videoReport),
            ],
        ]);
    }

    private function videoReportQuery(): Builder
    {
        return VideoReport::query()->with([
            'user',
            'reviewer',
            'video.user',
        ]);
    }
}