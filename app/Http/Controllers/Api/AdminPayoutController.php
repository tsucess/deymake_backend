<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePayoutRequestStatusRequest;
use App\Http\Resources\PayoutRequestResource;
use App\Models\PayoutRequest;
use App\Services\WalletLedgerService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $status = trim($request->string('status')->toString());
        $payouts = PaginatedJson::paginate(
            PayoutRequest::query()
                ->with(['payoutAccount', 'user', 'reviewer'])
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->latest('requested_at'),
            $request,
            12,
            50,
        );

        return response()->json([
            'message' => __('messages.monetization.admin_payouts_retrieved'),
            'data' => [
                'payouts' => PaginatedJson::items($request, $payouts, PayoutRequestResource::class),
            ],
            'meta' => [
                'payouts' => PaginatedJson::meta($payouts),
            ],
        ]);
    }

    public function update(
        UpdatePayoutRequestStatusRequest $request,
        PayoutRequest $payoutRequest,
        WalletLedgerService $walletLedgerService,
    ): JsonResponse {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $nextStatus = $validated['status'];

        $payoutRequest->forceFill([
            'status' => $nextStatus,
            'notes' => $validated['notes'] ?? $payoutRequest->notes,
            'rejection_reason' => $validated['rejectionReason'] ?? ($nextStatus === 'rejected' ? $payoutRequest->rejection_reason : null),
            'external_reference' => $validated['externalReference'] ?? $payoutRequest->external_reference,
            'reviewed_by' => $nextStatus === 'requested' ? null : $request->user()->id,
            'reviewed_at' => $nextStatus === 'requested' ? null : now(),
            'processed_at' => $nextStatus === 'paid' ? now() : null,
        ])->save();

        $payoutRequest->load(['payoutAccount', 'user', 'reviewer']);
        $walletLedgerService->syncPayoutTransaction($payoutRequest);

        $this->notifyCreator($payoutRequest, $request->user()->id);

        DeveloperWebhookDispatcher::dispatch($payoutRequest->user, 'payout.request.updated', [
            'type' => 'payout.request.updated',
            'payoutRequestId' => $payoutRequest->id,
            'amount' => $payoutRequest->amount,
            'currency' => $payoutRequest->currency,
            'status' => $payoutRequest->status,
            'reviewedBy' => $request->user()->id,
        ]);

        return response()->json([
            'message' => __('messages.monetization.admin_payout_updated'),
            'data' => [
                'payout' => new PayoutRequestResource($payoutRequest),
            ],
        ]);
    }

    private function notifyCreator(PayoutRequest $payoutRequest, int $actorId): void
    {
        match ($payoutRequest->status) {
            'processing' => UserNotifier::sendTranslated(
                $payoutRequest->user_id,
                $actorId,
                'payout_request_processing',
                'messages.notifications.payout_processing_title',
                'messages.notifications.payout_processing_body',
                ['amount' => $payoutRequest->amount, 'currency' => $payoutRequest->currency],
                ['payoutRequestId' => $payoutRequest->id]
            ),
            'paid' => UserNotifier::sendTranslated(
                $payoutRequest->user_id,
                $actorId,
                'payout_request_paid',
                'messages.notifications.payout_paid_title',
                'messages.notifications.payout_paid_body',
                ['amount' => $payoutRequest->amount, 'currency' => $payoutRequest->currency],
                ['payoutRequestId' => $payoutRequest->id]
            ),
            'rejected' => UserNotifier::sendTranslated(
                $payoutRequest->user_id,
                $actorId,
                'payout_request_rejected',
                'messages.notifications.payout_rejected_title',
                'messages.notifications.payout_rejected_body',
                ['amount' => $payoutRequest->amount, 'currency' => $payoutRequest->currency],
                ['payoutRequestId' => $payoutRequest->id]
            ),
            default => null,
        };
    }
}