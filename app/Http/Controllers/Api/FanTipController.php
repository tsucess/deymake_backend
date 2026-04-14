<?php

namespace App\Http\Controllers\Api;

use App\Events\LiveEngagementCreated;
use App\Http\Controllers\Controller;
use App\Http\Resources\FanTipResource;
use App\Http\Resources\VideoResource;
use App\Models\FanTip;
use App\Models\User;
use App\Models\Video;
use App\Services\WalletLedgerService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FanTipController extends Controller
{
    public function store(Request $request, User $creator, WalletLedgerService $walletLedgerService): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($creator->id === $request->user()->id, 422, __('messages.fan_tips.cannot_tip_self'));

        $tip = $this->createTip(
            $request,
            $creator,
            $walletLedgerService,
            $this->validateTipPayload($request)
        );

        $this->loadTipRelations($tip, $request);

        return response()->json([
            'message' => __('messages.fan_tips.created'),
            'data' => [
                'tip' => new FanTipResource($tip),
            ],
        ], 201);
    }

    public function storeLive(Request $request, Video $video, WalletLedgerService $walletLedgerService): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($video->type !== 'video', 422, __('messages.videos.only_video_can_go_live'));
        abort_if(! $video->is_live, 409, __('messages.videos.live_not_active'));

        $creator = $video->user;

        abort_if(! $creator || $creator->id === $request->user()->id, 422, __('messages.fan_tips.cannot_tip_self'));

        $validated = $this->validateTipPayload($request, true);
        $tip = $this->createTip($request, $creator, $walletLedgerService, $validated, $video);
        $this->loadTipRelations($tip, $request);

        $video = Video::query()
            ->withApiResourceData($request->user())
            ->findOrFail($video->id);

        $engagement = $this->formatLiveTipEngagement($tip, true);

        LiveEngagementCreated::dispatch($video->id, $engagement, null, [
            'liveLikes' => (int) ($video->live_like_events_count ?? 0),
            'liveComments' => (int) ($video->live_comments_count ?? 0),
            'liveTips' => (int) ($video->live_tips_count ?? 0),
            'liveTipsAmount' => (int) ($video->live_tips_amount ?? 0),
        ]);

        return response()->json([
            'message' => __('messages.fan_tips.live_created'),
            'data' => [
                'tip' => new FanTipResource($tip),
                'engagement' => $engagement,
                'video' => new VideoResource($video),
            ],
        ], 201);
    }

    public function sent(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $tips = FanTip::query()
            ->where('fan_id', $request->user()->id)
            ->with([
                'fan' => fn ($query) => $query->withProfileAggregates($request->user()),
                'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
                'video',
            ])
            ->latest('tipped_at')
            ->get();

        return response()->json([
            'message' => __('messages.fan_tips.sent_retrieved'),
            'data' => [
                'tips' => FanTipResource::collection($tips),
            ],
        ]);
    }

    public function received(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $tips = FanTip::query()
            ->where('creator_id', $request->user()->id)
            ->with([
                'fan' => fn ($query) => $query->withProfileAggregates($request->user()),
                'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
                'video',
            ])
            ->latest('tipped_at')
            ->get();

        return response()->json([
            'message' => __('messages.fan_tips.received_retrieved'),
            'data' => [
                'tips' => FanTipResource::collection($tips),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTipPayload(Request $request, bool $allowLiveMetadata = false): array
    {
        return $request->validate([
            'amount' => ['required', 'integer', 'min:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'message' => ['nullable', 'string', 'max:280'],
            'isPrivate' => ['sometimes', 'boolean'],
            'giftName' => [$allowLiveMetadata ? 'nullable' : 'prohibited', 'string', 'max:80'],
            'giftType' => [$allowLiveMetadata ? 'nullable' : 'prohibited', 'string', 'max:40'],
            'giftCount' => [$allowLiveMetadata ? 'nullable' : 'prohibited', 'integer', 'min:1', 'max:99'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createTip(Request $request, User $creator, WalletLedgerService $walletLedgerService, array $validated, ?Video $video = null): FanTip
    {
        $metadata = array_filter([
            'context' => $video ? 'live_video' : null,
            'giftName' => $validated['giftName'] ?? null,
            'giftType' => $validated['giftType'] ?? null,
            'giftCount' => $validated['giftCount'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $tip = FanTip::query()->create([
            'creator_id' => $creator->id,
            'fan_id' => $request->user()->id,
            'video_id' => $video?->id,
            'amount' => (int) $validated['amount'],
            'currency' => strtoupper((string) ($validated['currency'] ?? 'NGN')),
            'status' => 'posted',
            'message' => $validated['message'] ?? null,
            'is_private' => $validated['isPrivate'] ?? false,
            'metadata' => $metadata === [] ? null : $metadata,
            'tipped_at' => now(),
        ]);

        $walletLedgerService->recordCredit(
            $creator,
            'fan_tip_credit',
            (int) $tip->amount,
            $tip->currency,
            $video ? 'Live fan tip received.' : 'Fan tip received.',
            array_filter([
                'tipId' => $tip->id,
                'fanId' => $request->user()->id,
                'videoId' => $video?->id,
                'isLive' => $video !== null,
                ...($metadata === [] ? [] : $metadata),
            ], static fn ($value) => $value !== null),
            $tip->tipped_at,
        );

        UserNotifier::sendTranslated(
            $creator->id,
            $request->user()->id,
            'fan_tip_received',
            'messages.notifications.fan_tip_title',
            'messages.notifications.fan_tip_body',
            ['name' => $request->user()->name, 'amount' => $tip->amount, 'currency' => $tip->currency],
            array_filter([
                'fanTipId' => $tip->id,
                'videoId' => $video?->id,
                'amount' => $tip->amount,
                'currency' => $tip->currency,
                'isLive' => $video !== null,
            ], static fn ($value) => $value !== null),
        );

        DeveloperWebhookDispatcher::dispatch($creator, 'tip.received', [
            'type' => 'tip.received',
            'tipId' => $tip->id,
            'fanId' => $request->user()->id,
            'videoId' => $video?->id,
            'isLive' => $video !== null,
            'amount' => $tip->amount,
            'currency' => $tip->currency,
            'metadata' => $metadata,
        ]);

        return $tip;
    }

    private function loadTipRelations(FanTip $tip, Request $request): void
    {
        $tip->load([
            'fan' => fn ($query) => $query->withProfileAggregates($request->user()),
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'video',
        ]);
    }

    private function formatLiveTipEngagement(FanTip $tip, bool $maskPrivateActor = false): array
    {
        $giftCount = (int) data_get($tip->metadata, 'giftCount', 1);
        $giftName = data_get($tip->metadata, 'giftName');

        return [
            'id' => 'tip-'.$tip->id,
            'type' => 'tip',
            'body' => $tip->message,
            'createdAt' => $tip->tipped_at?->toISOString() ?? $tip->created_at?->toISOString(),
            'actor' => $maskPrivateActor && $tip->is_private ? [
                'id' => null,
                'fullName' => 'Anonymous fan',
                'username' => null,
                'avatarUrl' => null,
            ] : [
                'id' => $tip->fan?->id,
                'fullName' => $tip->fan?->name,
                'username' => $tip->fan?->username,
                'avatarUrl' => $tip->fan?->avatar_url,
            ],
            'metadata' => [
                'amount' => (int) $tip->amount,
                'currency' => $tip->currency,
                'giftName' => $giftName,
                'giftType' => data_get($tip->metadata, 'giftType'),
                'giftCount' => $giftCount,
                'isPrivate' => (bool) $tip->is_private,
            ],
        ];
    }
}