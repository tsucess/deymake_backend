<?php

namespace App\Services;

use App\Models\HashtagDailyCount;
use App\Models\Video;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExploreHashtagService
{
    protected const SCAN_CAP = 2000;

    protected const TAG_PATTERN = '/#([\p{L}\p{N}_]{2,50})/u';

    public function trending(?int $categoryId = null, int $limit = 10, int $windowDays = 7): array
    {
        $precomputed = $this->trendingFromPrecomputed($categoryId, $limit, $windowDays);

        if ($precomputed !== null) {
            return $precomputed;
        }

        $now = Carbon::now();
        $currentStart = $now->copy()->subDays($windowDays);
        $previousStart = $now->copy()->subDays($windowDays * 2);

        $rows = Video::query()
            ->discoverable()
            ->where(function ($query): void {
                $query->whereNotNull('hashtags')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('description')->where('description', '!=', '');
                    });
            })
            ->where('created_at', '>=', $previousStart)
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->orderByDesc('created_at')
            ->limit(self::SCAN_CAP)
            ->get(['id', 'description', 'hashtags', 'created_at']);

        $currentCounts = [];
        $previousCounts = [];
        $displayForms = [];

        foreach ($rows as $row) {
            $tags = $this->collectTagsFromRow($row);

            if ($tags === []) {
                continue;
            }

            $inCurrent = $row->created_at !== null && $row->created_at->greaterThanOrEqualTo($currentStart);
            $bucket = $inCurrent ? 'current' : 'previous';

            foreach ($tags as $normalized => $rawTag) {
                if ($bucket === 'current') {
                    $currentCounts[$normalized] = ($currentCounts[$normalized] ?? 0) + 1;
                    $displayForms[$normalized] = $displayForms[$normalized] ?? $rawTag;
                } else {
                    $previousCounts[$normalized] = ($previousCounts[$normalized] ?? 0) + 1;
                    $displayForms[$normalized] = $displayForms[$normalized] ?? $rawTag;
                }
            }
        }

        $ranked = [];

        foreach ($currentCounts as $tag => $count) {
            $prev = $previousCounts[$tag] ?? 0;
            $ranked[] = [
                'tag' => '#'.$displayForms[$tag],
                'slug' => $tag,
                'postsCount' => $count,
                'postsLabel' => $this->humanCount($count),
                'growthPercent' => $this->growthPercent($count, $prev),
                'growthLabel' => $this->growthLabel($count, $prev),
            ];
        }

        usort($ranked, fn ($a, $b) => $b['postsCount'] <=> $a['postsCount']);

        return array_slice($ranked, 0, $limit);
    }

    protected function growthPercent(int $current, int $previous): int
    {
        if ($previous === 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    protected function growthLabel(int $current, int $previous): string
    {
        $percent = $this->growthPercent($current, $previous);
        $sign = $percent >= 0 ? '+' : '';

        return $sign.$percent.'%';
    }

    protected function humanCount(int $count): string
    {
        if ($count >= 1_000_000) {
            return number_format($count / 1_000_000, 1).'M posts';
        }

        if ($count >= 1_000) {
            return number_format($count / 1_000, 1).'K posts';
        }

        return $count.' posts';
    }

    protected function trendingFromPrecomputed(?int $categoryId, int $limit, int $windowDays): ?array
    {
        $now = Carbon::now();
        $currentStart = $now->copy()->subDays($windowDays)->startOfDay();
        $previousStart = $now->copy()->subDays($windowDays * 2)->startOfDay();

        $rows = HashtagDailyCount::query()
            ->selectRaw('tag, MIN(display_tag) AS display_tag, bucket_date, SUM(posts_count) AS posts_count')
            ->where('bucket_date', '>=', $previousStart->toDateString())
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when(! $categoryId, fn ($q) => $q->whereNull('category_id'))
            ->groupBy('tag', 'bucket_date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $current = [];
        $previous = [];
        $display = [];

        foreach ($rows as $row) {
            $date = Carbon::parse($row->bucket_date);
            $bucket = $date->greaterThanOrEqualTo($currentStart) ? 'current' : 'previous';
            $tag = $row->tag;
            $display[$tag] = $display[$tag] ?? $row->display_tag;

            if ($bucket === 'current') {
                $current[$tag] = ($current[$tag] ?? 0) + (int) $row->posts_count;
            } else {
                $previous[$tag] = ($previous[$tag] ?? 0) + (int) $row->posts_count;
            }
        }

        $ranked = [];

        foreach ($current as $tag => $count) {
            $prev = $previous[$tag] ?? 0;
            $ranked[] = [
                'tag' => '#'.$display[$tag],
                'slug' => $tag,
                'postsCount' => $count,
                'postsLabel' => $this->humanCount($count),
                'growthPercent' => $this->growthPercent($count, $prev),
                'growthLabel' => $this->growthLabel($count, $prev),
            ];
        }

        usort($ranked, fn ($a, $b) => $b['postsCount'] <=> $a['postsCount']);

        return array_slice($ranked, 0, $limit);
    }

    public function rebuild(int $windowDays = 14): int
    {
        $now = Carbon::now();
        $windowStart = $now->copy()->subDays($windowDays * 2)->startOfDay();

        $videos = Video::query()
            ->discoverable()
            ->where(function ($query): void {
                $query->whereNotNull('hashtags')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('description')->where('description', '!=', '');
                    });
            })
            ->where('created_at', '>=', $windowStart)
            ->orderByDesc('created_at')
            ->get(['id', 'description', 'hashtags', 'category_id', 'created_at']);

        $buckets = [];

        foreach ($videos as $video) {
            $tags = $this->collectTagsFromRow($video);

            if ($tags === []) {
                continue;
            }

            $date = $video->created_at?->toDateString();

            if (! $date) {
                continue;
            }

            foreach ($tags as $normalized => $rawTag) {
                foreach ([null, $video->category_id] as $catKey) {
                    $key = $normalized.'|'.($catKey ?? '0').'|'.$date;
                    if (! isset($buckets[$key])) {
                        $buckets[$key] = [
                            'tag' => $normalized,
                            'display_tag' => $rawTag,
                            'category_id' => $catKey,
                            'bucket_date' => $date,
                            'posts_count' => 0,
                        ];
                    }
                    $buckets[$key]['posts_count']++;
                }
            }
        }

        DB::transaction(function () use ($buckets, $windowStart): void {
            HashtagDailyCount::query()
                ->where('bucket_date', '>=', $windowStart->toDateString())
                ->delete();

            foreach (array_chunk(array_values($buckets), 500) as $chunk) {
                HashtagDailyCount::query()->insert(array_map(function ($row) {
                    $row['created_at'] = now();
                    $row['updated_at'] = now();
                    return $row;
                }, $chunk));
            }
        });

        return count($buckets);
    }

    /**
     * Collect unique tags from a video row, preferring the persisted hashtags
     * column and falling back to a description scan when the column is empty.
     *
     * @return array<string,string> map of normalized-tag => display-tag (first-seen wins)
     */
    protected function collectTagsFromRow(Video $video): array
    {
        $tags = [];

        $stored = $video->hashtags;
        if (is_array($stored) && $stored !== []) {
            foreach ($stored as $tag) {
                if (! is_string($tag) || $tag === '') {
                    continue;
                }
                $normalized = Str::lower(ltrim($tag, '#'));
                if ($normalized === '' || isset($tags[$normalized])) {
                    continue;
                }
                $tags[$normalized] = ltrim($tag, '#');
            }
        }

        if ($tags === [] && preg_match_all(self::TAG_PATTERN, (string) $video->description, $matches)) {
            foreach (array_unique($matches[1]) as $rawTag) {
                $normalized = Str::lower($rawTag);
                if (! isset($tags[$normalized])) {
                    $tags[$normalized] = $rawTag;
                }
            }
        }

        return $tags;
    }
}
