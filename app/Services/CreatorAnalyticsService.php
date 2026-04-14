<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use App\Models\Video;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreatorAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $creator, string $period = '30d', int $limit = 5): array
    {
        [$startsAt, $endsAt] = $this->resolveWindow($period);
        $videosQuery = Video::query()->where('user_id', $creator->id);
        $videoIds = (clone $videosQuery)->pluck('id');

        $totalVideos = (clone $videosQuery)->count();
        $publishedVideos = (clone $videosQuery)->where('is_draft', false)->count();
        $draftVideos = (clone $videosQuery)->where('is_draft', true)->count();

        $engagementTotals = [
            'views' => (int) ((clone $videosQuery)->sum('views_count') ?? 0),
            'shares' => (int) ((clone $videosQuery)->sum('shares_count') ?? 0),
            'likes' => $this->countVideoInteractions($creator, 'like') + $this->countLiveLikes($creator),
            'saves' => $this->countVideoInteractions($creator, 'save'),
            'comments' => $this->countComments($creator),
        ];

        $subscriberCount = (int) DB::table('subscriptions')
            ->where('creator_id', $creator->id)
            ->count();

        $newSubscribers = (int) DB::table('subscriptions')
            ->where('creator_id', $creator->id)
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->count();

        $activeMemberships = Membership::query()
            ->where('creator_id', $creator->id)
            ->where('status', 'active')
            ->get();

        $overview = [
            'period' => [
                'key' => $period,
                'startsAt' => $startsAt->toISOString(),
                'endsAt' => $endsAt->toISOString(),
            ],
            'videos' => [
                'total' => $totalVideos,
                'published' => $publishedVideos,
                'drafts' => $draftVideos,
            ],
            'engagement' => $engagementTotals,
            'audience' => [
                'subscribers' => $subscriberCount,
                'newSubscribers' => $newSubscribers,
                'activeMemberships' => $activeMemberships->count(),
                'estimatedMonthlyRevenue' => round($activeMemberships->sum(fn (Membership $membership) => $this->monthlyValue($membership)), 2),
            ],
        ];

        return [
            'overview' => $overview,
            'trends' => $this->buildDashboardTrends($creator, $startsAt, $endsAt),
            'topVideos' => $this->buildTopVideos($creator, $limit),
            'audience' => $this->buildAudienceInsights($creator, $activeMemberships),
            'live' => $this->buildLiveInsights($creator),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function video(User $creator, Video $video, string $period = '30d'): array
    {
        [$startsAt, $endsAt] = $this->resolveWindow($period);

        $video->loadCount(['likes', 'saves', 'comments', 'liveLikeEvents']);

        $summary = [
            'period' => [
                'key' => $period,
                'startsAt' => $startsAt->toISOString(),
                'endsAt' => $endsAt->toISOString(),
            ],
            'lifetime' => [
                'views' => (int) $video->views_count,
                'shares' => (int) $video->shares_count,
                'likes' => (int) $video->likes_count,
                'saves' => (int) $video->saves_count,
                'comments' => (int) $video->comments_count,
                'liveLikes' => (int) $video->live_like_events_count,
                'peakViewers' => (int) ($video->live_peak_viewers_count ?? 0),
            ],
            'periodMetrics' => [
                'likes' => $this->countVideoInteractionsForVideo($video, 'like', $startsAt, $endsAt),
                'saves' => $this->countVideoInteractionsForVideo($video, 'save', $startsAt, $endsAt),
                'comments' => (int) DB::table('comments')
                    ->where('video_id', $video->id)
                    ->whereBetween('created_at', [$startsAt, $endsAt])
                    ->count(),
                'liveLikes' => (int) DB::table('live_like_events')
                    ->where('video_id', $video->id)
                    ->whereBetween('created_at', [$startsAt, $endsAt])
                    ->count(),
            ],
        ];

        return [
            'video' => [
                'id' => $video->id,
                'title' => $video->title,
                'caption' => $video->caption,
                'thumbnailUrl' => $video->thumbnail_url,
                'isLive' => (bool) $video->is_live,
                'isDraft' => (bool) $video->is_draft,
                'createdAt' => $video->created_at?->toISOString(),
            ],
            'summary' => $summary,
            'trend' => $this->buildVideoTrend($video, $startsAt, $endsAt),
            'audience' => [
                'topReactors' => $this->buildTopReactors($creator, $video, $startsAt, $endsAt),
            ],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(string $period): array
    {
        $endsAt = now();

        $startsAt = match ($period) {
            '7d' => now()->copy()->subDays(6)->startOfDay(),
            '90d' => now()->copy()->subDays(89)->startOfDay(),
            '365d' => now()->copy()->subDays(364)->startOfDay(),
            default => now()->copy()->subDays(29)->startOfDay(),
        };

        return [$startsAt, $endsAt];
    }

    private function countVideoInteractions(User $creator, string $type): int
    {
        return (int) DB::table('video_interactions')
            ->join('videos', 'videos.id', '=', 'video_interactions.video_id')
            ->where('videos.user_id', $creator->id)
            ->where('video_interactions.type', $type)
            ->count();
    }

    private function countComments(User $creator): int
    {
        return (int) DB::table('comments')
            ->join('videos', 'videos.id', '=', 'comments.video_id')
            ->where('videos.user_id', $creator->id)
            ->count();
    }

    private function countLiveLikes(User $creator): int
    {
        return (int) DB::table('live_like_events')
            ->join('videos', 'videos.id', '=', 'live_like_events.video_id')
            ->where('videos.user_id', $creator->id)
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDashboardTrends(User $creator, Carbon $startsAt, Carbon $endsAt): array
    {
        $likes = $this->groupCountsByDay(
            DB::table('video_interactions')
                ->join('videos', 'videos.id', '=', 'video_interactions.video_id')
                ->where('videos.user_id', $creator->id)
                ->where('video_interactions.type', 'like')
                ->whereBetween('video_interactions.created_at', [$startsAt, $endsAt]),
            'video_interactions.created_at'
        );

        $saves = $this->groupCountsByDay(
            DB::table('video_interactions')
                ->join('videos', 'videos.id', '=', 'video_interactions.video_id')
                ->where('videos.user_id', $creator->id)
                ->where('video_interactions.type', 'save')
                ->whereBetween('video_interactions.created_at', [$startsAt, $endsAt]),
            'video_interactions.created_at'
        );

        $comments = $this->groupCountsByDay(
            DB::table('comments')
                ->join('videos', 'videos.id', '=', 'comments.video_id')
                ->where('videos.user_id', $creator->id)
                ->whereBetween('comments.created_at', [$startsAt, $endsAt]),
            'comments.created_at'
        );

        $subscribers = $this->groupCountsByDay(
            DB::table('subscriptions')
                ->where('creator_id', $creator->id)
                ->whereBetween('created_at', [$startsAt, $endsAt]),
            'created_at'
        );

        $publishedVideos = $this->groupCountsByDay(
            DB::table('videos')
                ->where('user_id', $creator->id)
                ->where('is_draft', false)
                ->whereBetween('created_at', [$startsAt, $endsAt]),
            'created_at'
        );

        $membershipRevenue = $this->groupMembershipRevenueByDay($creator, $startsAt, $endsAt);

        return $this->dateRange($startsAt, $endsAt)
            ->map(function (Carbon $date) use ($likes, $saves, $comments, $subscribers, $publishedVideos, $membershipRevenue): array {
                $key = $date->toDateString();

                return [
                    'date' => $key,
                    'likes' => (int) ($likes[$key] ?? 0),
                    'saves' => (int) ($saves[$key] ?? 0),
                    'comments' => (int) ($comments[$key] ?? 0),
                    'subscribers' => (int) ($subscribers[$key] ?? 0),
                    'publishedVideos' => (int) ($publishedVideos[$key] ?? 0),
                    'membershipRevenue' => round((float) ($membershipRevenue[$key] ?? 0), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopVideos(User $creator, int $limit): array
    {
        return Video::query()
            ->where('user_id', $creator->id)
            ->withCount(['likes', 'saves', 'comments', 'liveLikeEvents'])
            ->latest()
            ->get()
            ->map(function (Video $video): array {
                $score = (int) $video->views_count
                    + ((int) $video->shares_count * 15)
                    + ((int) $video->likes_count * 8)
                    + ((int) $video->saves_count * 10)
                    + ((int) $video->comments_count * 12)
                    + ((int) $video->live_like_events_count * 5)
                    + ((int) ($video->live_peak_viewers_count ?? 0) * 3);

                return [
                    'id' => $video->id,
                    'title' => $video->title,
                    'thumbnailUrl' => $video->thumbnail_url,
                    'isLive' => (bool) $video->is_live,
                    'isDraft' => (bool) $video->is_draft,
                    'views' => (int) $video->views_count,
                    'shares' => (int) $video->shares_count,
                    'likes' => (int) $video->likes_count + (int) $video->live_like_events_count,
                    'saves' => (int) $video->saves_count,
                    'comments' => (int) $video->comments_count,
                    'liveLikes' => (int) $video->live_like_events_count,
                    'peakViewers' => (int) ($video->live_peak_viewers_count ?? 0),
                    'performanceScore' => $score,
                    'createdAt' => $video->created_at?->toISOString(),
                ];
            })
            ->sortByDesc('performanceScore')
            ->take(max(1, min(10, $limit)))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Membership>  $activeMemberships
     * @return array<string, mixed>
     */
    private function buildAudienceInsights(User $creator, Collection $activeMemberships): array
    {
        $topSupporters = $activeMemberships
            ->load(['member' => fn ($query) => $query->withProfileAggregates($creator)])
            ->map(function (Membership $membership): ?array {
                $member = $membership->member;

                if (! $member) {
                    return null;
                }

                return [
                    'user' => [
                        'id' => $member->id,
                        'fullName' => $member->name,
                        'username' => $member->username,
                        'avatarUrl' => $member->avatar_url,
                    ],
                    'membershipId' => $membership->id,
                    'planId' => $membership->creator_plan_id,
                    'status' => $membership->status,
                    'monthlyValue' => round($this->monthlyValue($membership), 2),
                    'startedAt' => $membership->started_at?->toISOString(),
                ];
            })
            ->filter()
            ->sortByDesc('monthlyValue')
            ->take(5)
            ->values()
            ->all();

        return [
            'topSupporters' => $topSupporters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLiveInsights(User $creator): array
    {
        $liveVideos = Video::query()
            ->where('user_id', $creator->id)
            ->where(function ($query): void {
                $query->where('is_live', true)
                    ->orWhereNotNull('live_started_at');
            })
            ->withCount(['comments', 'liveLikeEvents'])
            ->get();

        $bestVideo = $liveVideos
            ->map(function (Video $video): array {
                $score = ((int) $video->live_like_events_count * 5)
                    + ((int) $video->comments_count * 7)
                    + ((int) ($video->live_peak_viewers_count ?? 0) * 3);

                return [
                    'id' => $video->id,
                    'title' => $video->title,
                    'liveLikes' => (int) $video->live_like_events_count,
                    'liveComments' => (int) $video->comments_count,
                    'peakViewers' => (int) ($video->live_peak_viewers_count ?? 0),
                    'performanceScore' => $score,
                ];
            })
            ->sortByDesc('performanceScore')
            ->values()
            ->first();

        return [
            'videosCount' => $liveVideos->count(),
            'totalLiveLikes' => (int) $liveVideos->sum('live_like_events_count'),
            'totalLiveComments' => (int) $liveVideos->sum('comments_count'),
            'peakViewers' => (int) $liveVideos->max('live_peak_viewers_count'),
            'bestVideo' => $bestVideo,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildVideoTrend(Video $video, Carbon $startsAt, Carbon $endsAt): array
    {
        $likes = $this->groupCountsByDay(
            DB::table('video_interactions')
                ->where('video_id', $video->id)
                ->where('type', 'like')
                ->whereBetween('created_at', [$startsAt, $endsAt]),
            'created_at'
        );

        $saves = $this->groupCountsByDay(
            DB::table('video_interactions')
                ->where('video_id', $video->id)
                ->where('type', 'save')
                ->whereBetween('created_at', [$startsAt, $endsAt]),
            'created_at'
        );

        $comments = $this->groupCountsByDay(
            DB::table('comments')
                ->where('video_id', $video->id)
                ->whereBetween('created_at', [$startsAt, $endsAt]),
            'created_at'
        );

        $liveLikes = $this->groupCountsByDay(
            DB::table('live_like_events')
                ->where('video_id', $video->id)
                ->whereBetween('created_at', [$startsAt, $endsAt]),
            'created_at'
        );

        return $this->dateRange($startsAt, $endsAt)
            ->map(function (Carbon $date) use ($likes, $saves, $comments, $liveLikes): array {
                $key = $date->toDateString();

                return [
                    'date' => $key,
                    'likes' => (int) ($likes[$key] ?? 0),
                    'saves' => (int) ($saves[$key] ?? 0),
                    'comments' => (int) ($comments[$key] ?? 0),
                    'liveLikes' => (int) ($liveLikes[$key] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopReactors(User $creator, Video $video, Carbon $startsAt, Carbon $endsAt): array
    {
        $likeCounts = DB::table('video_interactions')
            ->select('user_id', DB::raw('COUNT(*) as likes_count'))
            ->where('video_id', $video->id)
            ->where('type', 'like')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->groupBy('user_id')
            ->pluck('likes_count', 'user_id');

        $commentCounts = DB::table('comments')
            ->select('user_id', DB::raw('COUNT(*) as comments_count'))
            ->where('video_id', $video->id)
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->groupBy('user_id')
            ->pluck('comments_count', 'user_id');

        $liveLikeCounts = DB::table('live_like_events')
            ->select('user_id', DB::raw('COUNT(*) as live_likes_count'))
            ->where('video_id', $video->id)
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->groupBy('user_id')
            ->pluck('live_likes_count', 'user_id');

        $userIds = collect([$likeCounts->keys(), $commentCounts->keys(), $liveLikeCounts->keys()])
            ->flatten()
            ->unique()
            ->filter()
            ->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->withProfileAggregates($creator)
            ->get()
            ->keyBy('id');

        return $userIds
            ->map(function ($userId) use ($users, $likeCounts, $commentCounts, $liveLikeCounts): ?array {
                $user = $users->get($userId);

                if (! $user) {
                    return null;
                }

                $likes = (int) ($likeCounts[$userId] ?? 0);
                $comments = (int) ($commentCounts[$userId] ?? 0);
                $liveLikes = (int) ($liveLikeCounts[$userId] ?? 0);

                return [
                    'user' => [
                        'id' => $user->id,
                        'fullName' => $user->name,
                        'username' => $user->username,
                        'avatarUrl' => $user->avatar_url,
                    ],
                    'likes' => $likes,
                    'comments' => $comments,
                    'liveLikes' => $liveLikes,
                    'engagements' => $likes + $comments + $liveLikes,
                ];
            })
            ->filter()
            ->sortByDesc('engagements')
            ->take(5)
            ->values()
            ->all();
    }

    private function countVideoInteractionsForVideo(Video $video, string $type, Carbon $startsAt, Carbon $endsAt): int
    {
        return (int) DB::table('video_interactions')
            ->where('video_id', $video->id)
            ->where('type', $type)
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->count();
    }

    /**
     * @return array<string, int>
     */
    private function groupCountsByDay($query, string $column): array
    {
        return $query
            ->selectRaw("DATE({$column}) as bucket_date, COUNT(*) as aggregate_count")
            ->groupBy('bucket_date')
            ->pluck('aggregate_count', 'bucket_date')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function groupMembershipRevenueByDay(User $creator, Carbon $startsAt, Carbon $endsAt): array
    {
        return Membership::query()
            ->where('creator_id', $creator->id)
            ->where('status', 'active')
            ->whereBetween('started_at', [$startsAt, $endsAt])
            ->get()
            ->groupBy(fn (Membership $membership) => optional($membership->started_at)->toDateString())
            ->map(fn (Collection $memberships) => round($memberships->sum(fn (Membership $membership) => $this->monthlyValue($membership)), 2))
            ->filter(fn ($value, $key) => $key !== null)
            ->all();
    }

    /**
     * @return Collection<int, Carbon>
     */
    private function dateRange(Carbon $startsAt, Carbon $endsAt): Collection
    {
        $dates = collect();
        $cursor = $startsAt->copy();

        while ($cursor->lessThanOrEqualTo($endsAt)) {
            $dates->push($cursor->copy());
            $cursor->addDay();
        }

        return $dates;
    }

    private function monthlyValue(Membership $membership): float
    {
        $amount = (float) $membership->price_amount;

        return match ($membership->billing_period) {
            'weekly' => round(($amount * 52) / 12, 2),
            'yearly' => round($amount / 12, 2),
            default => $amount,
        };
    }
}