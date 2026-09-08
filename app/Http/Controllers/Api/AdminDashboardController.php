<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateVideoReportRequest;
use App\Http\Resources\AdminDashboardResource;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\PayoutRequestResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VideoReportResource;
use App\Models\Category;
use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Models\Comment;
use App\Models\ContentModerationCase;
use App\Models\CreatorVerificationRequest;
use App\Models\FanTip;
use App\Models\Membership;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use App\Models\WalletTransaction;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Admin dashboard controller.
 *
 * Platform overview numbers for operators: totals, growth deltas, recent
 * activity feeds.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/Dashboard.jsx.
 * Related: AdminUserManagementController, AdminPayoutController.
 * See PROJECT_OVERVIEW.md §3.27 for the full data-flow map.
 */
class AdminDashboardController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        [$from, $to] = $this->resolveDateRange($request);

        $recentUsers = User::query()->latest()->limit(5)->get();
        $recentChallenges = Challenge::query()->withApiResourceData($request->user())->latest()->limit(5)->get();
        $recentReports = $this->videoReportQuery()->latest()->limit(5)->get();
        $recentPayouts = PayoutRequest::query()
            ->with(['user', 'reviewer', 'payoutAccount'])
            ->latest()
            ->limit(5)
            ->get();
        $recentVerificationRequests = CreatorVerificationRequest::query()
            ->with('user')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (CreatorVerificationRequest $verificationRequest) => [
                'id' => $verificationRequest->id,
                'status' => $verificationRequest->status,
                'submittedAt' => $verificationRequest->submitted_at?->toISOString(),
                'creator' => $verificationRequest->user ? [
                    'id' => $verificationRequest->user->id,
                    'fullName' => $verificationRequest->user->name,
                    'username' => $verificationRequest->user->username,
                    'avatarUrl' => $verificationRequest->user->avatar_url,
                ] : null,
            ])
            ->values();
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

        $data = [
            'summary' => $this->summary(),
            'charts' => $this->charts($from, $to),
            'topCreators' => $this->topCreators(),
            'recentUsers' => UserResource::collection($recentUsers),
            'recentVideos' => $recentVideos,
            'recentChallenges' => ChallengeResource::collection($recentChallenges),
            'recentVideoReports' => VideoReportResource::collection($recentReports),
            'recentReports' => VideoReportResource::collection($recentReports),
            'recentPayouts' => PayoutRequestResource::collection($recentPayouts),
            'recentVerificationRequests' => $recentVerificationRequests,
            'recentActivity' => $this->recentActivity($recentUsers, $recentVideos, $recentReports, $recentPayouts),
            'dateRange' => [
                'from' => $from->toISOString(),
                'to' => $to->toISOString(),
            ],
        ];

        return AdminDashboardResource::make($data)
            ->additional(['message' => __('messages.admin.dashboard_retrieved')])
            ->response();
    }

    /**
     * Platform-wide state totals plus operational queues surfaced on the admin
     * dashboard cards.
     */
    private function summary(): array
    {
        return [
            'totalUsers' => User::query()->count(),
            'activeUsers' => User::query()->where('last_active_at', '>=', now()->subDay())->count(),
            'suspendedUsers' => User::query()->where('account_status', 'suspended')->count(),
            'bannedUsers' => User::query()->where('account_status', 'banned')->count(),
            'totalCreators' => User::query()->has('videos')->count(),
            'verifiedCreators' => User::query()->where('creator_verification_status', 'approved')->count(),
            'totalVideos' => Video::query()->count(),
            'publishedVideos' => Video::query()->where('is_draft', false)->count(),
            'reportedVideos' => VideoReport::query()->where('status', 'pending')->distinct()->count('video_id'),
            'liveVideos' => Video::query()->where('is_live', true)->count(),
            'liveStreams' => Video::query()->where('is_live', true)->count(),
            'totalComments' => Comment::query()->count(),
            'activeMemberships' => Membership::query()->where('status', 'active')->count(),
            'totalMemberships' => Membership::query()->count(),
            'revenue' => (int) WalletTransaction::query()->where('direction', 'credit')->where('status', 'posted')->sum('amount'),
            'tips' => (int) FanTip::query()->where('status', 'posted')->sum('amount'),
            'payoutRequests' => PayoutRequest::query()->where('status', 'requested')->count(),
            'pendingModerationCases' => ContentModerationCase::query()->whereIn('status', ['pending', 'flagged', 'under_review'])->count(),
            'pendingVerificationRequests' => CreatorVerificationRequest::query()->where('status', 'pending')->count(),
            'totalChallenges' => Challenge::query()->count(),
            'openChallenges' => Challenge::query()
                ->where('status', 'published')
                ->where('submission_starts_at', '<=', now())
                ->where(fn (Builder $query) => $query->whereNull('submission_ends_at')->orWhere('submission_ends_at', '>=', now()))
                ->count(),
            'challengeSubmissions' => ChallengeSubmission::query()->count(),
            'pendingVideoReports' => VideoReport::query()->where('status', 'pending')->count(),
            'reviewedVideoReports' => VideoReport::query()->whereIn('status', ['reviewed', 'dismissed', 'escalated'])->count(),
        ];
    }

    /**
     * Top creators ranked by total published video views, with follower counts
     * and lifetime earnings for the dashboard leaderboard.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topCreators(): Collection
    {
        return User::query()
            ->has('videos')
            ->withCount('subscribers')
            ->withSum(['videos as views_sum' => fn (Builder $query) => $query->where('is_draft', false)], 'views_count')
            ->withSum(['walletTransactions as earnings_sum' => fn (Builder $query) => $query->where('direction', 'credit')->where('status', 'posted')], 'amount')
            ->orderByDesc('views_sum')
            ->limit(5)
            ->get()
            ->map(fn (User $creator) => [
                'id' => $creator->id,
                'fullName' => $creator->name,
                'username' => $creator->username,
                'avatarUrl' => $creator->avatar_url,
                'subscribersCount' => (int) ($creator->subscribers_count ?? 0),
                'views' => (int) ($creator->views_sum ?? 0),
                'earnings' => (int) ($creator->earnings_sum ?? 0),
            ])
            ->values();
    }

    /**
     * Resolve the requested reporting window from the `from`/`to` query params,
     * defaulting to the last seven days and clamping the span to 92 days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(Request $request): array
    {
        $to = $request->date('to');
        $from = $request->date('from');

        $to = $to instanceof Carbon ? $to->copy()->endOfDay() : now()->endOfDay();
        $from = $from instanceof Carbon ? $from->copy()->startOfDay() : $to->copy()->subDays(6)->startOfDay();

        if ($from->greaterThan($to)) {
            $from = $to->copy()->subDays(6)->startOfDay();
        }

        if ($from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * Merge the recent record feeds into a single time-sorted activity stream.
     *
     * @param  Collection<int, User>  $recentUsers
     * @param  Collection<int, array<string, mixed>>  $recentVideos
     * @param  Collection<int, VideoReport>  $recentReports
     * @param  Collection<int, PayoutRequest>  $recentPayouts
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity($recentUsers, $recentVideos, $recentReports, $recentPayouts): array
    {
        return collect()
            ->concat($recentUsers->map(fn (User $user) => [
                'type' => 'user',
                'title' => $user->name,
                'description' => __('messages.admin.activity_user_joined'),
                'at' => $user->created_at?->toISOString(),
            ]))
            ->concat(collect($recentVideos)->map(fn (array $video) => [
                'type' => 'video',
                'title' => $video['title'],
                'description' => __('messages.admin.activity_video_uploaded'),
                'at' => $video['createdAt'],
            ]))
            ->concat($recentReports->map(fn (VideoReport $report) => [
                'type' => 'report',
                'title' => $report->reason,
                'description' => __('messages.admin.activity_report_filed'),
                'at' => $report->created_at?->toISOString(),
            ]))
            ->concat($recentPayouts->map(fn (PayoutRequest $payout) => [
                'type' => 'payout',
                'title' => $payout->user?->name,
                'description' => __('messages.admin.activity_payout_requested'),
                'at' => $payout->created_at?->toISOString(),
            ]))
            ->filter(fn (array $item) => $item['at'] !== null)
            ->sortByDesc('at')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * Chart-friendly aggregates over the requested reporting window: daily
     * growth, user, content, live-stream, and revenue series, plus a view-count
     * distribution by content category.
     *
     * Uses per-day count/sum queries and GROUP BY/SUM so the aggregation behaves
     * identically on sqlite (tests) and mysql (production).
     */
    private function charts(Carbon $from, Carbon $to): array
    {
        $labels = [];
        $newUsers = [];
        $newCreators = [];
        $activeCreators = [];
        $newVideos = [];
        $liveStreams = [];
        $revenue = [];
        $tips = [];

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $start = $cursor->copy()->startOfDay();
            $end = $cursor->copy()->endOfDay();

            $labels[] = $cursor->format('M d');

            $newUsers[] = User::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $newCreators[] = User::query()
                ->has('videos')
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $activeCreators[] = User::query()
                ->has('videos')
                ->whereBetween('last_active_at', [$start, $end])
                ->count();

            $newVideos[] = Video::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $liveStreams[] = Video::query()
                ->whereBetween('live_started_at', [$start, $end])
                ->count();

            $revenue[] = (int) WalletTransaction::query()
                ->where('direction', 'credit')
                ->where('status', 'posted')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $tips[] = (int) FanTip::query()
                ->where('status', 'posted')
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');

            $cursor->addDay();
        }

        $categoryViews = Video::query()
            ->where('is_draft', false)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, SUM(views_count) as views')
            ->groupBy('category_id')
            ->pluck('views', 'category_id');

        $categoryNames = Category::query()
            ->whereIn('id', $categoryViews->keys())
            ->pluck('name', 'id');

        $categories = $categoryViews
            ->map(fn ($views, $id) => [
                'name' => $categoryNames->get($id, __('messages.admin.uncategorized')),
                'views' => (int) $views,
            ])
            ->sortByDesc('views')
            ->take(6)
            ->values()
            ->all();

        return [
            'labels' => $labels,
            'growth' => [
                'labels' => $labels,
                'newCreators' => $newCreators,
                'activeCreators' => $activeCreators,
            ],
            'users' => [
                'labels' => $labels,
                'newUsers' => $newUsers,
                'activeCreators' => $activeCreators,
            ],
            'content' => [
                'labels' => $labels,
                'newVideos' => $newVideos,
            ],
            'live' => [
                'labels' => $labels,
                'liveStreams' => $liveStreams,
            ],
            'revenue' => [
                'labels' => $labels,
                'revenue' => $revenue,
                'tips' => $tips,
            ],
            'categories' => $categories,
            'totalViews' => (int) Video::query()->where('is_draft', false)->sum('views_count'),
        ];
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
