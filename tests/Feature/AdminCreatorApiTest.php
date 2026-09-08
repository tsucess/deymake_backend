<?php

namespace Tests\Feature;

use App\Models\CollaborationInvite;
use App\Models\CreatorPlan;
use App\Models\Membership;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Video;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCreatorApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeVideo(User $user, array $attributes = []): Video
    {
        return Video::query()->create(array_merge([
            'user_id' => $user->id,
            'type' => 'video',
            'title' => 'Clip',
            'media_url' => 'https://cdn.example.com/clip.mp4',
            'thumbnail_url' => 'https://cdn.example.com/clip.jpg',
            'is_draft' => false,
            'views_count' => 1000,
        ], $attributes));
    }

    private function makeCreator(array $attributes = [], int $views = 1000): User
    {
        $creator = User::factory()->create($attributes);
        $this->makeVideo($creator, ['views_count' => $views]);

        return $creator;
    }

    public function test_non_admin_users_cannot_access_admin_creator_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/creators')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.admin.access_denied'));
    }

    public function test_admin_can_list_creators_with_summary(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeCreator(['name' => 'Ada Lovelace', 'creator_verification_status' => 'approved']);
        $this->makeCreator(['name' => 'Grace Hopper', 'creator_verification_status' => 'pending']);
        // A non-creator (no videos) should be excluded.
        User::factory()->create(['name' => 'No Videos']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/creators')->assertOk();

        $response->assertJsonPath('meta.summary.total', 2);
        $response->assertJsonPath('meta.summary.verified', 1);
        $response->assertJsonPath('meta.summary.pending', 1);
        $this->assertCount(2, $response->json('data.creators'));
    }

    public function test_admin_can_search_and_filter_creators(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeCreator(['name' => 'Distinctive Creator', 'creator_verification_status' => 'approved', 'country_code' => 'NG']);
        $this->makeCreator(['name' => 'Another Person', 'creator_verification_status' => 'pending', 'country_code' => 'US']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/creators?q=Distinctive')
            ->assertOk()
            ->assertJsonCount(1, 'data.creators')
            ->assertJsonPath('data.creators.0.fullName', 'Distinctive Creator');

        $this->getJson('/api/v1/admin/creators?verificationStatus=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.creators')
            ->assertJsonPath('data.creators.0.fullName', 'Another Person');

        $this->getJson('/api/v1/admin/creators?country=US')
            ->assertOk()
            ->assertJsonCount(1, 'data.creators')
            ->assertJsonPath('data.creators.0.country', 'US');
    }

    public function test_admin_creators_list_is_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (range(1, 5) as $i) {
            $this->makeCreator(['name' => "Creator {$i}"]);
        }

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/creators?per_page=2')->assertOk();

        $this->assertCount(2, $response->json('data.creators'));
        $response->assertJsonPath('meta.creators.perPage', 2);
        $response->assertJsonPath('meta.creators.total', 5);
        $response->assertJsonPath('meta.creators.lastPage', 3);
    }

    public function test_admin_can_retrieve_creator_analytics(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeCreator(['name' => 'Analytics Creator']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/creators/analytics')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.creator_analytics_retrieved'))
            ->assertJsonStructure([
                'data' => [
                    'growth' => ['labels', 'newCreators', 'activeCreators'],
                    'performance' => ['labels', 'views', 'engagements', 'engagementRate'],
                    'categories',
                    'engagementDistribution' => ['high', 'medium', 'low'],
                    'topCreators',
                    'totalViews',
                ],
                'meta' => ['summary', 'dateRange'],
            ]);
    }

    public function test_admin_can_retrieve_creator_monetization(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = $this->makeCreator(['name' => 'Earner']);

        WalletTransaction::query()->create([
            'user_id' => $creator->id,
            'type' => 'membership_credit',
            'direction' => 'credit',
            'status' => 'posted',
            'amount' => 500000,
            'currency' => 'NGN',
        ]);

        PayoutRequest::query()->create([
            'user_id' => $creator->id,
            'amount' => 200000,
            'currency' => 'NGN',
            'status' => 'requested',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/creators/monetization')->assertOk();

        $response->assertJsonPath('data.summary.totalEarnings', 500000);
        $response->assertJsonPath('data.summary.membershipRevenue', 500000);
        $response->assertJsonPath('data.summary.pendingPayoutAmount', 200000);
        $response->assertJsonPath('data.summary.pendingPayoutCount', 1);
        $this->assertNotEmpty($response->json('data.topEarners'));
        $this->assertNotEmpty($response->json('data.recentPayouts'));
    }

    public function test_admin_can_retrieve_creator_programs(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = $this->makeCreator(['name' => 'Plan Owner']);

        $plan = CreatorPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Gold Tier',
            'price_amount' => 100000,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'benefits' => ['Exclusive videos', 'Badge'],
            'is_active' => true,
        ]);

        $member = User::factory()->create();
        Membership::query()->create([
            'creator_plan_id' => $plan->id,
            'creator_id' => $creator->id,
            'member_id' => $member->id,
            'status' => 'active',
            'price_amount' => 100000,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/creators/programs')->assertOk();

        $response->assertJsonPath('data.summary.totalPlans', 1);
        $response->assertJsonPath('data.summary.activePlans', 1);
        $response->assertJsonPath('data.summary.activeMemberships', 1);
        $response->assertJsonPath('data.plans.0.name', 'Gold Tier');
        $response->assertJsonPath('data.plans.0.activeMemberships', 1);
        $this->assertNotEmpty($response->json('data.benefits'));
        $this->assertNotEmpty($response->json('data.onboarding'));
    }

    public function test_admin_can_retrieve_and_filter_collaborations(): void
    {
        $admin = User::factory()->admin()->create();
        $inviter = $this->makeCreator(['name' => 'Inviter']);
        $invitee = $this->makeCreator(['name' => 'Invitee']);
        $video = $inviter->videos()->first();

        CollaborationInvite::query()->create([
            'inviter_id' => $inviter->id,
            'invitee_id' => $invitee->id,
            'source_video_id' => $video->id,
            'type' => 'duet',
            'status' => 'pending',
        ]);
        CollaborationInvite::query()->create([
            'inviter_id' => $inviter->id,
            'invitee_id' => $invitee->id,
            'source_video_id' => $video->id,
            'type' => 'stitch',
            'status' => 'accepted',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/creators/collaborations')->assertOk();
        $response->assertJsonPath('meta.summary.total', 2);
        $response->assertJsonPath('meta.summary.pending', 1);
        $response->assertJsonPath('meta.summary.accepted', 1);
        $this->assertCount(2, $response->json('data.collaborations'));

        $this->getJson('/api/v1/admin/creators/collaborations?status=accepted')
            ->assertOk()
            ->assertJsonCount(1, 'data.collaborations')
            ->assertJsonPath('data.collaborations.0.status', 'accepted');
    }

    public function test_admin_can_export_creators_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeCreator(['name' => 'CSV Creator', 'username' => 'csv.creator']);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/v1/admin/creators/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('ID,Name,Username', $content);
        $this->assertStringContainsString('CSV Creator', $content);
    }
}
