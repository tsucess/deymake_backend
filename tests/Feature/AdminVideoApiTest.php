<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVideoApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeVideo(User $creator, array $attributes = []): Video
    {
        return Video::query()->create(array_merge([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Sample Clip',
            'caption' => 'A sample caption',
            'media_url' => 'https://cdn.example.com/sample.mp4',
            'thumbnail_url' => 'https://cdn.example.com/sample.jpg',
            'is_draft' => false,
            'is_live' => false,
            'moderation_status' => 'visible',
        ], $attributes));
    }

    public function test_non_admin_users_cannot_access_admin_video_routes(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/videos')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.admin.access_denied'));
    }

    public function test_admin_can_list_search_and_filter_videos(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create([
            'name' => 'Ada Lovelace',
            'username' => 'ada.dev',
        ]);
        $other = User::factory()->create();

        $target = $this->makeVideo($creator, ['title' => 'Unique Dance Routine']);
        $this->makeVideo($other, ['title' => 'Cooking Basics', 'is_live' => true]);
        $this->makeVideo($other, ['title' => 'Removed Item', 'moderation_status' => 'removed']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/videos')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.videos_retrieved'))
            ->assertJsonPath('meta.summary.totalVideos', 3)
            ->assertJsonPath('meta.summary.liveVideos', 1)
            ->assertJsonPath('meta.summary.removedVideos', 1);

        $this->getJson('/api/v1/admin/videos?q=Unique Dance')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.id', $target->id);

        $this->getJson('/api/v1/admin/videos?q=ada.dev')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.id', $target->id);

        $this->getJson('/api/v1/admin/videos?q='.$target->id)
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $target->id);

        $this->getJson('/api/v1/admin/videos?moderationStatus=removed')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos');

        $this->getJson('/api/v1/admin/videos?live=true')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos');
    }

    public function test_admin_can_view_video_detail_with_moderation_case_and_reports(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $reporter = User::factory()->create();

        $video = $this->makeVideo($creator, ['title' => 'Detailed Clip']);

        $video->moderationCase()->create([
            'content_type' => 'video',
            'source' => 'ai_scan',
            'status' => 'pending_review',
            'ai_score' => 60,
            'ai_risk_level' => 'medium',
            'ai_flags' => ['spam'],
            'ai_summary' => 'AI scan flagged: spam.',
            'report_count' => 1,
        ]);

        VideoReport::query()->create([
            'video_id' => $video->id,
            'user_id' => $reporter->id,
            'reason' => 'spam',
            'details' => 'Looks like spam.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/videos/'.$video->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.video_retrieved'))
            ->assertJsonPath('data.video.id', $video->id)
            ->assertJsonPath('data.moderationCase.status', 'pending_review')
            ->assertJsonPath('data.moderationCase.aiRiskLevel', 'medium')
            ->assertJsonPath('data.reports.total', 1)
            ->assertJsonPath('data.reports.pending', 1)
            ->assertJsonCount(1, 'data.reports.recent');
    }

    public function test_admin_can_approve_and_restrict_video_with_audit_logging(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();

        $video = $this->makeVideo($creator, ['moderation_status' => 'pending_review']);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/videos/'.$video->id, [
            'moderationStatus' => 'visible',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.video_updated'))
            ->assertJsonPath('data.video.moderation.status', 'visible');

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'moderation_status' => 'visible',
            'moderated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_approved',
            'auditable_id' => $video->id,
        ]);

        $this->patchJson('/api/v1/admin/videos/'.$video->id, [
            'moderationStatus' => 'restricted',
            'moderationNotes' => 'Hidden pending appeal.',
        ])
            ->assertOk()
            ->assertJsonPath('data.video.moderation.status', 'restricted');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_restricted',
            'auditable_id' => $video->id,
        ]);
    }

    public function test_admin_can_delete_video_with_audit_logging(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();

        $video = $this->makeVideo($creator);

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/v1/admin/videos/'.$video->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.video_deleted'));

        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_deleted',
            'auditable_id' => $video->id,
        ]);
    }

    public function test_admin_can_list_video_reports(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $reporter = User::factory()->create();

        $video = $this->makeVideo($creator);

        VideoReport::query()->create([
            'video_id' => $video->id,
            'user_id' => $reporter->id,
            'reason' => 'spam',
            'details' => 'Spammy content.',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/videos/'.$video->id.'/reports')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.video_reports_retrieved'))
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.reason', 'spam');
    }

    public function test_admin_can_rescan_video(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();

        $video = $this->makeVideo($creator, [
            'title' => 'FREE MONEY click here on telegram for xxx explicit content now',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/videos/'.$video->id.'/rescan')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.moderation.video_rescanned'))
            ->assertJsonPath('data.moderationCase.aiRiskLevel', 'high');

        $this->assertDatabaseHas('content_moderation_cases', [
            'moderatable_id' => $video->id,
            'content_type' => 'video',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_rescanned',
            'auditable_id' => $video->id,
        ]);
    }
}
