<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TalentDiscoveryResource;
use App\Services\TalentDiscoveryService;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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