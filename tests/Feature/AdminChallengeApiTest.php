<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminChallengeApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeChallenge(User $host, array $attributes = []): Challenge
    {
        return Challenge::query()->create(array_merge([
            'host_id' => $host->id,
            'title' => 'Sample Challenge',
            'summary' => 'A sample challenge',
            'submission_starts_at' => now()->subDay(),
            'submission_ends_at' => now()->addDays(5),
            'status' => 'published',
            'published_at' => now()->subHour(),
        ], $attributes));
    }

    private function makeSubmission(Challenge $challenge, User $user, array $attributes = []): ChallengeSubmission
    {
        $video = Video::query()->create([
            'user_id' => $user->id,
            'type' => 'video',
            'title' => 'Entry',
            'media_url' => 'https://cdn.example.com/entry.mp4',
            'thumbnail_url' => 'https://cdn.example.com/entry.jpg',
            'is_draft' => false,
        ]);

        return ChallengeSubmission::query()->create(array_merge([
            'challenge_id' => $challenge->id,
            'user_id' => $user->id,
            'video_id' => $video->id,
            'title' => 'Entry',
            'media_url' => $video->media_url,
            'thumbnail_url' => $video->thumbnail_url,
            'status' => 'submitted',
            'submitted_at' => now(),
        ], $attributes));
    }

    public function test_non_admin_users_cannot_access_admin_challenge_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/challenges')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.admin.access_denied'));
    }

    public function test_admin_can_list_search_and_filter_challenges(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create(['name' => 'Grace Hopper', 'username' => 'grace.h']);

        $this->makeChallenge($host, ['title' => 'Distinctive Dance Battle', 'category' => 'Dance']);
        $this->makeChallenge($host, ['title' => 'Draft Song Contest', 'status' => 'draft', 'published_at' => null, 'category' => 'Music']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/challenges')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenges_retrieved'))
            ->assertJsonPath('meta.summary.totalChallenges', 2)
            ->assertJsonPath('meta.summary.publishedChallenges', 1)
            ->assertJsonPath('meta.summary.draftChallenges', 1);

        $this->getJson('/api/admin/challenges?status=draft')
            ->assertOk()
            ->assertJsonCount(1, 'data.challenges')
            ->assertJsonPath('data.challenges.0.title', 'Draft Song Contest');

        $this->getJson('/api/admin/challenges?q=Distinctive')
            ->assertOk()
            ->assertJsonCount(1, 'data.challenges')
            ->assertJsonPath('data.challenges.0.title', 'Distinctive Dance Battle');

        $this->getJson('/api/admin/challenges?category=Dance')
            ->assertOk()
            ->assertJsonCount(1, 'data.challenges')
            ->assertJsonPath('data.challenges.0.category', 'Dance');
    }

    public function test_admin_can_create_challenge_and_records_audit(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/challenges', [
            'title' => 'Admin Created Challenge',
            'summary' => 'Made by admin',
            'category' => 'Comedy',
            'submissionStartsAt' => now()->subHour()->toISOString(),
            'submissionEndsAt' => now()->addDays(3)->toISOString(),
            'maxSubmissionsPerUser' => 2,
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.admin.challenge_created'))
            ->assertJsonPath('data.challenge.status', 'draft')
            ->assertJsonPath('data.challenge.category', 'Comedy');

        $challengeId = $response->json('data.challenge.id');

        $this->assertDatabaseHas('challenges', [
            'id' => $challengeId,
            'title' => 'Admin Created Challenge',
            'category' => 'Comedy',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.challenge_created',
            'auditable_id' => $challengeId,
        ]);
    }

    public function test_admin_can_update_and_publish_challenge_with_audit_and_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host, [
            'title' => 'Draft Challenge',
            'status' => 'draft',
            'published_at' => null,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/challenges/'.$challenge->id, [
            'title' => 'Renamed Challenge',
            'summary' => 'Updated summary',
            'category' => 'Fitness',
            'submissionStartsAt' => now()->subHour()->toISOString(),
            'status' => 'published',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_updated'))
            ->assertJsonPath('data.challenge.title', 'Renamed Challenge')
            ->assertJsonPath('data.challenge.status', 'published')
            ->assertJsonPath('data.challenge.category', 'Fitness');

        $this->assertNotNull($challenge->fresh()->published_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.challenge_published',
            'auditable_id' => $challenge->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $host->id,
            'type' => 'challenge',
            'title' => trans('messages.notifications.challenge_published_title'),
        ]);
    }

    public function test_admin_can_delete_challenge_with_audit_and_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host, ['title' => 'To Remove']);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/admin/challenges/'.$challenge->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_deleted'));

        $this->assertDatabaseMissing('challenges', ['id' => $challenge->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.challenge_deleted',
            'auditable_id' => $challenge->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $host->id,
            'type' => 'challenge',
            'title' => trans('messages.notifications.challenge_removed_title'),
        ]);
    }

    public function test_admin_can_list_and_review_submissions(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host);
        $participant = User::factory()->create(['name' => 'Alan Turing', 'username' => 'alan.t']);
        $submission = $this->makeSubmission($challenge, $participant);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/challenges/'.$challenge->id.'/submissions')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_submissions_retrieved'))
            ->assertJsonPath('meta.summary.total', 1)
            ->assertJsonPath('meta.summary.submitted', 1)
            ->assertJsonPath('data.submissions.0.user.fullName', 'Alan Turing');

        $this->patchJson('/api/admin/challenge-submissions/'.$submission->id, [
            'action' => 'approve',
            'notes' => 'Great entry',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_submission_reviewed'))
            ->assertJsonPath('data.submission.status', 'approved')
            ->assertJsonPath('data.submission.reviewNotes', 'Great entry');

        $this->assertDatabaseHas('challenge_submissions', [
            'id' => $submission->id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.challenge_submission_reviewed',
            'auditable_id' => $submission->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $participant->id,
            'type' => 'challenge',
            'title' => trans('messages.notifications.challenge_submission_approved_title'),
        ]);
    }

    public function test_admin_can_reject_submission_with_notes_and_notifies(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host);
        $participant = User::factory()->create();
        $submission = $this->makeSubmission($challenge, $participant);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/challenge-submissions/'.$submission->id, [
            'action' => 'reject',
            'notes' => 'Does not meet the rules',
        ])
            ->assertOk()
            ->assertJsonPath('data.submission.status', 'rejected')
            ->assertJsonPath('data.submission.reviewNotes', 'Does not meet the rules');

        $this->assertDatabaseHas('challenge_submissions', [
            'id' => $submission->id,
            'status' => 'rejected',
            'review_notes' => 'Does not meet the rules',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.challenge_submission_reviewed',
            'auditable_id' => $submission->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $participant->id,
            'type' => 'challenge',
            'title' => trans('messages.notifications.challenge_submission_rejected_title'),
        ]);
    }

    public function test_admin_can_request_changes_on_submission_with_notes_and_notifies(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host);
        $participant = User::factory()->create();
        $submission = $this->makeSubmission($challenge, $participant);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/challenge-submissions/'.$submission->id, [
            'action' => 'request_changes',
            'notes' => 'Please add the required hashtag and resubmit',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_submission_reviewed'))
            ->assertJsonPath('data.submission.status', 'changes_requested')
            ->assertJsonPath('data.submission.reviewNotes', 'Please add the required hashtag and resubmit');

        $this->assertDatabaseHas('challenge_submissions', [
            'id' => $submission->id,
            'status' => 'changes_requested',
            'review_notes' => 'Please add the required hashtag and resubmit',
            'reviewed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.challenge_submission_reviewed',
            'auditable_id' => $submission->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $participant->id,
            'type' => 'challenge',
            'title' => trans('messages.notifications.challenge_submission_changes_requested_title'),
        ]);
    }

    public function test_admin_review_submission_validates_action(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host);
        $submission = $this->makeSubmission($challenge, User::factory()->create());

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/challenge-submissions/'.$submission->id, [
            'action' => 'banish',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('action');
    }

    public function test_admin_can_filter_and_paginate_submissions(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host);

        foreach (range(1, 3) as $i) {
            $this->makeSubmission($challenge, User::factory()->create(), ['status' => 'submitted']);
        }
        $changesEntry = $this->makeSubmission($challenge, User::factory()->create(), ['status' => 'changes_requested']);
        $this->makeSubmission($challenge, User::factory()->create(), ['status' => 'approved']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/challenges/'.$challenge->id.'/submissions?status=changes_requested')
            ->assertOk()
            ->assertJsonCount(1, 'data.submissions')
            ->assertJsonPath('data.submissions.0.id', $changesEntry->id)
            ->assertJsonPath('meta.summary.total', 5)
            ->assertJsonPath('meta.summary.submitted', 3)
            ->assertJsonPath('meta.summary.approved', 1)
            ->assertJsonPath('meta.summary.changesRequested', 1);

        $this->getJson('/api/admin/challenges/'.$challenge->id.'/submissions?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.submissions')
            ->assertJsonPath('meta.submissions.perPage', 2)
            ->assertJsonPath('meta.submissions.currentPage', 1)
            ->assertJsonPath('meta.submissions.total', 5)
            ->assertJsonPath('meta.submissions.lastPage', 3);
    }

    public function test_admin_can_select_winner_and_view_analytics_and_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $host = User::factory()->create();
        $challenge = $this->makeChallenge($host, ['category' => 'Art']);
        $participant = User::factory()->create();
        $submission = $this->makeSubmission($challenge, $participant, ['status' => 'approved']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/challenge-submissions/'.$submission->id.'/winner', [
            'isWinner' => true,
            'rank' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_winner_updated'))
            ->assertJsonPath('data.submission.isWinner', true)
            ->assertJsonPath('data.submission.winnerRank', 1);

        $this->assertDatabaseHas('challenge_submissions', [
            'id' => $submission->id,
            'is_winner' => true,
            'winner_rank' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.challenge_winner_updated',
            'auditable_id' => $submission->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $participant->id,
            'type' => 'challenge',
            'title' => trans('messages.notifications.challenge_winner_title'),
        ]);

        $this->getJson('/api/admin/challenges/'.$challenge->id.'/analytics')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_analytics_retrieved'))
            ->assertJsonPath('data.analytics.participants', 1)
            ->assertJsonPath('data.analytics.submissions.winners', 1)
            ->assertJsonPath('data.analytics.winners.0.id', $submission->id);

        $this->getJson('/api/admin/challenge-categories')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.challenge_categories_retrieved'))
            ->assertJsonPath('data.categories.0.name', 'Art')
            ->assertJsonPath('data.categories.0.count', 1);
    }
}
