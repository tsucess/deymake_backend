<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;

class WalletLedgerService
{
    public function recordMembershipCredit(Membership $membership): WalletTransaction
    {
        return $this->recordCredit(
            $membership->creator_id,
            'membership_credit',
            (int) $membership->price_amount,
            $membership->currency,
            'Membership revenue received.',
            [
                'memberId' => $membership->member_id,
                'planId' => $membership->creator_plan_id,
                'billingPeriod' => $membership->billing_period,
            ],
            $membership->started_at,
            $membership->id,
        );
    }

    /**
     * @param  User|int  $creator
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordCredit(
        User|int $creator,
        string $type,
        int $amount,
        string $currency,
        ?string $description = null,
        ?array $metadata = null,
        ?Carbon $occurredAt = null,
        ?int $membershipId = null,
        ?int $payoutRequestId = null,
    ): WalletTransaction {
        return WalletTransaction::query()->create([
            'user_id' => $creator instanceof User ? $creator->id : $creator,
            'membership_id' => $membershipId,
            'payout_request_id' => $payoutRequestId,
            'type' => $type,
            'direction' => 'credit',
            'status' => 'posted',
            'amount' => max(0, $amount),
            'currency' => strtoupper($currency),
            'description' => $description,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Record a debit against a creator wallet.
     *
     * Used for reversals/refunds (status 'posted') that immediately reduce the
     * available balance, distinct from payout debits which move through the
     * requested/processing/paid lifecycle via syncPayoutTransaction().
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordDebit(
        User|int $user,
        string $type,
        int $amount,
        string $currency,
        string $status = 'posted',
        ?string $description = null,
        ?array $metadata = null,
        ?Carbon $occurredAt = null,
    ): WalletTransaction {
        return WalletTransaction::query()->create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'direction' => 'debit',
            'status' => $status,
            'amount' => max(0, $amount),
            'currency' => strtoupper($currency),
            'description' => $description,
            'metadata' => $metadata,
            'occurred_at' => $occurredAt ?? now(),
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

        $reversals = (int) $transactions
            ->where('direction', 'debit')
            ->where('status', 'posted')
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
            'reversals' => $reversals,
            'pendingPayouts' => $pending,
            'availableBalance' => max(0, $credits - $withdrawn - $reversals - $pending),
            'lastTransactionAt' => $latest?->occurred_at?->toISOString(),
        ];
    }
}