<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\GetCreatorAnalyticsRequest;
use App\Models\Video;
use App\Services\CreatorAnalyticsService;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;

/**
 * Creator analytics controller.
 *
 * Aggregated metrics for the authenticated creator: overall dashboard
 * numbers and per-video breakdowns. Numbers are computed by
 * CreatorAnalyticsService.
 *
 * Routes: GET /me/analytics, GET /me/analytics/videos/{video}.
 * Frontend consumers: pages/CreatorDashboard.jsx, PostLiveAnalytics.jsx.
 * Related: CreatorAnalyticsService.
 * See PROJECT_OVERVIEW.md §3.15 for the full data-flow map.
 */
class CreatorAnalyticsController extends Controller
{
    public function dashboard(
        GetCreatorAnalyticsRequest $request,
        CreatorAnalyticsService $creatorAnalyticsService,
    ): JsonResponse {
        SupportedLocales::apply($request);

        return response()->json([
            'message' => __('messages.analytics.dashboard_retrieved'),
            'data' => $creatorAnalyticsService->dashboard(
                $request->user(),
                (string) ($request->validated('period') ?? '30d'),
                (int) ($request->validated('limit') ?? 5),
            ),
        ]);
    }

    public function showVideo(
        GetCreatorAnalyticsRequest $request,
        Video $video,
        CreatorAnalyticsService $creatorAnalyticsService,
    ): JsonResponse {
        SupportedLocales::apply($request);
        abort_if($video->user_id !== $request->user()->id, 403);

        return response()->json([
            'message' => __('messages.analytics.video_retrieved'),
            'data' => $creatorAnalyticsService->video(
                $request->user(),
                $video,
                (string) ($request->validated('period') ?? '30d'),
            ),
        ]);
    }
}