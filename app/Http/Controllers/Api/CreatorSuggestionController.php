<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreatorSuggestionController extends Controller
{
    public function suggestions(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = $request->user();
        $limit = (int) $request->integer('limit', 10);
        $limit = max(1, min($limit, 30));

        $followedCreatorIds = $viewer->subscribedCreators()->pluck('users.id')->all();
        $excludedIds = array_merge([$viewer->id], $followedCreatorIds);

        $suggestedIds = [];

        if (! empty($followedCreatorIds)) {
            $suggestedIds = DB::table('subscriptions')
                ->whereIn('user_id', $followedCreatorIds)
                ->whereNotIn('creator_id', $excludedIds)
                ->select('creator_id', DB::raw('COUNT(*) as mutual_count'))
                ->groupBy('creator_id')
                ->orderByDesc('mutual_count')
                ->limit($limit)
                ->pluck('creator_id')
                ->all();
        }

        if (count($suggestedIds) < $limit) {
            $fallbackNeeded = $limit - count($suggestedIds);
            $fallbackExcluded = array_merge($excludedIds, $suggestedIds);
            $fallbackIds = User::query()
                ->whereNotIn('id', $fallbackExcluded)
                ->withCount('subscribers')
                ->orderByDesc('subscribers_count')
                ->orderByDesc('last_active_at')
                ->limit($fallbackNeeded)
                ->pluck('id')
                ->all();
            $suggestedIds = array_merge($suggestedIds, $fallbackIds);
        }

        $creators = collect();
        if (! empty($suggestedIds)) {
            $fetched = User::query()
                ->whereIn('id', $suggestedIds)
                ->withProfileAggregates($viewer)
                ->get()
                ->keyBy('id');

            $creators = collect($suggestedIds)
                ->map(fn ($id) => $fetched->get($id))
                ->filter()
                ->values();
        }

        return response()->json([
            'message' => __('messages.creators.suggestions_retrieved'),
            'data' => [
                'creators' => ProfileResource::collection($creators),
            ],
        ]);
    }
}
