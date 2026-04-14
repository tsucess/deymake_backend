<?php

namespace Tests\Feature;

use App\Models\CreatorPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_revenue_and_rejected_payout_are_reflected_in_wallet_transactions(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create(['name' => 'Ledger Creator', 'username' => 'ledger.creator']);
        $member = User::factory()->create(['name' => 'Ledger Member', 'username' => 'ledger.member']);

        $plan = CreatorPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Backstage Pass',
            'price_amount' => 4000,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'is_active' => true,
        ]);

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/memberships/plans/'.$plan->id.'/subscribe')
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.memberships.created'));

        Sanctum::actingAs($creator);

        $this->putJson('/api/v1/monetization/payout-account', [
            'accountName' => 'Ledger Creator',
            'accountReference' => '1234567890',
            'bankName' => 'Creator Bank',
            'bankCode' => '044',
            'currency' => 'NGN',
        ])->assertOk();

        $payoutResponse = $this->postJson('/api/v1/monetization/payouts', [
            'amount' => 1500,
            'notes' => 'Need working capital.',
        ]);

        $payoutResponse
            ->assertCreated()
            ->assertJsonPath('data.payout.status', 'requested');

        $payoutId = $payoutResponse->json('data.payout.id');

        $this->getJson('/api/v1/monetization/transactions')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.monetization.transactions_retrieved'))
            ->assertJsonCount(2, 'data.transactions');

        $this->getJson('/api/v1/monetization/transactions?type=payout_debit')
            ->assertOk()
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.type', 'payout_debit')
            ->assertJsonPath('data.transactions.0.status', 'requested');

        $this->getJson('/api/v1/monetization/transactions?type=membership_credit')
            ->assertOk()
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.type', 'membership_credit')
            ->assertJsonPath('data.transactions.0.status', 'posted');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/payout-requests/'.$payoutId, [
            'status' => 'rejected',
            'notes' => 'Please confirm payout details.',
            'rejectionReason' => 'Account verification pending.',
        ])
            ->assertOk()
            ->assertJsonPath('data.payout.status', 'rejected');

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/monetization/summary')
            ->assertOk()
            ->assertJsonPath('data.summary.earnings.grossRevenue', 4000)
            ->assertJsonPath('data.summary.earnings.pendingPayouts', 0)
            ->assertJsonPath('data.summary.earnings.withdrawn', 0)
            ->assertJsonPath('data.summary.earnings.availableBalance', 4000)
            ->assertJsonPath('data.summary.ledger.transactionsCount', 2);

        $this->getJson('/api/v1/monetization/transactions?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.payoutRequestId', $payoutId)
            ->assertJsonPath('data.transactions.0.status', 'rejected');

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $creator->id,
            'type' => 'membership_credit',
            'status' => 'posted',
            'amount' => 4000,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $creator->id,
            'type' => 'payout_debit',
            'status' => 'rejected',
            'payout_request_id' => $payoutId,
            'amount' => 1500,
        ]);
    }
}