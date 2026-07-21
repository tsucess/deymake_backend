<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExploreRisingCreatorService
{
    public function rising(?User $viewer = null, ?int $categoryId = null, int $limit = 6, int $windowDays = 7): Collection
    {
        $since = Carbon::now()->subDays($windowDays);

        $creatorScores = $this->aggregateCreatorScores($since, $categoryId, $limit);

        if ($creatorScores->isEmpty()) {
            $creatorScores = User::query()
                ->whereHas('videos', fn (Builder $q) => $q
                    ->where('is_draft', false)
                    ->where('moderation_status', 'visible')
                    ->when($categoryId, fn ($qq) => $qq->where('category_id', $categoryId)))
                ->withCount('subscribers')
                ->orderByDesc('subscribers_count')
                ->limit($limit)
                ->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => 0]);
        }

        $ids = $creatorScores->keys()->all();

        if (empty($ids)) {
            return collect();
        }

        $creators = User::query()
            ->withProfileAggregates($viewer)
            ->whereIn('id', $ids)
            ->get();

        $categoryNames = Category::query()->pluck('name', 'id');

        return $creators
            ->map(function (User $creator) use ($categoryNames, $creatorScores, $categoryId): array {
                $recentVideos = Video::query()
                    ->discoverable()
                    ->where('user_id', $creator->id)
                    ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
                    ->orderByDesc('created_at')
                    ->limit(3)
                    ->get(['id', 'public_id', 'thumbnail_url', 'media_url', 'category_id']);

                $topCategoryId = Video::query()
                    ->discoverable()
                    ->where('user_id', $creator->id)
                    ->whereNotNull('category_id')
                    ->selectRaw('category_id, COUNT(*) AS uses')
                    ->groupBy('category_id')
                    ->orderByDesc('uses')
                    ->value('category_id');

                return [
                    'creator' => $creator,
                    'role' => $topCategoryId ? ($categoryNames[$topCategoryId] ?? null) : null,
                    'engagementScore' => (int) ($creatorScores[$creator->id] ?? 0),
                    'recentVideos' => $recentVideos,
                ];
            })
            ->sortByDesc('engagementScore')
            ->values();
    }

    protected function aggregateCreatorScores(Carbon $since, ?int $categoryId, int $limit): Collection
    {
        $videoIdFilter = Video::query()
            ->select('id', 'user_id')
            ->where('is_draft', false)
            ->where('moderation_status', 'visible')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId));

        $videos = $videoIdFilter->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('id')->all());

        $scores = [];

        foreach ($videos as $userId => $videoIds) {
            if (empty($videoIds)) {
                continue;
            }

            $likes = DB::table('video_interactions')
                ->whereIn('video_id', $videoIds)
                ->where('type', 'like')
                ->where('created_at', '>=', $since)
                ->count();

            $reposts = DB::table('video_interactions')
                ->whereIn('video_id', $videoIds)
                ->where('type', 'repost')
                ->where('created_at', '>=', $since)
                ->count();

            $comments = DB::table('comments')
                ->whereIn('video_id', $videoIds)
                ->where('moderation_status', 'visible')
                ->where('created_at', '>=', $since)
                ->count();

            $total = $likes + $reposts + $comments;

            if ($total > 0) {
                $scores[$userId] = $total;
            }
        }

        arsort($scores);

        return collect(array_slice($scores, 0, $limit, true));
    }
}
