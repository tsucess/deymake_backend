<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Health probe controller.
 *
 * Cheap uptime endpoint used by load balancers and monitoring.
 *
 * Routes: GET /health.
 * See PROJECT_OVERVIEW.md §3.28 for the full data-flow map.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => __('messages.health.healthy'),
            'data' => [
                'status' => 'ok',
                'app' => config('app.name'),
                'timestamp' => now()->toISOString(),
            ],
        ]);
    }
}