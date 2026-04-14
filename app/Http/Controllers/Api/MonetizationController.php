<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monetization\StorePayoutAccountRequest;
use App\Http\Requests\Monetization\StorePayoutRequestRequest;
use App\Http\Resources\PayoutAccountResource;
use App\Http\Resources\PayoutRequestResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\WalletTransaction;
use App\Services\MonetizationService;
use App\Services\WalletLedgerService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonetizationController extends Controller
{
    public function summary(Request $request, MonetizationService $monetizationService): JsonResponse
    {
        SupportedLocales::apply($request);

        return response()->json([
            'message' => __('messages.monetization.summary_retrieved'),
            'data' => [
                'summary' => $monetizationService->summary($request->user()),
            ],
        ]);
    }

    public function payoutAccount(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $account = $request->user()->payoutAccount;

        return response()->json([
            'message' => __('messages.monetization.payout_account_retrieved'),
            'data' => [
                'account' => $account ? new PayoutAccountResource($account) : null,
            ],
        ]);
    }

    public function upsertPayoutAccount(StorePayoutAccountRequest $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $reference = trim((string) $validated['accountReference']);
        $account = PayoutAccount::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'provider' => $validated['provider'] ?? 'bank_transfer',
                'account_name' => $validated['accountName'],
                'account_reference' => $reference,
                'account_mask' => $this->maskReference($reference),
                'bank_name' => $validated['bankName'] ?? null,
                'bank_code' => $validated['bankCode'] ?? null,
                'currency' => strtoupper((string) ($validated['currency'] ?? 'NGN')),
                'metadata' => $validated['metadata'] ?? null,
            ],
        );

        return response()->json([
            'message' => __('messages.monetization.payout_account_saved'),
            'data' => [
                'account' => new PayoutAccountResource($account),
            ],
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $status = trim($request->string('status')->toString());
        $payouts = PayoutRequest::query()
            ->where('user_id', $request->user()->id)
            ->with(['payoutAccount', 'reviewer'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('requested_at')
            ->get();

        return response()->json([
            'message' => __('messages.monetization.payouts_retrieved'),
            'data' => [
                'payouts' => PayoutRequestResource::collection($payouts),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $status = trim($request->string('status')->toString());
        $type = trim($request->string('type')->toString());
        $transactions = PaginatedJson::paginate(
            WalletTransaction::query()
                ->where('user_id', $request->user()->id)
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->when($type !== '', fn ($query) => $query->where('type', $type))
                ->latest('occurred_at'),
            $request,
            12,
            50,
        );

        return response()->json([
            'message' => __('messages.monetization.transactions_retrieved'),
            'data' => [
                'transactions' => PaginatedJson::items($request, $transactions, WalletTransactionResource::class),
            ],
            'meta' => [
                'transactions' => PaginatedJson::meta($transactions),
            ],
        ]);
    }

    public function requestPayout(
        StorePayoutRequestRequest $request,
        MonetizationService $monetizationService,
        WalletLedgerService $walletLedgerService,
    ): JsonResponse {
        SupportedLocales::apply($request);

        $validated = $request->validated();
        $account = $request->user()->payoutAccount;

        if (($validated['payoutAccountId'] ?? null) !== null) {
            $account = PayoutAccount::query()
                ->where('user_id', $request->user()->id)
                ->find($validated['payoutAccountId']);
        }

        abort_if(! $account, 422, __('messages.monetization.payout_account_required'));

        $availableBalance = $monetizationService->availableBalance($request->user());
        abort_if((int) $validated['amount'] > $availableBalance, 422, __('messages.monetization.insufficient_balance'));

        $payoutRequest = PayoutRequest::query()->create([
            'user_id' => $request->user()->id,
            'payout_account_id' => $account->id,
            'amount' => (int) $validated['amount'],
            'currency' => $account->currency,
            'status' => 'requested',
            'notes' => $validated['notes'] ?? null,
            'requested_at' => now(),
        ]);

        $walletLedgerService->syncPayoutTransaction($payoutRequest);

        $payoutRequest->load(['payoutAccount', 'user']);

        DeveloperWebhookDispatcher::dispatch($request->user(), 'payout.request.created', [
            'type' => 'payout.request.created',
            'payoutRequestId' => $payoutRequest->id,
            'amount' => $payoutRequest->amount,
            'currency' => $payoutRequest->currency,
            'status' => $payoutRequest->status,
        ]);

        return response()->json([
            'message' => __('messages.monetization.payout_requested'),
            'data' => [
                'payout' => new PayoutRequestResource($payoutRequest),
            ],
        ], 201);
    }

    private function maskReference(string $reference): string
    {
        $normalized = preg_replace('/\s+/', '', trim($reference)) ?? '';
        $suffix = substr($normalized, -4);

        return $suffix === '' ? '****' : '****'.$suffix;
    }
}