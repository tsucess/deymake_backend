<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandCampaign;
use App\Models\Comment;
use App\Models\FanTip;
use App\Models\Membership;
use App\Models\MerchOrder;
use App\Models\MerchProduct;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Video;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin analytics controller.
 *
 * Powers the Admin Analytics dashboard with real, API-backed metrics across
 * users, creators, videos, engagement, live streams, monetization and revenue.
 *
 * Endpoints (under the admin prefix in routes/api.php):
 *   - overview:      headline cards with period-over-period deltas + totals
 *   - timeseries:    day/week/month bucketed series for a chosen metric
 *   - distribution:  country, revenue-source, device and platform breakdowns
 *   - topProducts:   best-selling merch by units and revenue
 *   - exportCsv:     streamed CSV of the overview metrics
 *
 * Frontend consumer: Admin/Pages/Analytics.jsx and its chart components.
 * Related: AdminDashboardController, CreatorAnalyticsService.
 */
class AdminAnalyticsController extends Controller
{
    private const MAX_BUCKETS = 180;

    public function overview(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        [$from, $to] = $this->resolveDateRange($request, true);
        [$prevFrom, $prevTo] = $this->previousWindow($from, $to);

        $labels = [
            'users' => __('messages.admin.metric_users'),
            'creators' => __('messages.admin.metric_creators'),
            'videos' => __('messages.admin.metric_videos'),
            'views' => __('messages.admin.metric_views'),
            'engagement' => __('messages.admin.metric_engagement'),
            'comments' => __('messages.admin.metric_comments'),
            'shares' => __('messages.admin.metric_shares'),
            'liveStreams' => __('messages.admin.metric_live_streams'),
            'tips' => __('messages.admin.metric_tips'),
            'memberships' => __('messages.admin.metric_memberships'),
            'campaigns' => __('messages.admin.metric_campaigns'),
            'orders' => __('messages.admin.metric_orders'),
            'merchSales' => __('messages.admin.metric_merch_sales'),
            'payouts' => __('messages.admin.metric_payouts'),
            'revenue' => __('messages.admin.metric_revenue'),
        ];

        $cards = collect($this->metricResolvers())
            ->map(function (callable $resolver, string $key) use ($labels, $from, $to, $prevFrom, $prevTo): array {
                $current = (int) $resolver($from, $to);
                $previous = (int) $resolver($prevFrom, $prevTo);

                return [
                    'key' => $key,
                    'label' => $labels[$key] ?? $key,
                    'value' => $current,
                    'previous' => $previous,
                    'delta' => $this->delta($current, $previous),
                ];
            })
            ->values();

        return response()->json([
            'message' => __('messages.admin.analytics_overview_retrieved'),
            'data' => [
                'cards' => $cards,
                'totals' => $this->totals(),
            ],
            'meta' => [
                'range' => $this->rangeMeta($from, $to),
            ],
        ]);
    }

    /**
     * Platform-wide all-time totals shown alongside the windowed cards.
     *
     * @return array<string, int>
     */
    private function totals(): array
    {
        return [
            'totalUsers' => User::query()->count(),
            'totalCreators' => User::query()->has('videos')->count(),
            'verifiedCreators' => User::query()->where('creator_verification_status', 'approved')->count(),
            'totalVideos' => Video::query()->count(),
            'totalComments' => Comment::query()->count(),
            'activeMemberships' => Membership::query()->where('status', 'active')->count(),
            'liveStreams' => Video::query()->where('is_live', true)->count(),
            'activeUsers' => User::query()->where('last_active_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Metric resolvers keyed by metric name. Each closure returns the metric
     * value for the supplied [from, to] window so the same definitions power
     * both the overview cards and the timeseries buckets.
     *
     * @return array<string, callable(Carbon, Carbon): int>
     */
    private function metricResolvers(): array
    {
        return [
            'users' => fn (Carbon $f, Carbon $t): int => User::query()->whereBetween('created_at', [$f, $t])->count(),
            'creators' => fn (Carbon $f, Carbon $t): int => User::query()->has('videos')->whereBetween('created_at', [$f, $t])->count(),
            'videos' => fn (Carbon $f, Carbon $t): int => Video::query()->whereBetween('created_at', [$f, $t])->count(),
            'views' => fn (Carbon $f, Carbon $t): int => (int) Video::query()->whereBetween('created_at', [$f, $t])->sum('views_count'),
            'engagement' => fn (Carbon $f, Carbon $t): int => $this->engagementTotal($f, $t),
            'comments' => fn (Carbon $f, Carbon $t): int => Comment::query()->whereBetween('created_at', [$f, $t])->count(),
            'shares' => fn (Carbon $f, Carbon $t): int => (int) Video::query()->whereBetween('created_at', [$f, $t])->sum('shares_count'),
            'liveStreams' => fn (Carbon $f, Carbon $t): int => Video::query()->whereBetween('live_started_at', [$f, $t])->count(),
            'tips' => fn (Carbon $f, Carbon $t): int => (int) FanTip::query()->where('status', 'posted')->whereBetween('created_at', [$f, $t])->sum('amount'),
            'memberships' => fn (Carbon $f, Carbon $t): int => Membership::query()->whereBetween('started_at', [$f, $t])->count(),
            'campaigns' => fn (Carbon $f, Carbon $t): int => BrandCampaign::query()->whereBetween('created_at', [$f, $t])->count(),
            'orders' => fn (Carbon $f, Carbon $t): int => MerchOrder::query()->where('status', 'paid')->whereBetween('created_at', [$f, $t])->count(),
            'merchSales' => fn (Carbon $f, Carbon $t): int => (int) MerchOrder::query()->where('status', 'paid')->whereBetween('created_at', [$f, $t])->sum('total_amount'),
            'payouts' => fn (Carbon $f, Carbon $t): int => (int) PayoutRequest::query()->whereBetween('created_at', [$f, $t])->sum('amount'),
            'revenue' => fn (Carbon $f, Carbon $t): int => array_sum($this->revenueBySource($f, $t)),
        ];
    }

    /**
     * Engagement = interactions (likes/saves/etc.) on videos created in the
     * window, plus comments created and shares accrued in the window.
     */
    private function engagementTotal(Carbon $from, Carbon $to): int
    {
        $interactions = (int) DB::table('video_interactions')
            ->join('videos', 'videos.id', '=', 'video_interactions.video_id')
            ->whereBetween('videos.created_at', [$from, $to])
            ->count();

        $comments = Comment::query()->whereBetween('created_at', [$from, $to])->count();
        $shares = (int) Video::query()->whereBetween('created_at', [$from, $to])->sum('shares_count');

        return $interactions + $comments + $shares;
    }

    /**
     * Revenue broken down by monetization source for the given window, in the
     * platform's smallest currency unit (kobo).
     *
     * @return array<string, int>
     */
    private function revenueBySource(Carbon $from, Carbon $to): array
    {
        return [
            'tips' => (int) FanTip::query()->where('status', 'posted')->whereBetween('created_at', [$from, $to])->sum('amount'),
            'memberships' => (int) Membership::query()->where('status', 'active')->whereBetween('started_at', [$from, $to])->sum('price_amount'),
            'merch' => (int) MerchOrder::query()->where('status', 'paid')->whereBetween('created_at', [$from, $to])->sum('total_amount'),
            'campaigns' => (int) BrandCampaign::query()->whereIn('status', ['active', 'closed'])->whereBetween('created_at', [$from, $to])->sum('budget_amount'),
        ];
    }

}
