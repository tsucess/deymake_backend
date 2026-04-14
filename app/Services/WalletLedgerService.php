<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\WalletTransaction;

class WalletLedgerService
{
    public function recordMembershipCredit(Membership $membership): WalletTransaction
    {
        return WalletTransaction::query()->create([
            'user_id' => $membership->creator_id,
            'membership_id' => $membership->id,
            'type' => 'membership_credit',
            'direction' => 'credit',
            'status' => 'posted',
            'amount' => (int) $membership->price_amount,
            'currency' => $membership->currency,
            'description' => 'Membership revenue received.',
            'metadata' => [
                'memberId' => $membership->member_id,
                'planId' => $membership->creator_plan_id,
                'billingPeriod' => $membership->billing_period,
            ],
            'occurred_at' => $membership->started_at ?? now(),
        ]);
    }

    public function syncPayoutTransaction(PayoutRequest $payoutRequest): WalletTransaction
    {
        return WalletTransaction::query()->updateOrCreate(
            [
                'payout_request_id' => $payoutRequest->id,
                'type' => 'payout_debit',
            ],
            [
                'user_id' => $payoutRequest->user_id,
                'direction' => 'debit',
                'status' => $payoutRequest->status,
                'amount' => (int) $payoutRequest->amount,
                'currency' => $payoutRequest->currency,
                'description' => 'Creator payout request.',
                'metadata' => [
                    'payoutAccountId' => $payoutRequest->payout_account_id,
                    'reviewedBy' => $payoutRequest->reviewed_by,
                ],
                'occurred_at' => $payoutRequest->processed_at
                    ?? $payoutRequest->reviewed_at
                    ?? $payoutRequest->requested_at
                    ?? now(),
            ],
        );
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function totalsFor(User $creator): array
    {
        $transactions = WalletTransaction::query()
            ->where('user_id', $creator->id)
            ->get();

        $credits = (int) $transactions
            ->where('direction', 'credit')
            ->where('status', 'posted')
            ->sum('amount');

        $withdrawn = (int) $transactions
            ->where('direction', 'debit')
            ->where('status', 'paid')
            ->sum('amount');

        $pending = (int) $transactions
            ->where('direction', 'debit')
            ->whereIn('status', ['requested', 'processing'])
            ->sum('amount');

        $latest = $transactions
            ->sortByDesc(fn (WalletTransaction $transaction) => optional($transaction->occurred_at)->timestamp ?? 0)
            ->first();

        return [
            'grossRevenue' => $credits,
            'withdrawn' => $withdrawn,
            'pendingPayouts' => $pending,
            'availableBalance' => max(0, $credits - $withdrawn - $pending),
            'lastTransactionAt' => $latest?->occurred_at?->toISOString(),
        ];
    }
}