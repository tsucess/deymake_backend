<?php

namespace Tests\Feature;

use App\Http\Resources\CreatorVerificationRequestResource;
use App\Models\CreatorVerificationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCreatorVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(User $user, array $attributes = []): CreatorVerificationRequest
    {
        return CreatorVerificationRequest::query()->create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'legal_name' => $user->name,
            'country' => 'Nigeria',
            'document_type' => 'passport',
            'document_url' => 'https://cdn.example.com/id-'.$user->id.'.jpg',
            'social_links' => [],
            'submitted_at' => now(),
        ], $attributes));
    }

    public function test_non_admin_users_cannot_access_admin_creator_verification_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/creator-verification-requests')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.admin.access_denied'));
    }

    public function test_admin_can_list_search_filter_and_summarise_requests(): void
    {
        $admin = User::factory()->admin()->create();
        $pending = $this->makeRequest(User::factory()->create(['username' => 'ada.dev', 'name' => 'Ada Lovelace']), ['submitted_at' => '2026-04-10 10:00:00']);
        $this->makeRequest(User::factory()->create(), ['status' => 'approved', 'submitted_at' => '2026-03-01 10:00:00']);
        $this->makeRequest(User::factory()->create(), ['status' => 'rejected', 'submitted_at' => '2026-02-01 10:00:00']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/creator-verification-requests')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.creator_verification.admin_requests_retrieved'))
            ->assertJsonPath('meta.requests.total', 3)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.pending', 1)
            ->assertJsonPath('meta.summary.approved', 1)
            ->assertJsonPath('meta.summary.rejected', 1);

        $this->getJson('/api/v1/admin/creator-verification-requests?q=ada')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.id', $pending->id);

        $this->getJson('/api/v1/admin/creator-verification-requests?status=rejected')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests');

        $this->getJson('/api/v1/admin/creator-verification-requests?from=2026-04-01')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.id', $pending->id);
    }

    public function test_admin_can_view_request_detail_with_documents_metrics_and_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $request = $this->makeRequest(User::factory()->create(['username' => 'grace.h']));

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/creator-verification-requests/'.$request->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.creator_verification.admin_request_retrieved'))
            ->assertJsonPath('data.request.documentUrl', $request->document_url)
            ->assertJsonPath('data.request.user.username', 'grace.h')
            ->assertJsonPath('data.request.user.subscriberCount', 0);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.creator_verification_viewed',
            'auditable_id' => $request->id,
        ]);
    }

    public function test_admin_can_approve_request_setting_badge_notifying_and_auditing(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $request = $this->makeRequest($creator);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/creator-verification-requests/'.$request->id, [
            'status' => 'approved',
            'reviewNotes' => 'Documents confirmed.',
        ])
            ->assertOk()
            ->assertJsonPath('data.request.status', 'approved')
            ->assertJsonPath('data.request.user.isVerifiedCreator', true);

        $this->assertDatabaseHas('users', ['id' => $creator->id, 'creator_verification_status' => 'approved']);
        $this->assertNotNull($creator->fresh()->creator_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.creator_verification_approved',
            'auditable_id' => $request->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'creator_verification_reviewed',
        ]);
    }

    public function test_admin_can_reject_and_request_more_information_with_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $rejected = $this->makeRequest(User::factory()->create());
        $needsInfo = $this->makeRequest(User::factory()->create());

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/creator-verification-requests/'.$rejected->id, ['status' => 'rejected'])->assertOk();
        $this->patchJson('/api/v1/admin/creator-verification-requests/'.$needsInfo->id, ['status' => 'needs_more_info'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.creator_verification_rejected', 'auditable_id' => $rejected->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.creator_verification_more_info_requested', 'auditable_id' => $needsInfo->id]);
    }

    public function test_update_validates_status(): void
    {
        $admin = User::factory()->admin()->create();
        $request = $this->makeRequest(User::factory()->create());

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/creator-verification-requests/'.$request->id, ['status' => 'banned'])
            ->assertStatus(422);
    }

    public function test_document_url_is_hidden_from_non_owner_non_admin(): void
    {
        $owner = User::factory()->create();
        $request = $this->makeRequest($owner, ['document_url' => 'https://cdn.example.com/secret.jpg']);
        $other = User::factory()->create();

        $httpRequest = Request::create('/');
        $httpRequest->setUserResolver(fn () => $other);
        $restricted = (new CreatorVerificationRequestResource($request))->toArray($httpRequest);
        $this->assertNull($restricted['documentUrl']);
        $this->assertTrue($restricted['documentAccessRestricted']);

        $httpRequest->setUserResolver(fn () => $owner);
        $allowed = (new CreatorVerificationRequestResource($request))->toArray($httpRequest);
        $this->assertSame('https://cdn.example.com/secret.jpg', $allowed['documentUrl']);
    }
}
