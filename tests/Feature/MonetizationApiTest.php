<?php

namespace Tests\Feature;

use App\Models\CreatorPlan;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonetizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_manage_payout_account_request_payout_and_admin_can_mark_it_paid(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Finance Admin', 'username' => 'finance.admin']);
        $creator = User::factory()->create([
            'name' => 'Paid Creator',
            'username' => 'paid.creator',
            'preferences' => ['language' => 'fr'],
        ]);
        $member = User::factory()->create(['name' => 'Supporter', 'username' => 'supporter.one']);

        $plan = CreatorPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Inner Circle',
            'price_amount' => 6500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'is_active' => true,
        ]);

        Membership::query()->create([
            'creator_plan_id' => $plan->id,
            'creator_id' => $creator->id,
            'member_id' => $member->id,
            'status' => 'active',
            'price_amount' => 6500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'started_at' => now()->subDays(3),
        ]);

        Sanctum::actingAs($creator);

        $this->putJson('/api/v1/monetization/payout-account', [
            'provider' => 'bank_transfer',
            'accountName' => 'Paid Creator',
            'accountReference' => '0123456789',
            'bankName' => 'Dey Bank',
            'bankCode' => '058',
            'currency' => 'NGN',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.monetization.payout_account_saved'))
            ->assertJsonPath('data.account.accountName', 'Paid Creator')
            ->assertJsonPath('data.account.accountMask', '****6789');

        $this->getJson('/api/v1/monetization/summary')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.monetization.summary_retrieved'))
            ->assertJsonPath('data.summary.currency', 'NGN')
            ->assertJsonPath('data.summary.earnings.grossRevenue', 6500)
            ->assertJsonPath('data.summary.earnings.availableBalance', 6500)
            ->assertJsonPath('data.summary.memberships.activeCustomers', 1)
            ->assertJsonPath('data.summary.payouts.accountReady', true);

        $payoutResponse = $this->postJson('/api/v1/monetization/payouts', [
            'amount' => 3000,
            'notes' => 'First creator payout request.',
        ]);

        $payoutResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.monetization.payout_requested'))
            ->assertJsonPath('data.payout.amount', 3000)
            ->assertJsonPath('data.payout.currency', 'NGN')
            ->assertJsonPath('data.payout.status', 'requested')
            ->assertJsonPath('data.payout.account.accountMask', '****6789');

        $payoutId = $payoutResponse->json('data.payout.id');

        $this->getJson('/api/v1/monetization/summary')
            ->assertOk()
            ->assertJsonPath('data.summary.earnings.pendingPayouts', 3000)
            ->assertJsonPath('data.summary.earnings.availableBalance', 3500);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/payout-requests?status=requested')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.monetization.admin_payouts_retrieved'))
            ->assertJsonPath('data.payouts.0.id', $payoutId)
            ->assertJsonPath('data.payouts.0.creator.id', $creator->id)
            ->assertJsonPath('meta.payouts.total', 1);

        $this->patchJson('/api/v1/admin/payout-requests/'.$payoutId, [
            'status' => 'paid',
            'notes' => 'Settled to creator bank account.',
            'externalReference' => 'paystack-transfer-001',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.monetization.admin_payout_updated'))
            ->assertJsonPath('data.payout.status', 'paid')
            ->assertJsonPath('data.payout.externalReference', 'paystack-transfer-001')
            ->assertJsonPath('data.payout.reviewer.id', $admin->id);

        $this->assertDatabaseHas('payout_requests', [
            'id' => $payoutId,
            'status' => 'paid',
            'reviewed_by' => $admin->id,
            'external_reference' => 'paystack-transfer-001',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'payout_request_paid',
            'title' => trans('messages.notifications.payout_paid_title', [], 'fr'),
        ]);

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/monetization/payouts')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.monetization.payouts_retrieved'))
            ->assertJsonPath('data.payouts.0.id', $payoutId)
            ->assertJsonPath('data.payouts.0.status', 'paid')
            ->assertJsonPath('data.payouts.0.externalReference', 'paystack-transfer-001');
    }
}