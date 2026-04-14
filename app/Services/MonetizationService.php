<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\WalletTransaction;

class MonetizationService
{
    public function __construct(private readonly WalletLedgerService $walletLedgerService) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $creator): array
    {
        $memberships = Membership::query()
            ->where('creator_id', $creator->id)
            ->get();

        $activeMemberships = $memberships->where('status', 'active');
        $payoutAccount = $creator->payoutAccount;
        $currency = $payoutAccount?->currency
            ?? ($memberships->first()?->currency ?: 'NGN');
        $ledgerTotals = $this->walletLedgerService->totalsFor($creator);
        $legacyGrossRevenue = (int) $memberships->sum('price_amount');
        $grossRevenue = max((int) $ledgerTotals['grossRevenue'], $legacyGrossRevenue);
        $withdrawn = (int) $ledgerTotals['withdrawn'];
        $pendingPayouts = (int) $ledgerTotals['pendingPayouts'];

        return [
            'currency' => $currency,
            'earnings' => [
                'grossRevenue' => $grossRevenue,
                'monthlyRecurringRevenue' => round($activeMemberships->sum(fn (Membership $membership) => $this->monthlyValue($membership)), 2),
                'withdrawn' => $withdrawn,
                'pendingPayouts' => $pendingPayouts,
                'availableBalance' => max(0, $grossRevenue - $withdrawn - $pendingPayouts),
            ],
            'memberships' => [
                'totalCustomers' => $memberships->pluck('member_id')->unique()->count(),
                'activeCustomers' => $activeMemberships->pluck('member_id')->unique()->count(),
                'activeMemberships' => $activeMemberships->count(),
            ],
            'payouts' => [
                'accountReady' => (bool) $payoutAccount,
                'totalRequests' => (int) PayoutRequest::query()->where('user_id', $creator->id)->count(),
                'lastRequestedAt' => PayoutRequest::query()->where('user_id', $creator->id)->latest('requested_at')->value('requested_at'),
            ],
            'ledger' => [
                'transactionsCount' => (int) WalletTransaction::query()->where('user_id', $creator->id)->count(),
                'lastTransactionAt' => $ledgerTotals['lastTransactionAt'],
            ],
        ];
    }

    public function availableBalance(User $creator): int
    {
        return (int) data_get($this->summary($creator), 'earnings.availableBalance', 0);
    }

    public function defaultCurrency(User $creator): string
    {
        return (string) data_get($this->summary($creator), 'currency', 'NGN');
    }

    private function monthlyValue(Membership $membership): float
    {
        $amount = (float) $membership->price_amount;

        return match ($membership->billing_period) {
            'weekly' => round(($amount * 52) / 12, 2),
            'yearly' => round($amount / 12, 2),
            default => $amount,
        };
    }
}