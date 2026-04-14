<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChallengeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_users_can_browse_published_challenges_and_submissions(): void
    {
        $host = User::factory()->create(['name' => 'Challenge Host', 'username' => 'challenge.host']);
        $participant = User::factory()->create(['name' => 'Challenge Fan', 'username' => 'challenge.fan']);

        $active = Challenge::query()->create([
            'host_id' => $host->id,
            'title' => 'Active Dance Challenge',
            'summary' => 'Show your best move',
            'submission_starts_at' => now()->subDay(),
            'submission_ends_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'is_featured' => true,
        ]);

        Challenge::query()->create([
            'host_id' => $host->id,
            'title' => 'Upcoming Song Challenge',
            'submission_starts_at' => now()->addDays(2),
            'submission_ends_at' => now()->addDays(7),
            'status' => 'published',
            'published_at' => now(),
        ]);

        Challenge::query()->create([
            'host_id' => $host->id,
            'title' => 'Draft Host Challenge',
            'submission_starts_at' => now(),
            'status' => 'draft',
        ]);

        $video = Video::query()->create([
            'user_id' => $participant->id,
            'type' => 'video',
            'title' => 'My Entry',
            'caption' => 'Entry caption',
            'media_url' => 'https://cdn.example.com/entry.mp4',
            'thumbnail_url' => 'https://cdn.example.com/entry.jpg',
            'is_draft' => false,
        ]);

        ChallengeSubmission::query()->create([
            'challenge_id' => $active->id,
            'user_id' => $participant->id,
            'video_id' => $video->id,
            'title' => 'My Entry',
            'media_url' => $video->media_url,
            'thumbnail_url' => $video->thumbnail_url,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->getJson('/api/v1/challenges?status=active')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.challenges.retrieved'))
            ->assertJsonCount(1, 'data.challenges')
            ->assertJsonPath('data.challenges.0.id', $active->id)
            ->assertJsonPath('data.challenges.0.host.fullName', 'Challenge Host')
            ->assertJsonPath('data.challenges.0.isOpen', true)
            ->assertJsonPath('data.challenges.0.currentUserState.canSubmit', false)
            ->assertJsonPath('meta.challenges.total', 1);

        $this->getJson('/api/v1/challenges/'.$active->id)
            ->assertOk()
            ->assertJsonPath('data.challenge.slug', 'active-dance-challenge')
            ->assertJsonPath('data.challenge.submissionsCount', 1);

        $this->getJson('/api/v1/challenges/'.$active->id.'/submissions')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.challenges.submissions_retrieved'))
            ->assertJsonPath('data.submissions.0.user.fullName', 'Challenge Fan')
            ->assertJsonPath('data.submissions.0.video.id', $video->id)
            ->assertJsonPath('meta.submissions.total', 1);

        $this->getJson('/api/v1/challenges?status=featured')
            ->assertOk()
            ->assertJsonPath('data.challenges.0.id', $active->id)
            ->assertJsonPath('meta.challenges.total', 1);
    }

    public function test_authenticated_users_can_manage_challenges_submit_entries_and_withdraw(): void
    {
        $host = User::factory()->create(['name' => 'Host Creator', 'username' => 'host.creator']);
        $participant = User::factory()->create(['name' => 'Entry Creator', 'username' => 'entry.creator']);

        Sanctum::actingAs($host);

        $createResponse = $this->postJson('/api/v1/challenges', [
            'title' => 'Campus Talent Hunt',
            'summary' => 'Show your creative skill',
            'description' => 'Upload your most impressive performance.',
            'rules' => ['Original content only'],
            'prizes' => ['₦100,000 grand prize'],
            'requirements' => ['Must be 60 seconds or less'],
            'judgingCriteria' => ['Creativity', 'Execution'],
            'submissionStartsAt' => now()->subHour()->toISOString(),
            'submissionEndsAt' => now()->addDays(5)->toISOString(),
            'maxSubmissionsPerUser' => 1,
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.challenges.created'))
            ->assertJsonPath('data.challenge.status', 'draft')
            ->assertJsonPath('data.challenge.currentUserState.isHost', true);

        $challengeId = $createResponse->json('data.challenge.id');

        $this->patchJson('/api/v1/challenges/'.$challengeId, [
            'summary' => 'Updated summary',
            'isFeatured' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.challenges.updated'))
            ->assertJsonPath('data.challenge.summary', 'Updated summary')
            ->assertJsonPath('data.challenge.isFeatured', true);

        $this->postJson('/api/v1/challenges/'.$challengeId.'/publish')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.challenges.published'))
            ->assertJsonPath('data.challenge.status', 'published')
            ->assertJsonPath('data.challenge.lifecycleStatus', 'active');

        $this->getJson('/api/v1/me/challenges')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.challenges.my_retrieved'))
            ->assertJsonPath('data.challenges.0.id', $challengeId);

        $video = Video::query()->create([
            'user_id' => $participant->id,
            'type' => 'video',
            'title' => 'Talent Entry',
            'caption' => 'A powerful performance',
            'description' => 'Performance description',
            'media_url' => 'https://cdn.example.com/talent-entry.mp4',
            'thumbnail_url' => 'https://cdn.example.com/talent-entry.jpg',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($participant);

        $this->getJson('/api/v1/challenges/'.$challengeId)
            ->assertOk()
            ->assertJsonPath('data.challenge.currentUserState.hasSubmitted', false)
            ->assertJsonPath('data.challenge.currentUserState.canSubmit', true);

        $submissionResponse = $this->postJson('/api/v1/challenges/'.$challengeId.'/submissions', [
            'videoId' => $video->id,
            'caption' => 'Submitting my best performance',
            'metadata' => ['team' => 'Blue'],
        ]);

        $submissionResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.challenges.submission_created'))
            ->assertJsonPath('data.submission.video.id', $video->id)
            ->assertJsonPath('data.submission.status', 'submitted')
            ->assertJsonPath('data.submission.currentUserState.canWithdraw', true);

        $submissionId = $submissionResponse->json('data.submission.id');

        $this->postJson('/api/v1/challenges/'.$challengeId.'/submissions', [
            'videoId' => $video->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('messages.challenges.submission_limit_reached'));

        $this->getJson('/api/v1/challenges/'.$challengeId.'/submissions/mine')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.challenges.my_submissions_retrieved'))
            ->assertJsonPath('data.submissions.0.id', $submissionId)
            ->assertJsonPath('meta.submissions.total', 1);

        $this->getJson('/api/v1/me/challenge-submissions')
            ->assertOk()
            ->assertJsonPath('data.submissions.0.challenge.id', $challengeId)
            ->assertJsonPath('data.submissions.0.user.fullName', 'Entry Creator');

        $this->postJson('/api/v1/challenge-submissions/'.$submissionId.'/withdraw')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.challenges.submission_withdrawn'))
            ->assertJsonPath('data.submission.status', 'withdrawn')
            ->assertJsonPath('data.submission.currentUserState.canWithdraw', false);

        $this->assertDatabaseHas('challenge_submissions', [
            'id' => $submissionId,
            'status' => 'withdrawn',
        ]);
    }
}