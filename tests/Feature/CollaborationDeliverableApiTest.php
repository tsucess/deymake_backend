<?php

namespace Tests\Feature;

use App\Models\CollaborationInvite;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollaborationDeliverableApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_collaboration_can_move_from_draft_to_changes_requested_to_approved(): void
    {
        $inviter = User::factory()->create(['name' => 'Lead Artist', 'username' => 'lead.artist']);
        $invitee = User::factory()->create(['name' => 'Guest Artist', 'username' => 'guest.artist']);

        $sourceVideo = Video::query()->create([
            'user_id' => $inviter->id,
            'type' => 'video',
            'title' => 'Open Verse Original',
            'caption' => 'Waiting for a guest take',
            'is_draft' => false,
            'moderation_status' => 'visible',
        ]);

        $draftVideo = Video::query()->create([
            'user_id' => $invitee->id,
            'type' => 'video',
            'title' => 'Guest Verse Draft',
            'caption' => 'Version one',
            'is_draft' => true,
            'moderation_status' => 'visible',
        ]);

        $invite = CollaborationInvite::query()->create([
            'inviter_id' => $inviter->id,
            'invitee_id' => $invitee->id,
            'source_video_id' => $sourceVideo->id,
            'type' => 'duet',
            'status' => 'accepted',
            'responded_at' => now()->subDay(),
            'expires_at' => now()->addDays(6),
        ]);

        Sanctum::actingAs($invitee);

        $createResponse = $this->postJson('/api/v1/collaborations/invites/'.$invite->id.'/deliverables', [
            'title' => 'Guest Verse V1',
            'brief' => 'First pass with a softer intro.',
            'draftVideoId' => $draftVideo->id,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.collaborations.deliverable_created'))
            ->assertJsonPath('data.deliverable.status', 'drafting')
            ->assertJsonPath('data.deliverable.draftVideo.id', $draftVideo->id)
            ->assertJsonPath('data.deliverable.canEdit', true);

        $deliverableId = $createResponse->json('data.deliverable.id');

        $this->patchJson('/api/v1/collaborations/deliverables/'.$deliverableId, [
            'action' => 'submit',
        ])
            ->assertOk()
            ->assertJsonPath('data.deliverable.status', 'submitted')
            ->assertJsonPath('data.deliverable.feedback', null);

        Sanctum::actingAs($inviter);

        $this->getJson('/api/v1/collaborations/invites/'.$invite->id.'/deliverables')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.collaborations.deliverables_retrieved'))
            ->assertJsonCount(1, 'data.deliverables')
            ->assertJsonPath('data.deliverables.0.id', $deliverableId)
            ->assertJsonPath('data.deliverables.0.canReview', true);

        $this->patchJson('/api/v1/collaborations/deliverables/'.$deliverableId, [
            'action' => 'request_changes',
            'feedback' => 'Tighten the hook and punch in faster.',
        ])
            ->assertOk()
            ->assertJsonPath('data.deliverable.status', 'changes_requested')
            ->assertJsonPath('data.deliverable.feedback', 'Tighten the hook and punch in faster.');

        Sanctum::actingAs($invitee);

        $this->patchJson('/api/v1/collaborations/deliverables/'.$deliverableId, [
            'action' => 'submit',
            'title' => 'Guest Verse V2',
        ])
            ->assertOk()
            ->assertJsonPath('data.deliverable.status', 'submitted')
            ->assertJsonPath('data.deliverable.title', 'Guest Verse V2');

        Sanctum::actingAs($inviter);

        $this->patchJson('/api/v1/collaborations/deliverables/'.$deliverableId, [
            'action' => 'approve',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.collaborations.deliverable_updated'))
            ->assertJsonPath('data.deliverable.status', 'approved');

        $this->assertDatabaseHas('collaboration_deliverables', [
            'id' => $deliverableId,
            'status' => 'approved',
            'reviewed_by' => $inviter->id,
            'draft_video_id' => $draftVideo->id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $inviter->id,
            'type' => 'collaboration_deliverable_submitted',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $invitee->id,
            'type' => 'collaboration_deliverable_changes_requested',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $invitee->id,
            'type' => 'collaboration_deliverable_approved',
        ]);
    }
}