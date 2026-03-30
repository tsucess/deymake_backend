<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class LeaderboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $period = $request->query('period', 'daily');
        $since = $this->since($period);

        $scoredUsers = User::query()
            ->withProfileAggregates()
            ->with(['videos' => function ($query) use ($since): void {
                $query->where('is_draft', false);

                if ($since) {
                    $query->where('created_at', '>=', $since);
                }
            }])
            ->get()
            ->map(function (User $user): array {
                $score = (int) $user->videos->sum('views_count');

                return [
                    'user' => $user,
                    'score' => $score,
                    'videosCount' => $user->videos->count(),
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->map(function (array $item, int $index): array {
                return [
                    'userId' => $item['user']->id,
                    'rank' => $index + 1,
                    'score' => $item['score'],
                    'videosCount' => $item['videosCount'],
                    'trend' => 'steady',
                    'user' => new ProfileResource($item['user']),
                ];
            })
            ->values();

        $currentUserRank = null;

        if ($request->user()) {
            $currentUserRank = $scoredUsers->firstWhere('userId', $request->user()->id);
        }

        return response()->json([
            'message' => __('messages.leaderboard.retrieved'),
            'data' => [
                'period' => $period,
                'podium' => $scoredUsers->take(3)->values(),
                'standings' => $scoredUsers->take(20)->values(),
                'currentUserRank' => $currentUserRank,
            ],
        ]);
    }

    private function since(string $period): ?Carbon
    {
        return match ($period) {
            'daily' => now()->subDay(),
            'weekly' => now()->subWeek(),
            'monthly' => now()->subMonth(),
            default => now()->subDay(),
        };
    }
}