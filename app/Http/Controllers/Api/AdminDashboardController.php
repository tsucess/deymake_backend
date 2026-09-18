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
use App\Services\CloudinaryUploadService;
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
    /**
     * ISO country-code to display-name map for the "Top Regions by DAU" card.
     * Unknown codes fall back to the raw (upper-cased) code.
     *
     * @var array<string, string>
     */
    private const REGION_NAMES = [
        'NG' => 'Nigeria',
        'GH' => 'Ghana',
        'CM' => 'Cameroon',
        'BJ' => 'Benin',
        'KE' => 'Kenya',
        'ZA' => 'South Africa',
        'TZ' => 'Tanzania',
        'UG' => 'Uganda',
        'CI' => "Côte d'Ivoire",
        'SN' => 'Senegal',
        'ET' => 'Ethiopia',
        'EG' => 'Egypt',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'CA' => 'Canada',
    ];

    /**
     * Reason groupings for the "Moderation Alerts" card, keyed by the fixed
     * frontend category slots. Reasons not listed here are not surfaced on the
     * dashboard card.
     *
     * @var array<string, list<string>>
     */
    private const MODERATION_CATEGORIES = [
        'violent_content' => ['violence', 'graphic_violence', 'terrorism', 'threat', 'threats'],
        'nudity_sexual' => ['nudity', 'sexual_content', 'csam', 'child_safety'],
        'hate_speech' => ['hate', 'hate_speech'],
        'spam' => ['spam', 'scam', 'misinformation'],
        'copyright' => ['copyright', 'impersonation'],
    ];

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
            ->with([
                'user' => fn ($query) => $query->withProfileAggregates($request->user()),
                'upload',
            ])
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Video $video) => [
                'id' => $video->id,
                'title' => $video->title,
                'caption' => $video->caption,
                'thumbnailUrl' => $this->resolveVideoThumbnail($video),
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
            'moderationAlerts' => $this->moderationAlerts($from, $to),
            'creatorGrowth' => $this->creatorGrowth($from, $to),
            'topChallenges' => $this->topChallenges(),
            'topChallengers' => $this->topChallengers(),
            'topRegions' => $this->topRegions($from, $to),
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

    /**
     * Moderation-alert counts per fixed category over the reporting window, with
     * a proportion of the relevant denominator: content-violation categories
     * (violent, nudity, spam, copyright) are expressed out of total platform
     * contents (published videos), while hate speech is expressed out of total
     * platform comments. `isLow` still tracks the "bad" direction (a
     * period-over-period rise in reports).
     *
     * @return array<int, array<string, mixed>>
     */
    private function moderationAlerts(Carbon $from, Carbon $to): array
    {
        [$prevFrom, $prevTo] = $this->previousRange($from, $to);

        $current = $this->reportCountsByReason($from, $to);
        $previous = $this->reportCountsByReason($prevFrom, $prevTo);

        $totalContents = $this->totalContentsCount();
        $totalComments = $this->totalCommentsCount();

        // Denominator per category: content-based versus comment-based reports.
        $denominators = [
            'violent_content' => $totalContents,
            'nudity_sexual' => $totalContents,
            'hate_speech' => $totalComments,
            'spam' => $totalContents,
            'copyright' => $totalContents,
        ];

        $items = [];

        foreach (self::MODERATION_CATEGORIES as $key => $reasons) {
            $currentTotal = $this->sumReasons($current, $reasons);
            $previousTotal = $this->sumReasons($previous, $reasons);

            $items[] = [
                'key' => $key,
                'value' => $currentTotal,
                'percent' => $this->proportionLabel($currentTotal, $denominators[$key] ?? 0),
                'isLow' => $currentTotal > $previousTotal,
            ];
        }

        return $items;
    }

    /**
     * Creator-growth metrics over the reporting window. Each percent is now a
     * proportion of a wider whole: weekly new creators out of new creators in
     * the trailing year, verified creators out of all users, weekly creator
     * earnings out of the calendar-month earnings, and weekly revenue shared
     * out of all-time platform earnings. `isLow` still tracks the "bad"
     * direction (a period-over-period decline).
     *
     * @return array<int, array<string, mixed>>
     */
    private function creatorGrowth(Carbon $from, Carbon $to): array
    {
        [$prevFrom, $prevTo] = $this->previousRange($from, $to);

        $newCreators = $this->newCreatorsCount($from, $to);
        $newCreatorsPrev = $this->newCreatorsCount($prevFrom, $prevTo);
        $newCreatorsYear = $this->newCreatorsCount($to->copy()->subYear(), $to);

        $verified = $this->verifiedCreatorsCount($from, $to);
        $verifiedPrev = $this->verifiedCreatorsCount($prevFrom, $prevTo);
        $totalUsers = $this->totalUsersCount();

        $earnings = $this->creatorEarnings($from, $to);
        $earningsPrev = $this->creatorEarnings($prevFrom, $prevTo);
        $earningsMonth = $this->creatorEarnings($to->copy()->startOfMonth(), $to->copy()->endOfMonth());

        $shared = $this->revenueShared($from, $to);
        $sharedPrev = $this->revenueShared($prevFrom, $prevTo);
        $totalEarnings = $this->totalPlatformEarnings();

        return [
            [
                'key' => 'new_creators',
                'value' => $newCreators,
                'percent' => $this->proportionLabel($newCreators, $newCreatorsYear),
                'isLow' => $newCreators < $newCreatorsPrev,
            ],
            [
                'key' => 'verified_creators',
                'value' => $verified,
                'percent' => $this->proportionLabel($verified, $totalUsers),
                'isLow' => $verified < $verifiedPrev,
            ],
            [
                'key' => 'creator_earnings',
                'value' => $earnings,
                'isMoney' => true,
                'percent' => $this->proportionLabel($earnings, $earningsMonth),
                'isLow' => $earnings < $earningsPrev,
            ],
            [
                'key' => 'revenue_shared',
                'value' => $shared,
                'isMoney' => true,
                'percent' => $this->proportionLabel($shared, $totalEarnings),
                'isLow' => $shared < $sharedPrev,
            ],
        ];
    }

    /**
     * Top three published challenges ranked by (non-withdrawn) entry count for
     * the dashboard "Top Challenges" card.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topChallenges(): Collection
    {
        return Challenge::query()
            ->where('status', '!=', 'draft')
            ->withCount(['submissions as submissions_count' => fn (Builder $query) => $query->where('status', '!=', 'withdrawn')])
            ->orderByDesc('submissions_count')
            ->latest('published_at')
            ->limit(3)
            ->get()
            ->map(fn (Challenge $challenge) => [
                'id' => $challenge->id,
                'title' => $challenge->title,
                'entries' => (int) ($challenge->submissions_count ?? 0),
                'thumbnailUrl' => $challenge->thumbnail_url,
                'status' => $challenge->lifecycleStatus(),
            ])
            ->values();
    }

    /**
     * Resolve a displayable thumbnail for a trending-video card: prefer the
     * stored thumbnail, fall back to the image itself for photo posts, and
     * derive a poster frame from managed (Cloudinary) sources for videos.
     * Returns null when no image thumbnail can be produced so the frontend
     * can render its placeholder.
     */
    private function resolveVideoThumbnail(Video $video): ?string
    {
        if (is_string($video->thumbnail_url) && $video->thumbnail_url !== '') {
            return $video->thumbnail_url;
        }

        if ($video->type !== 'video') {
            return $video->upload?->url ?: ($video->media_url ?: null);
        }

        $source = $video->upload?->path ?: $video->media_url;

        if (! is_string($source) || $source === '') {
            return null;
        }

        $cloudinary = app(CloudinaryUploadService::class);

        return $cloudinary->isManagedUrl($source)
            ? $cloudinary->thumbnailUrlFor($source)
            : null;
    }

    /**
     * Top challengers ranked by challenge wins (winning submissions), with
     * follower (subscriber) counts and verified status for the
     * "Top Challengers" card.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topChallengers(): Collection
    {
        return User::query()
            ->whereHas('challengeSubmissions', fn (Builder $query) => $query->where('is_winner', true))
            ->withCount([
                'challengeSubmissions as wins_count' => fn (Builder $query) => $query->where('is_winner', true),
                'subscribers as subscribers_count',
            ])
            ->orderByDesc('wins_count')
            ->orderByDesc('subscribers_count')
            ->limit(4)
            ->get()
            ->map(fn (User $challenger) => [
                'id' => $challenger->id,
                'fullName' => $challenger->name,
                'username' => $challenger->username,
                'avatarUrl' => $challenger->avatar_url,
                'wins' => (int) ($challenger->wins_count ?? 0),
                'followersCount' => (int) ($challenger->subscribers_count ?? 0),
                'isVerifiedCreator' => $challenger->creator_verification_status === 'approved',
                'role' => $challenger->is_admin ? 'admin' : 'creator',
            ])
            ->values();
    }

    /**
     * Top regions by daily-active users (unique users with recent activity),
     * grouped by ISO country code, with period-over-period change.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topRegions(Carbon $from, Carbon $to): Collection
    {
        [$prevFrom, $prevTo] = $this->previousRange($from, $to);

        $current = $this->regionDau($from, $to);
        $previous = $this->regionDau($prevFrom, $prevTo);

        return $current
            ->take(4)
            ->map(function ($dau, $code) use ($previous): array {
                $currentDau = (int) $dau;
                $previousDau = (int) $previous->get($code, 0);

                return [
                    'code' => $code,
                    'region' => $this->regionName((string) $code),
                    'value' => $currentDau,
                    'percent' => $this->percentLabel($currentDau, $previousDau),
                    'isLow' => $currentDau < $previousDau,
                ];
            })
            ->values();
    }

    /**
     * Report counts keyed by reason over a window.
     *
     * @return Collection<string, int>
     */
    private function reportCountsByReason(Carbon $from, Carbon $to): Collection
    {
        return VideoReport::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('reason, COUNT(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');
    }

    /**
     * @param  Collection<string, int>  $counts
     * @param  list<string>  $reasons
     */
    private function sumReasons(Collection $counts, array $reasons): int
    {
        $total = 0;

        foreach ($reasons as $reason) {
            $total += (int) $counts->get($reason, 0);
        }

        return $total;
    }

    private function newCreatorsCount(Carbon $from, Carbon $to): int
    {
        return User::query()
            ->has('videos')
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    private function verifiedCreatorsCount(Carbon $from, Carbon $to): int
    {
        return User::query()
            ->where('creator_verification_status', 'approved')
            ->whereBetween('creator_verified_at', [$from, $to])
            ->count();
    }

    private function creatorEarnings(Carbon $from, Carbon $to): int
    {
        return (int) WalletTransaction::query()
            ->where('direction', 'credit')
            ->where('status', 'posted')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');
    }

    private function revenueShared(Carbon $from, Carbon $to): int
    {
        return (int) FanTip::query()
            ->where('status', 'posted')
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');
    }

    /**
     * Total platform contents: all published (non-draft) videos. Used as the
     * denominator for content-violation moderation categories.
     */
    private function totalContentsCount(): int
    {
        return Video::query()->where('is_draft', false)->count();
    }

    /**
     * Total platform comments. Denominator for comment-violation moderation
     * categories (hate speech, spam).
     */
    private function totalCommentsCount(): int
    {
        return Comment::query()->count();
    }

    /**
     * Total registered users. Denominator for the verified-creators proportion.
     */
    private function totalUsersCount(): int
    {
        return User::query()->count();
    }

    /**
     * All-time platform earnings: every posted credit across creator wallets.
     * Denominator for the revenue-shared proportion.
     */
    private function totalPlatformEarnings(): int
    {
        return (int) WalletTransaction::query()
            ->where('direction', 'credit')
            ->where('status', 'posted')
            ->sum('amount');
    }

    /**
     * Unique daily-active users per country code over a window.
     *
     * @return Collection<string, int>
     */
    private function regionDau(Carbon $from, Carbon $to): Collection
    {
        return User::query()
            ->whereNotNull('country_code')
            ->where('country_code', '!=', '')
            ->whereBetween('last_active_at', [$from, $to])
            ->selectRaw('country_code, COUNT(*) as dau')
            ->groupBy('country_code')
            ->orderByDesc('dau')
            ->pluck('dau', 'country_code');
    }

    private function regionName(string $code): string
    {
        $upper = mb_strtoupper($code);

        return self::REGION_NAMES[$upper] ?? $upper;
    }

    /**
     * The equal-length window immediately preceding the given range, used for
     * period-over-period comparisons.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previousRange(Carbon $from, Carbon $to): array
    {
        $days = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $from->copy()->subDays($days)->startOfDay();

        return [$prevFrom, $prevTo];
    }

    /**
     * Signed percent-change label (e.g. "+12%", "-5%", "+0%") between two
     * values, treating a zero baseline with a positive current as +100%.
     */
    private function percentLabel(float $current, float $previous): string
    {
        if ($previous <= 0.0) {
            $pct = $current > 0.0 ? 100.0 : 0.0;
        } else {
            $pct = (($current - $previous) / $previous) * 100.0;
        }

        $rounded = round($pct, 1);
        $number = $rounded == (int) $rounded ? (string) (int) $rounded : (string) $rounded;

        return ($rounded > 0 ? '+' : '').$number.'%';
    }

    /**
     * Unsigned proportion label (e.g. "12%", "3.5%", "0%") expressing a part as
     * a percentage of a whole. A zero (or missing) denominator yields "0%".
     */
    private function proportionLabel(float $part, float $whole): string
    {
        $pct = $whole > 0.0 ? ($part / $whole) * 100.0 : 0.0;

        $rounded = round($pct, 1);
        $number = $rounded == (int) $rounded ? (string) (int) $rounded : (string) $rounded;

        return $number.'%';
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
