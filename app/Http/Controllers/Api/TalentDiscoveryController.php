<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TalentDiscoveryResource;
use App\Services\TalentDiscoveryService;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Talent discovery controller.
 *
 * Brand-facing creator search with filtering + scoring. Powered by
 * TalentDiscoveryService.
 *
 * Routes: GET /talent/discovery.
 * Frontend consumers: brand tools via api.getTalentDiscovery.
 * Related: TalentDiscoveryService, TalentDiscoveryResource.
 * See PROJECT_OVERVIEW.md §3.23 for the full data-flow map.
 */
class TalentDiscoveryController extends Controller
{
    public function index(Request $request, TalentDiscoveryService $talentDiscoveryService): JsonResponse
    {
        SupportedLocales::apply($request);

        $viewer = auth('sanctum')->user() ?? $request->user();
        $filters = [
            'q' => $request->string('q')->toString(),
            'categoryId' => $request->query('categoryId'),
            'verifiedOnly' => $request->boolean('verifiedOnly'),
            'minSubscribers' => $request->query('minSubscribers', 0),
            'hasActivePlans' => $request->query('hasActivePlans', false),
        ];

        $creators = PaginatedJson::paginate(
            $talentDiscoveryService->query($filters, $viewer),
            $request,
            12,
            25,
        );

        return response()->json([
            'message' => __('messages.talent_discovery.retrieved'),
            'data' => [
                'creators' => PaginatedJson::items($request, $creators, TalentDiscoveryResource::class),
            ],
            'meta' => [
                'creators' => PaginatedJson::meta($creators),
            ],
        ]);
    }
}