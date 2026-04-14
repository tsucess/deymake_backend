<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RevenueShareAgreementResource;
use App\Http\Resources\RevenueShareSettlementResource;
use App\Models\RevenueShareAgreement;
use App\Models\RevenueShareSettlement;
use App\Models\User;
use App\Services\WalletLedgerService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RevenueShareController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $scope = trim($request->string('scope')->toString());
        $agreements = PaginatedJson::paginate(
            RevenueShareAgreement::query()
                ->with([
                    'owner' => fn ($query) => $query->withProfileAggregates($request->user()),
                    'recipient' => fn ($query) => $query->withProfileAggregates($request->user()),
                    'settlements',
                ])
                ->when($scope === 'owned', fn ($query) => $query->where('owner_id', $request->user()->id))
                ->when($scope === 'inbox', fn ($query) => $query->where('recipient_id', $request->user()->id))
                ->when(! in_array($scope, ['owned', 'inbox'], true), function ($query) use ($request): void {
                    $query->where(function ($nested) use ($request): void {
                        $nested->where('owner_id', $request->user()->id)
                            ->orWhere('recipient_id', $request->user()->id);
                    });
                })
                ->latest('id'),
            $request
        );

        return response()->json([
            'message' => __('messages.revenue_shares.retrieved'),
            'data' => [
                'agreements' => PaginatedJson::items($request, $agreements, RevenueShareAgreementResource::class),
            ],
            'meta' => [
                'agreements' => PaginatedJson::meta($agreements),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'recipientId' => ['required', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'sourceType' => ['nullable', 'string', 'max:40'],
            'sharePercentage' => ['required', 'integer', 'min:1', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        abort_if((int) $validated['recipientId'] === $request->user()->id, 422, __('messages.revenue_shares.cannot_share_with_self'));

        $agreement = RevenueShareAgreement::query()->create([
            'owner_id' => $request->user()->id,
            'recipient_id' => (int) $validated['recipientId'],
            'title' => $validated['title'],
            'source_type' => $validated['sourceType'] ?? 'general',
            'share_percentage' => (int) $validated['sharePercentage'],
            'currency' => strtoupper((string) ($validated['currency'] ?? 'NGN')),
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        UserNotifier::sendTranslated(
            $agreement->recipient_id,
            $request->user()->id,
            'revenue_share_created',
            'messages.notifications.revenue_share_title',
            'messages.notifications.revenue_share_body',
            ['name' => $request->user()->name, 'title' => $agreement->title],
            ['revenueShareAgreementId' => $agreement->id],
        );

        $recipient = User::query()->findOrFail($agreement->recipient_id);
        DeveloperWebhookDispatcher::dispatch($recipient, 'revenue_share.agreement.created', [
            'type' => 'revenue_share.agreement.created',
            'agreementId' => $agreement->id,
            'status' => $agreement->status,
            'ownerId' => $agreement->owner_id,
        ]);

        $agreement->load([
            'owner' => fn ($query) => $query->withProfileAggregates($request->user()),
            'recipient' => fn ($query) => $query->withProfileAggregates($request->user()),
            'settlements',
        ]);

        return response()->json([
            'message' => __('messages.revenue_shares.created'),
            'data' => [
                'agreement' => new RevenueShareAgreementResource($agreement),
            ],
        ], 201);
    }

    public function update(Request $request, RevenueShareAgreement $revenueShareAgreement): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_unless(
            in_array($request->user()->id, [$revenueShareAgreement->owner_id, $revenueShareAgreement->recipient_id], true),
            403
        );

        $validated = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject', 'cancel'])],
        ]);

        $action = $validated['action'];
        if (in_array($action, ['accept', 'reject'], true)) {
            abort_if($request->user()->id !== $revenueShareAgreement->recipient_id, 403);
        }

        if ($action === 'cancel') {
            abort_if($request->user()->id !== $revenueShareAgreement->owner_id, 403);
        }

        if ($action === 'accept') {
            $revenueShareAgreement->forceFill(['status' => 'active', 'accepted_at' => now(), 'rejected_at' => null, 'cancelled_at' => null])->save();
        } elseif ($action === 'reject') {
            $revenueShareAgreement->forceFill(['status' => 'rejected', 'rejected_at' => now()])->save();
        } else {
            $revenueShareAgreement->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        }

        $otherPartyId = $request->user()->id === $revenueShareAgreement->owner_id
            ? $revenueShareAgreement->recipient_id
            : $revenueShareAgreement->owner_id;

        UserNotifier::sendTranslated(
            $otherPartyId,
            $request->user()->id,
            'revenue_share_updated',
            'messages.notifications.revenue_share_title',
            'messages.notifications.revenue_share_updated_body',
            ['status' => $revenueShareAgreement->status, 'title' => $revenueShareAgreement->title],
            ['revenueShareAgreementId' => $revenueShareAgreement->id, 'status' => $revenueShareAgreement->status],
        );

        $otherParty = User::query()->findOrFail($otherPartyId);
        DeveloperWebhookDispatcher::dispatch($otherParty, 'revenue_share.agreement.updated', [
            'type' => 'revenue_share.agreement.updated',
            'agreementId' => $revenueShareAgreement->id,
            'status' => $revenueShareAgreement->status,
            'actorId' => $request->user()->id,
        ]);

        $revenueShareAgreement->load([
            'owner' => fn ($query) => $query->withProfileAggregates($request->user()),
            'recipient' => fn ($query) => $query->withProfileAggregates($request->user()),
            'settlements',
        ]);

        return response()->json([
            'message' => __('messages.revenue_shares.updated'),
            'data' => [
                'agreement' => new RevenueShareAgreementResource($revenueShareAgreement),
            ],
        ]);
    }

    public function storeSettlement(Request $request, RevenueShareAgreement $revenueShareAgreement, WalletLedgerService $walletLedgerService): JsonResponse
    {
        SupportedLocales::apply($request);

        abort_if($request->user()->id !== $revenueShareAgreement->owner_id, 403);
        abort_if($revenueShareAgreement->status !== 'active', 422, __('messages.revenue_shares.agreement_not_active'));

        $validated = $request->validate([
            'grossAmount' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $grossAmount = (int) $validated['grossAmount'];
        $sharedAmount = (int) round(($grossAmount * (int) $revenueShareAgreement->share_percentage) / 100);

        $settlement = RevenueShareSettlement::query()->create([
            'revenue_share_agreement_id' => $revenueShareAgreement->id,
            'created_by' => $request->user()->id,
            'gross_amount' => $grossAmount,
            'shared_amount' => $sharedAmount,
            'currency' => $revenueShareAgreement->currency,
            'share_percentage' => (int) $revenueShareAgreement->share_percentage,
            'notes' => $validated['notes'] ?? null,
            'settled_at' => now(),
        ]);

        $walletLedgerService->recordCredit(
            $revenueShareAgreement->recipient_id,
            'revenue_share_credit',
            $sharedAmount,
            $revenueShareAgreement->currency,
            'Revenue share settlement received.',
            [
                'agreementId' => $revenueShareAgreement->id,
                'settlementId' => $settlement->id,
                'ownerId' => $revenueShareAgreement->owner_id,
            ],
            $settlement->settled_at,
        );

        UserNotifier::sendTranslated(
            $revenueShareAgreement->recipient_id,
            $request->user()->id,
            'revenue_share_settlement_created',
            'messages.notifications.revenue_share_settlement_title',
            'messages.notifications.revenue_share_settlement_body',
            ['amount' => $sharedAmount, 'currency' => $revenueShareAgreement->currency],
            ['revenueShareSettlementId' => $settlement->id],
        );

        $recipient = User::query()->findOrFail($revenueShareAgreement->recipient_id);
        DeveloperWebhookDispatcher::dispatch($recipient, 'revenue_share.settlement.created', [
            'type' => 'revenue_share.settlement.created',
            'agreementId' => $revenueShareAgreement->id,
            'settlementId' => $settlement->id,
            'sharedAmount' => $sharedAmount,
            'currency' => $revenueShareAgreement->currency,
        ]);

        return response()->json([
            'message' => __('messages.revenue_shares.settlement_created'),
            'data' => [
                'settlement' => new RevenueShareSettlementResource($settlement),
            ],
        ], 201);
    }
}