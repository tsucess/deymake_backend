<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use App\Http\Resources\UserWebhookResource;
use App\Models\UserWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

class DeveloperController extends Controller
{
    private const WEBHOOK_EVENTS = [
        'membership.created',
        'membership.cancelled',
        'membership.plan.updated',
        'payout.request.created',
        'payout.request.updated',
        'collaboration.invite.created',
        'collaboration.invite.updated',
        'collaboration.deliverable.created',
        'collaboration.deliverable.updated',
    ];

    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $apiKeys = $user->tokens()->latest()->get();
        $webhooks = $user->webhooks()->latest()->get();

        return response()->json([
            'message' => __('messages.developer.overview_retrieved'),
            'data' => [
                'developer' => [
                    'availableEvents' => self::WEBHOOK_EVENTS,
                    'apiKeys' => ApiKeyResource::collection($apiKeys),
                    'webhooks' => UserWebhookResource::collection($webhooks),
                    'summary' => [
                        'apiKeysCount' => $apiKeys->count(),
                        'webhooksCount' => $webhooks->count(),
                        'activeWebhooksCount' => $webhooks->where('is_active', true)->count(),
                    ],
                ],
            ],
        ]);
    }

    public function storeApiKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', 'max:100'],
        ]);

        $token = $request->user()->createToken(
            $validated['name'],
            $validated['abilities'] ?? ['*'],
        );

        $accessToken = $request->user()->tokens()->latest('id')->firstOrFail();

        return response()->json([
            'message' => __('messages.developer.api_key_created'),
            'data' => [
                'apiKey' => new ApiKeyResource($accessToken),
                'plainTextToken' => $token->plainTextToken,
            ],
        ], 201);
    }

    public function destroyApiKey(Request $request, PersonalAccessToken $token): JsonResponse
    {
        abort_if((int) $token->tokenable_id !== (int) $request->user()->id || $token->tokenable_type !== $request->user()::class, 403);

        $token->delete();

        return response()->json([
            'message' => __('messages.developer.api_key_deleted'),
        ]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'targetUrl' => ['required', 'url', 'max:2048'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(self::WEBHOOK_EVENTS)],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $secret = Str::random(40);
        $webhook = $request->user()->webhooks()->create([
            'name' => $validated['name'],
            'target_url' => $validated['targetUrl'],
            'events' => $validated['events'] ?? [],
            'secret' => $secret,
            'is_active' => $validated['isActive'] ?? true,
        ]);

        return response()->json([
            'message' => __('messages.developer.webhook_created'),
            'data' => [
                'webhook' => new UserWebhookResource($webhook),
                'secret' => $secret,
            ],
        ], 201);
    }

    public function updateWebhook(Request $request, UserWebhook $webhook): JsonResponse
    {
        $this->ensureOwner($request, $webhook);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'targetUrl' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['string', Rule::in(self::WEBHOOK_EVENTS)],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        $webhook->forceFill([
            'name' => $validated['name'] ?? $webhook->name,
            'target_url' => $validated['targetUrl'] ?? $webhook->target_url,
            'events' => array_key_exists('events', $validated) ? $validated['events'] : $webhook->events,
            'is_active' => $validated['isActive'] ?? $webhook->is_active,
        ])->save();

        return response()->json([
            'message' => __('messages.developer.webhook_updated'),
            'data' => [
                'webhook' => new UserWebhookResource($webhook),
            ],
        ]);
    }

    public function rotateWebhookSecret(Request $request, UserWebhook $webhook): JsonResponse
    {
        $this->ensureOwner($request, $webhook);

        $secret = Str::random(40);
        $webhook->forceFill(['secret' => $secret])->save();

        return response()->json([
            'message' => __('messages.developer.webhook_secret_rotated'),
            'data' => [
                'webhook' => new UserWebhookResource($webhook),
                'secret' => $secret,
            ],
        ]);
    }

    public function destroyWebhook(Request $request, UserWebhook $webhook): JsonResponse
    {
        $this->ensureOwner($request, $webhook);
        $webhook->delete();

        return response()->json([
            'message' => __('messages.developer.webhook_deleted'),
        ]);
    }

    private function ensureOwner(Request $request, UserWebhook $webhook): void
    {
        abort_if($webhook->user_id !== $request->user()->id, 403);
    }
}