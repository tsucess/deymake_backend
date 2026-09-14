<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ContentModerationCase;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentModerationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_reports_create_moderation_cases_and_admin_can_remove_content(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $reporter = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Campus Performance',
            'caption' => 'A clean showcase clip',
            'media_url' => 'https://cdn.example.com/performance.mp4',
            'thumbnail_url' => 'https://cdn.example.com/performance.jpg',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($reporter);

        $this->postJson('/api/videos/'.$video->id.'/report', [
            'reason' => 'unsafe',
            'details' => 'Please review this clip manually.',
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.videos.reported'));

        $moderationCase = ContentModerationCase::query()->where([
            'moderatable_type' => Video::class,
            'moderatable_id' => $video->id,
        ])->firstOrFail();

        $this->assertSame('video', $moderationCase->content_type);
        $this->assertSame('pending_review', $moderationCase->status);
        $this->assertSame('user_report', $moderationCase->source);
        $this->assertSame(1, $moderationCase->report_count);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/moderation/cases?status=pending_review&contentType=video')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.moderation.queue_retrieved'))
            ->assertJsonCount(1, 'data.cases')
            ->assertJsonPath('data.cases.0.id', $moderationCase->id)
            ->assertJsonPath('data.cases.0.subject.id', $video->id)
            ->assertJsonPath('data.cases.0.reportCount', 1);

        $this->patchJson('/api/admin/moderation/cases/'.$moderationCase->id, [
            'action' => 'remove',
            'notes' => 'Removed after manual review.',
            'reason' => 'policy_violation',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.moderation.case_updated'))
            ->assertJsonPath('data.case.status', 'removed')
            ->assertJsonPath('data.case.subject.moderationStatus', 'removed');

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'moderation_status' => 'removed',
        ]);

        Sanctum::actingAs($reporter);
        $this->getJson('/api/videos/'.$video->id)->assertNotFound();
    }

    public function test_ai_scan_auto_restricts_risky_comment_and_admin_can_approve_it(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $commenter = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Dance Challenge Clip',
            'caption' => 'Performance clip',
            'media_url' => 'https://cdn.example.com/dance.mp4',
            'thumbnail_url' => 'https://cdn.example.com/dance.jpg',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($commenter);

        $createResponse = $this->postJson('/api/videos/'.$video->id.'/comments', [
            'body' => 'FREE MONEY click here on telegram for xxx explicit content now',
        ]);

        $createResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.comments.created'))
            ->assertJsonPath('data.comment.moderation.status', 'restricted');

        $commentId = $createResponse->json('data.comment.id');
        $moderationCase = ContentModerationCase::query()->where([
            'moderatable_type' => Comment::class,
            'moderatable_id' => $commentId,
        ])->firstOrFail();

        $this->assertSame('comment', $moderationCase->content_type);
        $this->assertSame('restricted', $moderationCase->status);
        $this->assertSame('high', $moderationCase->ai_risk_level);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/moderation/cases?status=restricted&contentType=comment')
            ->assertOk()
            ->assertJsonCount(1, 'data.cases')
            ->assertJsonPath('data.cases.0.id', $moderationCase->id)
            ->assertJsonPath('data.cases.0.subject.id', $commentId)
            ->assertJsonPath('data.cases.0.aiRiskLevel', 'high');

        Sanctum::actingAs($creator);
        $this->getJson('/api/videos/'.$video->id.'/comments')
            ->assertOk()
            ->assertJsonCount(0, 'data.comments');

        Sanctum::actingAs($admin);
        $this->patchJson('/api/admin/moderation/cases/'.$moderationCase->id, [
            'action' => 'approve',
            'notes' => 'Approved after manual review.',
        ])
            ->assertOk()
            ->assertJsonPath('data.case.status', 'approved')
            ->assertJsonPath('data.case.subject.moderationStatus', 'visible');

        Sanctum::actingAs($creator);
        $this->getJson('/api/videos/'.$video->id.'/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.id', $commentId);
    }

    public function test_admin_can_search_comment_cases_and_manual_decision_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $commenter = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Studio Session',
            'caption' => 'Behind the scenes',
            'media_url' => 'https://cdn.example.com/studio.mp4',
            'thumbnail_url' => 'https://cdn.example.com/studio.jpg',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($commenter);

        $commentId = $this->postJson('/api/videos/'.$video->id.'/comments', [
            'body' => 'Absolutely lovely choreography showcase',
        ])->assertCreated()->json('data.comment.id');

        $moderationCase = ContentModerationCase::query()->where([
            'moderatable_type' => Comment::class,
            'moderatable_id' => $commentId,
        ])->firstOrFail();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/moderation/cases?contentType=comment&q=choreography')
            ->assertOk()
            ->assertJsonCount(1, 'data.cases')
            ->assertJsonPath('data.cases.0.id', $moderationCase->id)
            ->assertJsonPath('data.cases.0.subject.id', $commentId)
            ->assertJsonPath('data.cases.0.subject.author.id', $commenter->id)
            ->assertJsonPath('data.cases.0.subject.video.id', $video->id)
            ->assertJsonPath('meta.summary.total', 1);

        $this->getJson('/api/admin/moderation/cases?contentType=comment&q=nonexistentterm')
            ->assertOk()
            ->assertJsonCount(0, 'data.cases');

        $this->patchJson('/api/admin/moderation/cases/'.$moderationCase->id, [
            'action' => 'restrict',
            'notes' => 'Restricted after manual review.',
        ])
            ->assertOk()
            ->assertJsonPath('data.case.status', 'restricted')
            ->assertJsonPath('data.case.subject.moderationStatus', 'restricted');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.comment_restricted',
            'auditable_id' => $moderationCase->id,
        ]);
    }

    public function test_admin_rescan_comment_records_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $commenter = User::factory()->create();

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Open Mic',
            'media_url' => 'https://cdn.example.com/openmic.mp4',
            'thumbnail_url' => 'https://cdn.example.com/openmic.jpg',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($commenter);

        $commentId = $this->postJson('/api/videos/'.$video->id.'/comments', [
            'body' => 'Great set tonight',
        ])->assertCreated()->json('data.comment.id');

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/moderation/comments/'.$commentId.'/rescan')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.moderation.comment_rescanned'))
            ->assertJsonPath('data.case.subject.id', $commentId);

        $moderationCase = ContentModerationCase::query()->where([
            'moderatable_type' => Comment::class,
            'moderatable_id' => $commentId,
        ])->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.comment_rescanned',
            'auditable_id' => $moderationCase->id,
        ]);
    }
}