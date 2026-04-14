<?php

namespace App\Services;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;

class TalentDiscoveryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters, ?User $viewer = null): Builder
    {
        $query = trim((string) ($filters['q'] ?? ''));
        $categoryId = $filters['categoryId'] ?? null;
        $verifiedOnly = (bool) ($filters['verifiedOnly'] ?? false);
        $minSubscribers = (int) ($filters['minSubscribers'] ?? 0);
        $hasActivePlans = filter_var($filters['hasActivePlans'] ?? false, FILTER_VALIDATE_BOOL);

        return User::query()
            ->withProfileAggregates($viewer)
            ->withCount([
                'videos as published_videos_count' => fn (Builder $videos) => $videos
                    ->where('is_draft', false)
                    ->where('moderation_status', 'visible'),
            ])
            ->addSelect([
                'published_views_count' => Video::query()
                    ->selectRaw('COALESCE(SUM(views_count), 0)')
                    ->whereColumn('user_id', 'users.id')
                    ->where('is_draft', false)
                    ->where('moderation_status', 'visible'),
            ])
            ->whereHas('videos', fn (Builder $videos) => $videos
                ->where('is_draft', false)
                ->where('moderation_status', 'visible'))
            ->when($query !== '', function (Builder $users) use ($query): void {
                $users->where(function (Builder $nested) use ($query): void {
                    $nested->where('name', 'like', '%'.$query.'%')
                        ->orWhere('username', 'like', '%'.$query.'%')
                        ->orWhere('bio', 'like', '%'.$query.'%');
                });
            })
            ->when($categoryId, fn (Builder $users) => $users->whereHas('videos', fn (Builder $videos) => $videos
                ->where('category_id', $categoryId)
                ->where('is_draft', false)
                ->where('moderation_status', 'visible')))
            ->when($verifiedOnly, fn (Builder $users) => $users->where('creator_verification_status', 'approved'))
            ->when($minSubscribers > 0, fn (Builder $users) => $users->has('subscribers', '>=', $minSubscribers))
            ->when($hasActivePlans, fn (Builder $users) => $users->whereHas('creatorPlans', fn (Builder $plans) => $plans->where('is_active', true)))
            ->orderByDesc('published_views_count')
            ->orderByDesc('subscribers_count')
            ->latest('users.id');
    }
}