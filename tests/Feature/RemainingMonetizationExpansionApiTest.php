<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RemainingMonetizationExpansionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_verification_request_can_be_reviewed_by_admin(): void
    {
        $creator = User::factory()->create(['name' => 'Verified Soon', 'username' => 'verified.soon']);
        $admin = User::factory()->admin()->create(['name' => 'Review Admin', 'username' => 'review.admin']);

        Sanctum::actingAs($creator);

        $requestId = $this->postJson('/api/v1/creator-verification', [
            'legalName' => 'Verified Soon Ltd',
            'country' => 'Nigeria',
            'documentType' => 'passport',
            'documentUrl' => 'https://cdn.example.com/passport.pdf',
            'about' => 'Campus creator building a strong audience.',
            'socialLinks' => ['https://instagram.com/verifiedsoon'],
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.creator_verification.submitted'))
            ->assertJsonPath('data.request.status', 'pending')
            ->json('data.request.id');

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/creator-verification-requests?status=pending')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.creator_verification.admin_requests_retrieved'))
            ->assertJsonPath('data.requests.0.id', $requestId)
            ->assertJsonPath('meta.requests.total', 1);

        $this->patchJson('/api/v1/admin/creator-verification-requests/'.$requestId, [
            'status' => 'approved',
            'reviewNotes' => 'Audience and documents confirmed.',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.creator_verification.reviewed'))
            ->assertJsonPath('data.request.status', 'approved');

        $creator->refresh();

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/creator-verification')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.request.status', 'approved');

        $this->assertDatabaseHas('users', [
            'id' => $creator->id,
            'creator_verification_status' => 'approved',
        ]);
    }

    public function test_fan_tips_credit_creator_wallet_and_revenue_shares_can_be_settled(): void
    {
        $creator = User::factory()->create(['name' => 'Main Creator', 'username' => 'main.creator']);
        $fan = User::factory()->create(['name' => 'Big Fan', 'username' => 'big.fan']);
        $collaborator = User::factory()->create(['name' => 'Split Partner', 'username' => 'split.partner']);

        Sanctum::actingAs($fan);

        $this->postJson('/api/v1/creators/'.$creator->id.'/tips', [
            'amount' => 2500,
            'currency' => 'NGN',
            'message' => 'This content helped me a lot.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.fan_tips.created'))
            ->assertJsonPath('data.tip.amount', 2500)
            ->assertJsonPath('data.tip.creator.id', $creator->id);

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/tips/received')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.fan_tips.received_retrieved'))
            ->assertJsonPath('data.tips.0.amount', 2500)
            ->assertJsonPath('data.tips.0.fan.id', $fan->id);

        $this->getJson('/api/v1/monetization/summary')
            ->assertOk()
            ->assertJsonPath('data.summary.earnings.grossRevenue', 2500)
            ->assertJsonPath('data.summary.earnings.availableBalance', 2500);

        $agreementId = $this->postJson('/api/v1/revenue-shares', [
            'recipientId' => $collaborator->id,
            'title' => 'Duet payout split',
            'sourceType' => 'collaboration',
            'sharePercentage' => 40,
            'currency' => 'NGN',
            'notes' => 'Split performance campaign revenue.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.revenue_shares.created'))
            ->assertJsonPath('data.agreement.status', 'pending')
            ->json('data.agreement.id');

        Sanctum::actingAs($collaborator);

        $this->patchJson('/api/v1/revenue-shares/'.$agreementId, ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.revenue_shares.updated'))
            ->assertJsonPath('data.agreement.status', 'active');

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/revenue-shares/'.$agreementId.'/settlements', [
            'grossAmount' => 10000,
            'notes' => 'First payout cycle.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.revenue_shares.settlement_created'))
            ->assertJsonPath('data.settlement.sharedAmount', 4000);

        Sanctum::actingAs($collaborator);

        $this->getJson('/api/v1/monetization/summary')
            ->assertOk()
            ->assertJsonPath('data.summary.earnings.grossRevenue', 4000)
            ->assertJsonPath('data.summary.earnings.availableBalance', 4000);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $creator->id,
            'type' => 'fan_tip_credit',
            'amount' => 2500,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $collaborator->id,
            'type' => 'revenue_share_credit',
            'amount' => 4000,
        ]);
    }
}