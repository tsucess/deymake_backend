<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CollaborationInvite;
use App\Models\CreatorPlan;
use App\Models\Membership;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Video;
use App\Models\WalletTransaction;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin creator ecosystem controller.
 *
 * Powers the Creator Ecosystem admin console: creator directory, growth and
 * engagement analytics, monetization, programs/benefits and collaboration
 * activity.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/CreatorEcosystem.jsx and its tab components.
 * Related: AdminDashboardController, AdminUserManagementController.
 */
class AdminCreatorController extends Controller
{
    private const VERIFICATION_STATUSES = ['approved', 'pending', 'rejected', 'unsubmitted'];

    private const ACCOUNT_STATUSES = ['active', 'suspended', 'banned'];

    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $query = trim($request->string('q')->toString());
        $category = trim($request->string('category')->toString());
        $country = trim($request->string('country')->toString());
        $verification = trim($request->string('verificationStatus')->toString());
        $status = trim($request->string('status')->toString());
        $sort = trim($request->string('sort')->toString()) ?: 'latest';
        [$from, $to] = $this->resolveDateRange($request, false);

        $creators = PaginatedJson::paginate(
            $this->creatorBaseQuery()
                ->when($query !== '', fn (Builder $b) => $b->where(function (Builder $inner) use ($query): void {
                    $inner->where('name', 'like', '%'.$query.'%')
                        ->orWhere('username', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%');
                }))
                ->when($category !== '', fn (Builder $b) => $b->whereHas('videos', fn (Builder $v) => $v
                    ->where('is_draft', false)
                    ->whereHas('category', fn (Builder $c) => $c->where('name', $category))))
                ->when($country !== '', fn (Builder $b) => $b->where('country_code', $country))
                ->when(in_array($verification, ['verified', 'approved'], true), fn (Builder $b) => $b->where('creator_verification_status', 'approved'))
                ->when($verification === 'pending', fn (Builder $b) => $b->where('creator_verification_status', 'pending'))
                ->when($verification === 'rejected', fn (Builder $b) => $b->where('creator_verification_status', 'rejected'))
                ->when(in_array($verification, ['unverified', 'unsubmitted'], true), fn (Builder $b) => $b->where(fn (Builder $i) => $i->whereNull('creator_verification_status')->orWhere('creator_verification_status', '!=', 'approved')))
                ->when(in_array($status, self::ACCOUNT_STATUSES, true), fn (Builder $b) => $b->where('account_status', $status))
                ->when($from !== null, fn (Builder $b) => $b->where('created_at', '>=', $from))
                ->when($to !== null, fn (Builder $b) => $b->where('created_at', '<=', $to))
                ->when($sort === 'oldest', fn (Builder $b) => $b->oldest())
                ->when($sort === 'top', fn (Builder $b) => $b->orderByDesc('views_sum')->latest())
                ->when($sort === 'earnings', fn (Builder $b) => $b->orderByDesc('earnings_sum')->latest())
                ->when($sort === 'followers', fn (Builder $b) => $b->orderByDesc('subscribers_count')->latest())
                ->when(! in_array($sort, ['oldest', 'top', 'earnings', 'followers'], true), fn (Builder $b) => $b->latest()),
            $request,
            12,
            50
        );

        $rows = collect($creators->items());
        $ids = $rows->pluck('id')->all();
        $categoryByUser = $this->primaryCategoryByUser($ids);
        $engagementByUser = $this->engagementByUser($ids);

        return response()->json([
            'message' => __('messages.admin.creators_retrieved'),
            'data' => [
                'creators' => $rows->map(fn (User $creator) => $this->creatorRow($creator, $categoryByUser, $engagementByUser))->values(),
            ],
            'meta' => [
                'creators' => PaginatedJson::meta($creators),
                'summary' => $this->summary(),
            ],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $query = trim($request->string('q')->toString());
        $category = trim($request->string('category')->toString());
        $country = trim($request->string('country')->toString());
        $verification = trim($request->string('verificationStatus')->toString());
        $status = trim($request->string('status')->toString());

        $filename = 'creators-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $category, $country, $verification, $status): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Username', 'Email', 'Country', 'Followers', 'Views', 'Earnings (kobo)', 'Verification', 'Status', 'Joined At']);

            $this->creatorBaseQuery()
                ->when($query !== '', fn (Builder $b) => $b->where(function (Builder $inner) use ($query): void {
                    $inner->where('name', 'like', '%'.$query.'%')
                        ->orWhere('username', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%');
                }))
                ->when($category !== '', fn (Builder $b) => $b->whereHas('videos', fn (Builder $v) => $v
                    ->where('is_draft', false)
                    ->whereHas('category', fn (Builder $c) => $c->where('name', $category))))
                ->when($country !== '', fn (Builder $b) => $b->where('country_code', $country))
                ->when(in_array($verification, ['verified', 'approved'], true), fn (Builder $b) => $b->where('creator_verification_status', 'approved'))
                ->when($verification === 'pending', fn (Builder $b) => $b->where('creator_verification_status', 'pending'))
                ->when($verification === 'rejected', fn (Builder $b) => $b->where('creator_verification_status', 'rejected'))
                ->when(in_array($verification, ['unverified', 'unsubmitted'], true), fn (Builder $b) => $b->where(fn (Builder $i) => $i->whereNull('creator_verification_status')->orWhere('creator_verification_status', '!=', 'approved')))
                ->when(in_array($status, self::ACCOUNT_STATUSES, true), fn (Builder $b) => $b->where('account_status', $status))
                ->orderBy('id')
                ->chunk(200, function (Collection $rows) use ($handle): void {
                    foreach ($rows as $creator) {
                        fputcsv($handle, [
                            $creator->id,
                            $creator->name,
                            $creator->username,
                            $creator->email,
                            $creator->country_code,
                            (int) ($creator->subscribers_count ?? 0),
                            (int) ($creator->views_sum ?? 0),
                            (int) ($creator->earnings_sum ?? 0),
                            $creator->creator_verification_status ?: 'unsubmitted',
                            $creator->account_status ?: 'active',
                            optional($creator->created_at)->toIso8601String(),
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Creator growth, performance and engagement analytics over the requested
     * reporting window, plus category distribution and a views leaderboard.
     */
    public function analytics(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        [$from, $to] = $this->resolveDateRange($request, true);
        $category = trim($request->string('category')->toString());
        $country = trim($request->string('country')->toString());

        $labels = [];
        $newCreators = [];
        $activeCreators = [];
        $views = [];
        $engagements = [];
        $engagementRate = [];

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $start = $cursor->copy()->startOfDay();
            $end = $cursor->copy()->endOfDay();

            $labels[] = $cursor->format('M d');

            $newCreators[] = User::query()
                ->has('videos')
                ->when($country !== '', fn (Builder $b) => $b->where('country_code', $country))
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $activeCreators[] = User::query()
                ->has('videos')
                ->when($country !== '', fn (Builder $b) => $b->where('country_code', $country))
                ->whereBetween('last_active_at', [$start, $end])
                ->count();

            $dayViews = (int) Video::query()
                ->where('is_draft', false)
                ->whereBetween('created_at', [$start, $end])
                ->sum('views_count');

            $dayLikes = (int) DB::table('video_interactions')
                ->where('type', 'like')
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $dayComments = (int) DB::table('comments')
                ->where('moderation_status', 'visible')
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $dayEngagements = $dayLikes + $dayComments;

            $views[] = $dayViews;
            $engagements[] = $dayEngagements;
            $engagementRate[] = $dayViews > 0 ? round(min(100, ($dayEngagements / $dayViews) * 100), 1) : 0.0;

            $cursor->addDay();
        }

        return response()->json([
            'message' => __('messages.admin.creator_analytics_retrieved'),
            'data' => [
                'growth' => [
                    'labels' => $labels,
                    'newCreators' => $newCreators,
                    'activeCreators' => $activeCreators,
                ],
                'performance' => [
                    'labels' => $labels,
                    'views' => $views,
                    'engagements' => $engagements,
                    'engagementRate' => $engagementRate,
                ],
                'categories' => $this->categoryDistribution($category, $country),
                'engagementDistribution' => $this->engagementDistribution(),
                'topCreators' => $this->topCreators(),
                'totalViews' => (int) Video::query()->where('is_draft', false)->sum('views_count'),
            ],
            'meta' => [
                'summary' => $this->summary(),
                'dateRange' => [
                    'from' => $from->toISOString(),
                    'to' => $to->toISOString(),
                ],
            ],
        ]);
    }

    /**
     * Monetization overview: platform earnings/payout totals plus a top-earner
     * leaderboard and the most recent payout requests.
     */
    public function monetization(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $summary = [
            'totalEarnings' => (int) WalletTransaction::query()->where('direction', 'credit')->where('status', 'posted')->sum('amount'),
            'membershipRevenue' => (int) WalletTransaction::query()->where('type', 'membership_credit')->where('status', 'posted')->sum('amount'),
            'totalPaidOut' => (int) PayoutRequest::query()->where('status', 'paid')->sum('amount'),
            'pendingPayoutAmount' => (int) PayoutRequest::query()->whereIn('status', ['requested', 'processing'])->sum('amount'),
            'pendingPayoutCount' => PayoutRequest::query()->whereIn('status', ['requested', 'processing'])->count(),
            'activeMemberships' => Membership::query()->where('status', 'active')->count(),
        ];

        $topEarners = $this->creatorBaseQuery()
            ->orderByDesc('earnings_sum')
            ->limit(10)
            ->get()
            ->map(fn (User $creator) => [
                'id' => $creator->id,
                'fullName' => $creator->name,
                'username' => $creator->username,
                'avatarUrl' => $creator->avatar_url,
                'earnings' => (int) ($creator->earnings_sum ?? 0),
                'followers' => (int) ($creator->subscribers_count ?? 0),
                'views' => (int) ($creator->views_sum ?? 0),
            ])
            ->values();

        $recentPayouts = PayoutRequest::query()
            ->with('user')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (PayoutRequest $payout) => [
                'id' => $payout->id,
                'amount' => (int) $payout->amount,
                'currency' => $payout->currency,
                'status' => $payout->status,
                'requestedAt' => ($payout->requested_at ?? $payout->created_at)?->toISOString(),
                'creator' => $payout->user ? [
                    'id' => $payout->user->id,
                    'fullName' => $payout->user->name,
                    'username' => $payout->user->username,
                    'avatarUrl' => $payout->user->avatar_url,
                ] : null,
            ])
            ->values();

        return response()->json([
            'message' => __('messages.admin.creator_monetization_retrieved'),
            'data' => [
                'summary' => $summary,
                'topEarners' => $topEarners,
                'recentPayouts' => $recentPayouts,
            ],
        ]);
    }

    /**
     * Creator programs and benefits: the plans creators offer, an aggregated
     * benefit catalogue and the newest creators in the onboarding queue.
     */
    public function programs(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $plans = CreatorPlan::query()
            ->with('creator')
            ->withCount([
                'memberships as active_memberships_count' => fn (Builder $b) => $b->where('status', 'active'),
                'memberships as total_memberships_count',
            ])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $benefitCounts = [];
        foreach ($plans as $plan) {
            foreach ((array) ($plan->benefits ?? []) as $benefit) {
                $label = trim((string) $benefit);
                if ($label === '') {
                    continue;
                }
                $benefitCounts[$label] = ($benefitCounts[$label] ?? 0) + 1;
            }
        }

        $benefits = collect($benefitCounts)
            ->map(fn (int $count, string $label) => ['label' => $label, 'plans' => $count])
            ->sortByDesc('plans')
            ->values()
            ->all();

        $onboarding = User::query()
            ->has('videos')
            ->withCount(['videos as videos_count' => fn (Builder $b) => $b->where('is_draft', false)])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (User $creator) => [
                'id' => $creator->id,
                'fullName' => $creator->name,
                'username' => $creator->username,
                'avatarUrl' => $creator->avatar_url,
                'videosCount' => (int) ($creator->videos_count ?? 0),
                'verificationStatus' => $creator->creator_verification_status ?: 'unsubmitted',
                'joinedAt' => $creator->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'message' => __('messages.admin.creator_programs_retrieved'),
            'data' => [
                'summary' => [
                    'totalPlans' => CreatorPlan::query()->count(),
                    'activePlans' => CreatorPlan::query()->where('is_active', true)->count(),
                    'totalMemberships' => Membership::query()->count(),
                    'activeMemberships' => Membership::query()->where('status', 'active')->count(),
                ],
                'plans' => $plans->map(fn (CreatorPlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'priceAmount' => (int) $plan->price_amount,
                    'currency' => $plan->currency,
                    'billingPeriod' => $plan->billing_period,
                    'isActive' => (bool) $plan->is_active,
                    'benefits' => array_values((array) ($plan->benefits ?? [])),
                    'activeMemberships' => (int) ($plan->active_memberships_count ?? 0),
                    'totalMemberships' => (int) ($plan->total_memberships_count ?? 0),
                    'creator' => $plan->creator ? [
                        'id' => $plan->creator->id,
                        'fullName' => $plan->creator->name,
                        'username' => $plan->creator->username,
                        'avatarUrl' => $plan->creator->avatar_url,
                    ] : null,
                ])->values(),
                'benefits' => $benefits,
                'onboarding' => $onboarding,
            ],
        ]);
    }

    /**
     * Collaboration activity: a paginated, status-filterable feed of
     * collaboration invites with a status summary.
     */
    public function collaborations(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $status = trim($request->string('status')->toString());
        $type = trim($request->string('type')->toString());

        $invites = PaginatedJson::paginate(
            CollaborationInvite::query()
                ->with(['inviter', 'invitee', 'sourceVideo'])
                ->when($status !== '', fn (Builder $b) => $b->where('status', $status))
                ->when($type !== '', fn (Builder $b) => $b->where('type', $type))
                ->latest(),
            $request,
            12,
            50
        );

        $rows = collect($invites->items())->map(fn (CollaborationInvite $invite) => [
            'id' => $invite->id,
            'type' => $invite->type,
            'status' => $invite->status,
            'message' => $invite->message,
            'createdAt' => $invite->created_at?->toISOString(),
            'respondedAt' => $invite->responded_at?->toISOString(),
            'inviter' => $invite->inviter ? [
                'id' => $invite->inviter->id,
                'fullName' => $invite->inviter->name,
                'username' => $invite->inviter->username,
                'avatarUrl' => $invite->inviter->avatar_url,
            ] : null,
            'invitee' => $invite->invitee ? [
                'id' => $invite->invitee->id,
                'fullName' => $invite->invitee->name,
                'username' => $invite->invitee->username,
                'avatarUrl' => $invite->invitee->avatar_url,
            ] : null,
            'sourceVideo' => $invite->sourceVideo ? [
                'id' => $invite->sourceVideo->id,
                'title' => $invite->sourceVideo->title,
                'thumbnailUrl' => $invite->sourceVideo->thumbnail_url,
            ] : null,
        ])->values();

        return response()->json([
            'message' => __('messages.admin.creator_collaborations_retrieved'),
            'data' => [
                'collaborations' => $rows,
            ],
            'meta' => [
                'collaborations' => PaginatedJson::meta($invites),
                'summary' => [
                    'total' => CollaborationInvite::query()->count(),
                    'pending' => CollaborationInvite::query()->where('status', 'pending')->count(),
                    'accepted' => CollaborationInvite::query()->where('status', 'accepted')->count(),
                    'declined' => CollaborationInvite::query()->where('status', 'declined')->count(),
                    'expired' => CollaborationInvite::query()->where('status', 'expired')->count(),
                ],
            ],
        ]);
    }

    /**
     * Base creator query: users who have published as creators, annotated with
     * follower counts and lifetime view/earnings sums used for sorting and rows.
     */
    private function creatorBaseQuery(): Builder
    {
        return User::query()
            ->has('videos')
            ->withCount('subscribers')
            ->withSum(['videos as views_sum' => fn (Builder $b) => $b->where('is_draft', false)], 'views_count')
            ->withSum(['walletTransactions as earnings_sum' => fn (Builder $b) => $b->where('direction', 'credit')->where('status', 'posted')], 'amount');
    }

    /**
     * Creator directory summary counts surfaced on the creators tab cards and
     * verification donut.
     */
    private function summary(): array
    {
        $total = User::query()->has('videos')->count();
        $verified = User::query()->has('videos')->where('creator_verification_status', 'approved')->count();
        $pending = User::query()->has('videos')->where('creator_verification_status', 'pending')->count();

        return [
            'total' => $total,
            'new' => User::query()->has('videos')->where('created_at', '>=', now()->subDays(30))->count(),
            'active' => User::query()->has('videos')->where('last_active_at', '>=', now()->subDays(30))->count(),
            'verified' => $verified,
            'pending' => $pending,
            'unverified' => max(0, $total - $verified - $pending),
        ];
    }

    /**
     * Resolve the reporting window. When $withDefault is false only explicitly
     * supplied bounds are returned (used to filter the creator list by join
     * date); otherwise the window defaults to the last 30 days, clamped to 92.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveDateRange(Request $request, bool $withDefault, int $defaultDays = 29): array
    {
        $to = $request->date('to');
        $from = $request->date('from');

        $to = $to instanceof Carbon ? $to->copy()->endOfDay() : ($withDefault ? now()->endOfDay() : null);
        $from = $from instanceof Carbon
            ? $from->copy()->startOfDay()
            : ($withDefault ? ($to ?? now()->endOfDay())->copy()->subDays($defaultDays)->startOfDay() : null);

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            $from = $to->copy()->subDays($defaultDays)->startOfDay();
        }

        if ($from !== null && $to !== null && $from->diffInDays($to) > 92) {
            $from = $to->copy()->subDays(92)->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * Map each creator id to the name of the category they publish in most.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, string>
     */
    private function primaryCategoryByUser(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $rows = Video::query()
            ->whereIn('user_id', $ids)
            ->where('is_draft', false)
            ->whereNotNull('category_id')
            ->selectRaw('user_id, category_id, COUNT(*) as total')
            ->groupBy('user_id', 'category_id')
            ->get();

        $categoryNames = Category::query()
            ->whereIn('id', $rows->pluck('category_id')->unique()->all())
            ->pluck('name', 'id');

        return $rows
            ->groupBy('user_id')
            ->map(function (Collection $group) use ($categoryNames): string {
                $top = $group->sortByDesc('total')->first();

                return $categoryNames->get($top->category_id, __('messages.admin.uncategorized'));
            });
    }

    /**
     * Compute an engagement rate (interactions as a percentage of views) per
     * creator across their published videos.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, float>
     */
    private function engagementByUser(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $views = Video::query()
            ->whereIn('user_id', $ids)
            ->where('is_draft', false)
            ->selectRaw('user_id, SUM(views_count) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $likes = DB::table('video_interactions')
            ->join('videos', 'videos.id', '=', 'video_interactions.video_id')
            ->whereIn('videos.user_id', $ids)
            ->where('videos.is_draft', false)
            ->where('video_interactions.type', 'like')
            ->selectRaw('videos.user_id as user_id, COUNT(*) as total')
            ->groupBy('videos.user_id')
            ->pluck('total', 'user_id');

        $comments = DB::table('comments')
            ->join('videos', 'videos.id', '=', 'comments.video_id')
            ->whereIn('videos.user_id', $ids)
            ->where('videos.is_draft', false)
            ->where('comments.moderation_status', 'visible')
            ->selectRaw('videos.user_id as user_id, COUNT(*) as total')
            ->groupBy('videos.user_id')
            ->pluck('total', 'user_id');

        return collect($ids)->mapWithKeys(function (int $id) use ($views, $likes, $comments): array {
            $totalViews = (int) ($views[$id] ?? 0);
            $interactions = (int) ($likes[$id] ?? 0) + (int) ($comments[$id] ?? 0);
            $rate = $totalViews > 0 ? round(min(100, ($interactions / $totalViews) * 100), 1) : 0.0;

            return [$id => $rate];
        });
    }

    /**
     * Bucket every creator into high/medium/low engagement tiers for the
     * engagement-rate distribution chart.
     */
    private function engagementDistribution(): array
    {
        $ids = User::query()->has('videos')->pluck('id')->all();
        $rates = $this->engagementByUser($ids);

        $high = 0;
        $medium = 0;
        $low = 0;

        foreach ($rates as $rate) {
            if ($rate > 8) {
                $high++;
            } elseif ($rate >= 4) {
                $medium++;
            } else {
                $low++;
            }
        }

        return ['high' => $high, 'medium' => $medium, 'low' => $low];
    }

    /**
     * Distinct creator counts and total views grouped by content category.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoryDistribution(string $category, string $country): array
    {
        $rows = Video::query()
            ->where('is_draft', false)
            ->whereNotNull('category_id')
            ->when($country !== '', fn (Builder $b) => $b->whereHas('user', fn (Builder $u) => $u->where('country_code', $country)))
            ->when($category !== '', fn (Builder $b) => $b->whereHas('category', fn (Builder $c) => $c->where('name', $category)))
            ->selectRaw('category_id, COUNT(DISTINCT user_id) as creators, SUM(views_count) as views')
            ->groupBy('category_id')
            ->get();

        $categoryNames = Category::query()
            ->whereIn('id', $rows->pluck('category_id')->unique()->all())
            ->pluck('name', 'id');

        return $rows
            ->map(fn ($row) => [
                'name' => $categoryNames->get($row->category_id, __('messages.admin.uncategorized')),
                'creators' => (int) $row->creators,
                'views' => (int) $row->views,
            ])
            ->sortByDesc('views')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * Top creators ranked by lifetime published-video views.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function topCreators(): Collection
    {
        return $this->creatorBaseQuery()
            ->orderByDesc('views_sum')
            ->limit(10)
            ->get()
            ->map(fn (User $creator) => [
                'id' => $creator->id,
                'fullName' => $creator->name,
                'username' => $creator->username,
                'avatarUrl' => $creator->avatar_url,
                'followers' => (int) ($creator->subscribers_count ?? 0),
                'views' => (int) ($creator->views_sum ?? 0),
                'earnings' => (int) ($creator->earnings_sum ?? 0),
            ])
            ->values();
    }

    /**
     * Shape a single creator directory row, merging the sortable DB aggregates
     * with the per-page category and engagement lookups.
     *
     * @param  Collection<int, string>  $categoryByUser
     * @param  Collection<int, float>  $engagementByUser
     */
    private function creatorRow(User $creator, Collection $categoryByUser, Collection $engagementByUser): array
    {
        return [
            'id' => $creator->id,
            'fullName' => $creator->name,
            'username' => $creator->username,
            'email' => $creator->email,
            'avatarUrl' => $creator->avatar_url,
            'country' => $creator->country_code,
            'category' => $categoryByUser->get($creator->id, __('messages.admin.uncategorized')),
            'followers' => (int) ($creator->subscribers_count ?? 0),
            'views' => (int) ($creator->views_sum ?? 0),
            'engagement' => (float) $engagementByUser->get($creator->id, 0),
            'earnings' => (int) ($creator->earnings_sum ?? 0),
            'verificationStatus' => $creator->creator_verification_status ?: 'unsubmitted',
            'status' => $creator->accountStatus(),
            'joinedAt' => $creator->created_at?->toISOString(),
        ];
    }
}
