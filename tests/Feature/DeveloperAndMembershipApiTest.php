<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeveloperAndMembershipApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_manage_developer_api_keys_and_webhooks(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/developer')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.developer.overview_retrieved'))
            ->assertJsonPath('data.developer.summary.apiKeysCount', 0)
            ->assertJsonPath('data.developer.summary.webhooksCount', 0);

        $apiKeyResponse = $this->postJson('/api/v1/developer/api-keys', [
            'name' => 'Creator SDK',
            'abilities' => ['memberships:read', 'memberships:write'],
        ]);

        $apiKeyResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.developer.api_key_created'))
            ->assertJsonPath('data.apiKey.name', 'Creator SDK');

        $tokenId = $apiKeyResponse->json('data.apiKey.id');

        $webhookResponse = $this->postJson('/api/v1/developer/webhooks', [
            'name' => 'Membership events',
            'targetUrl' => 'https://example.com/hooks/memberships',
            'events' => ['membership.created', 'membership.cancelled'],
            'isActive' => true,
        ]);

        $webhookResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.developer.webhook_created'))
            ->assertJsonPath('data.webhook.targetUrl', 'https://example.com/hooks/memberships');

        $webhookId = $webhookResponse->json('data.webhook.id');

        $this->getJson('/api/v1/developer')
            ->assertOk()
            ->assertJsonPath('data.developer.availableEvents.0', 'membership.created')
            ->assertJsonPath('data.developer.availableEvents.1', 'membership.cancelled')
            ->assertJsonPath('data.developer.availableEvents.2', 'membership.plan.updated')
            ->assertJsonPath('data.developer.apiKeys.0.name', 'Creator SDK')
            ->assertJsonPath('data.developer.apiKeys.0.abilities.0', 'memberships:read')
            ->assertJsonPath('data.developer.webhooks.0.name', 'Membership events')
            ->assertJsonPath('data.developer.webhooks.0.targetUrl', 'https://example.com/hooks/memberships')
            ->assertJsonPath('data.developer.webhooks.0.events.0', 'membership.created')
            ->assertJsonPath('data.developer.webhooks.0.hasSecret', true)
            ->assertJsonPath('data.developer.summary.apiKeysCount', 1)
            ->assertJsonPath('data.developer.summary.webhooksCount', 1)
            ->assertJsonPath('data.developer.summary.activeWebhooksCount', 1);

        $this->patchJson('/api/v1/developer/webhooks/'.$webhookId, [
            'name' => 'Membership updates',
            'isActive' => false,
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.developer.webhook_updated'))
            ->assertJsonPath('data.webhook.name', 'Membership updates')
            ->assertJsonPath('data.webhook.isActive', false);

        $this->postJson('/api/v1/developer/webhooks/'.$webhookId.'/rotate-secret')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.developer.webhook_secret_rotated'));

        $this->deleteJson('/api/v1/developer/api-keys/'.$tokenId)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.developer.api_key_deleted'));

        $this->deleteJson('/api/v1/developer/webhooks/'.$webhookId)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.developer.webhook_deleted'));

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('user_webhooks', 0);
    }

    public function test_creator_membership_plans_can_be_managed_and_subscribed_to(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 202)]);

        $creator = User::factory()->create([
            'name' => 'Creator Prime',
            'preferences' => ['language' => 'fr'],
        ]);
        $member = User::factory()->create([
            'name' => 'Member Zero',
            'preferences' => ['language' => 'yo'],
        ]);

        $creator->webhooks()->create([
            'name' => 'Creator app',
            'target_url' => 'https://example.com/developer/webhooks',
            'secret' => 'test-secret',
            'events' => ['membership.created', 'membership.cancelled', 'membership.plan.updated'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($creator);

        $planResponse = $this->postJson('/api/v1/memberships/plans', [
            'name' => 'Gold Circle',
            'description' => 'Premium access',
            'price_amount' => 1500,
            'currency' => 'USD',
            'billing_period' => 'monthly',
            'benefits' => ['Early access', 'Members-only chat'],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $planResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.memberships.plan_created'))
            ->assertJsonPath('data.plan.name', 'Gold Circle');

        $planId = $planResponse->json('data.plan.id');

        $this->patchJson('/api/v1/memberships/plans/'.$planId, [
            'description' => 'Premium access + shoutouts',
            'benefits' => ['Early access', 'Members-only chat', 'Monthly shoutout'],
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.memberships.plan_updated'))
            ->assertJsonPath('data.plan.description', 'Premium access + shoutouts');

        $this->getJson('/api/v1/users/'.$creator->id.'/plans')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.memberships.plans_retrieved'))
            ->assertJsonCount(1, 'data.plans')
            ->assertJsonPath('data.plans.0.name', 'Gold Circle');

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.user.hasActivePlans', true)
            ->assertJsonPath('data.user.activePlansCount', 1);

        $this->getJson('/api/v1/users/'.$creator->id.'/plans')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.memberships.plans_retrieved'))
            ->assertJsonPath('data.plans.0.currentUserMembership', null);

        $subscribeResponse = $this->postJson('/api/v1/memberships/plans/'.$planId.'/subscribe');

        $subscribeResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.memberships.created'))
            ->assertJsonPath('data.membership.status', 'active')
            ->assertJsonPath('data.membership.plan.id', $planId)
            ->assertJsonPath('data.membership.creator.fullName', 'Creator Prime');

        $membershipId = $subscribeResponse->json('data.membership.id');

        $this->getJson('/api/v1/memberships/mine')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.memberships.mine_retrieved'))
            ->assertJsonPath('data.memberships.0.id', $membershipId)
            ->assertJsonPath('data.memberships.0.plan.name', 'Gold Circle')
            ->assertJsonPath('data.memberships.0.creator.fullName', 'Creator Prime')
            ->assertJsonPath('data.memberships.0.priceAmount', 1500)
            ->assertJsonPath('data.memberships.0.billingPeriod', 'monthly');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'membership_created',
            'title' => trans('messages.notifications.membership_title', [], 'fr'),
        ]);

        $this->postJson('/api/v1/memberships/'.$membershipId.'/cancel')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.memberships.cancelled'))
            ->assertJsonPath('data.membership.status', 'cancelled');

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/memberships/creator')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.memberships.creator_dashboard_retrieved'))
            ->assertJsonPath('data.memberships.0.status', 'cancelled')
            ->assertJsonPath('data.memberships.0.member.fullName', 'Member Zero')
            ->assertJsonPath('data.memberships.0.plan.name', 'Gold Circle');

        $this->deleteJson('/api/v1/memberships/plans/'.$planId)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.memberships.plan_deleted'));

        $this->assertDatabaseMissing('creator_plans', ['id' => $planId]);
        $this->assertSame(2, UserNotification::query()->count());
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => $request->hasHeader('X-DeyMake-Event', 'membership.created'));
        Http::assertSent(fn ($request) => $request->hasHeader('X-DeyMake-Event', 'membership.cancelled'));

        $planUpdatedRequests = collect(Http::recorded())
            ->filter(fn (array $entry) => $entry[0]->hasHeader('X-DeyMake-Event', 'membership.plan.updated'));

        $this->assertCount(3, $planUpdatedRequests);
    }
}