<?php

namespace Tests\Feature;

use App\Models\CollaborationInvite;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollaborationInviteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_send_duet_invite_and_acceptance_reuses_existing_direct_conversation(): void
    {
        $creator = User::factory()->create(['name' => 'Lead Creator', 'username' => 'lead.creator']);
        $invitee = User::factory()->create([
            'name' => 'Guest Creator',
            'username' => 'guest.creator',
            'preferences' => ['language' => 'fr'],
        ]);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Open Verse Session',
            'caption' => 'Need a sharp duet partner',
            'thumbnail_url' => 'https://cdn.example.com/open-verse.jpg',
            'is_draft' => false,
            'moderation_status' => 'visible',
        ]);

        $conversation = Conversation::query()->create();
        $conversation->participants()->attach([
            $creator->id => ['last_read_at' => now()],
            $invitee->id => ['last_read_at' => null],
        ]);

        Sanctum::actingAs($creator);

        $inviteResponse = $this->postJson('/api/v1/collaborations/invites', [
            'inviteeId' => $invitee->id,
            'videoId' => $video->id,
            'type' => 'duet',
            'message' => 'Bring your best second verse.',
        ]);

        $inviteResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.collaborations.invite_created'))
            ->assertJsonPath('data.invite.type', 'duet')
            ->assertJsonPath('data.invite.status', 'pending')
            ->assertJsonPath('data.invite.invitee.id', $invitee->id)
            ->assertJsonPath('data.invite.sourceVideo.id', $video->id);

        $inviteId = $inviteResponse->json('data.invite.id');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $invitee->id,
            'type' => 'collaboration_invite',
            'title' => trans('messages.notifications.collaboration_invite_title', [], 'fr'),
        ]);

        Sanctum::actingAs($invitee);

        $this->getJson('/api/v1/collaborations/invites?scope=inbox&status=pending')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.collaborations.invites_retrieved'))
            ->assertJsonCount(1, 'data.invites')
            ->assertJsonPath('data.invites.0.id', $inviteId)
            ->assertJsonPath('data.invites.0.canRespond', true);

        $this->patchJson('/api/v1/collaborations/invites/'.$inviteId, [
            'action' => 'accept',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.collaborations.invite_updated'))
            ->assertJsonPath('data.invite.status', 'accepted')
            ->assertJsonPath('data.invite.conversationId', $conversation->id);

        $this->assertDatabaseHas('collaboration_invites', [
            'id' => $inviteId,
            'status' => 'accepted',
            'conversation_id' => $conversation->id,
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'collaboration_invite_accepted',
            'title' => trans('messages.notifications.collaboration_accepted_title'),
        ]);

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/collaborations/invites?scope=sent&status=accepted')
            ->assertOk()
            ->assertJsonCount(1, 'data.invites')
            ->assertJsonPath('data.invites.0.id', $inviteId)
            ->assertJsonPath('data.invites.0.conversationId', $conversation->id);
    }

    public function test_collaboration_invites_can_be_rejected_by_invitee_and_cancelled_by_inviter(): void
    {
        $creator = User::factory()->create(['name' => 'Primary Creator', 'username' => 'primary.creator']);
        $invitee = User::factory()->create(['name' => 'Second Creator', 'username' => 'second.creator']);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Skatepark Session',
            'is_draft' => false,
            'moderation_status' => 'visible',
        ]);

        $firstInvite = CollaborationInvite::query()->create([
            'inviter_id' => $creator->id,
            'invitee_id' => $invitee->id,
            'source_video_id' => $video->id,
            'type' => 'remix',
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($invitee);

        $this->patchJson('/api/v1/collaborations/invites/'.$firstInvite->id, [
            'action' => 'reject',
        ])
            ->assertOk()
            ->assertJsonPath('data.invite.status', 'rejected');

        $this->assertDatabaseHas('collaboration_invites', [
            'id' => $firstInvite->id,
            'status' => 'rejected',
        ]);

        $secondInvite = CollaborationInvite::query()->create([
            'inviter_id' => $creator->id,
            'invitee_id' => $invitee->id,
            'source_video_id' => $video->id,
            'type' => 'collab',
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Sanctum::actingAs($creator);

        $this->patchJson('/api/v1/collaborations/invites/'.$secondInvite->id, [
            'action' => 'cancel',
        ])
            ->assertOk()
            ->assertJsonPath('data.invite.status', 'cancelled')
            ->assertJsonPath('data.invite.canCancel', false);

        $this->assertDatabaseHas('collaboration_invites', [
            'id' => $secondInvite->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'collaboration_invite_rejected',
        ]);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $invitee->id,
            'type' => 'collaboration_invite_cancelled',
        ]);
    }
}