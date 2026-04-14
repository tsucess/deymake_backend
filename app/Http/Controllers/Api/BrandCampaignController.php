<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandCampaignResource;
use App\Http\Resources\TalentDiscoveryResource;
use App\Models\BrandCampaign;
use App\Services\TalentDiscoveryService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandCampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $campaigns = BrandCampaign::query()
            ->where('owner_id', $request->user()->id)
            ->with(['owner' => fn ($query) => $query->withProfileAggregates($request->user())])
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.brand_campaigns.retrieved'),
            'data' => [
                'campaigns' => BrandCampaignResource::collection($campaigns),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $this->validateCampaign($request);
        $campaign = BrandCampaign::query()->create([
            'owner_id' => $request->user()->id,
            'title' => $validated['title'],
            'objective' => $validated['objective'] ?? 'awareness',
            'status' => $validated['status'] ?? 'draft',
            'summary' => $validated['summary'] ?? null,
            'budget_amount' => (int) ($validated['budgetAmount'] ?? 0),
            'currency' => strtoupper((string) ($validated['currency'] ?? 'NGN')),
            'min_subscribers' => (int) ($validated['minSubscribers'] ?? 0),
            'target_categories' => $validated['targetCategories'] ?? [],
            'target_locations' => $validated['targetLocations'] ?? [],
            'deliverables' => $validated['deliverables'] ?? [],
            'starts_at' => $validated['startsAt'] ?? null,
            'ends_at' => $validated['endsAt'] ?? null,
        ]);

        $campaign->load(['owner' => fn ($query) => $query->withProfileAggregates($request->user())]);
        DeveloperWebhookDispatcher::dispatch($request->user(), 'brand.campaign.updated', [
            'type' => 'brand.campaign.updated',
            'campaignId' => $campaign->id,
            'action' => 'created',
            'status' => $campaign->status,
        ]);

        return response()->json([
            'message' => __('messages.brand_campaigns.created'),
            'data' => [
                'campaign' => new BrandCampaignResource($campaign),
            ],
        ], 201);
    }

    public function update(Request $request, BrandCampaign $brandCampaign): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($brandCampaign->owner_id !== $request->user()->id, 403);

        $validated = $this->validateCampaign($request, true);
        $brandCampaign->fill([
            'title' => $validated['title'] ?? $brandCampaign->title,
            'objective' => $validated['objective'] ?? $brandCampaign->objective,
            'status' => $validated['status'] ?? $brandCampaign->status,
            'summary' => array_key_exists('summary', $validated) ? $validated['summary'] : $brandCampaign->summary,
            'budget_amount' => $validated['budgetAmount'] ?? $brandCampaign->budget_amount,
            'currency' => isset($validated['currency']) ? strtoupper((string) $validated['currency']) : $brandCampaign->currency,
            'min_subscribers' => $validated['minSubscribers'] ?? $brandCampaign->min_subscribers,
            'target_categories' => $validated['targetCategories'] ?? $brandCampaign->target_categories,
            'target_locations' => $validated['targetLocations'] ?? $brandCampaign->target_locations,
            'deliverables' => $validated['deliverables'] ?? $brandCampaign->deliverables,
            'starts_at' => $validated['startsAt'] ?? $brandCampaign->starts_at,
            'ends_at' => $validated['endsAt'] ?? $brandCampaign->ends_at,
        ])->save();

        $brandCampaign->load(['owner' => fn ($query) => $query->withProfileAggregates($request->user())]);
        DeveloperWebhookDispatcher::dispatch($request->user(), 'brand.campaign.updated', [
            'type' => 'brand.campaign.updated',
            'campaignId' => $brandCampaign->id,
            'action' => 'updated',
            'status' => $brandCampaign->status,
        ]);

        return response()->json([
            'message' => __('messages.brand_campaigns.updated'),
            'data' => [
                'campaign' => new BrandCampaignResource($brandCampaign),
            ],
        ]);
    }

    public function matches(Request $request, BrandCampaign $brandCampaign, TalentDiscoveryService $talentDiscoveryService): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($brandCampaign->owner_id !== $request->user()->id, 403);

        $filters = [
            'q' => $request->string('q')->toString(),
            'categoryId' => $request->query('categoryId') ?: collect($brandCampaign->target_categories ?? [])->first(),
            'verifiedOnly' => $request->boolean('verifiedOnly'),
            'minSubscribers' => $request->query('minSubscribers', $brandCampaign->min_subscribers),
            'hasActivePlans' => $request->query('hasActivePlans', true),
        ];

        $creators = PaginatedJson::paginate(
            $talentDiscoveryService->query($filters, $request->user()),
            $request
        );

        return response()->json([
            'message' => __('messages.brand_campaigns.matches_retrieved'),
            'data' => [
                'creators' => PaginatedJson::items($request, $creators, TalentDiscoveryResource::class),
            ],
            'meta' => [
                'creators' => PaginatedJson::meta($creators),
            ],
        ]);
    }

    private function validateCampaign(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'title' => [...$required, 'string', 'max:255'],
            'objective' => ['sometimes', 'string', 'max:80'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused', 'closed'])],
            'summary' => ['nullable', 'string'],
            'budgetAmount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'minSubscribers' => ['sometimes', 'integer', 'min:0'],
            'targetCategories' => ['nullable', 'array'],
            'targetCategories.*' => ['integer', 'exists:categories,id'],
            'targetLocations' => ['nullable', 'array'],
            'targetLocations.*' => ['string', 'max:120'],
            'deliverables' => ['nullable', 'array'],
            'startsAt' => ['nullable', 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
        ]);
    }
}