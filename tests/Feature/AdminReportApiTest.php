<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReportApiTest extends TestCase
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

    private function makeReport(Video $video, ?User $reporter, array $attributes = []): VideoReport
    {
        return VideoReport::query()->create(array_merge([
            'video_id' => $video->id,
            'user_id' => $reporter?->id,
            'reason' => 'spam',
            'details' => 'Reported content.',
            'status' => 'pending',
        ], $attributes));
    }

    public function test_non_admin_users_cannot_access_admin_report_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/reports')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.admin.access_denied'));
    }

    public function test_admin_can_list_search_and_filter_reports(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $reporter = User::factory()->create(['name' => 'Grace Hopper', 'username' => 'grace.h']);

        $video = $this->makeVideo($creator, ['title' => 'Unique Reported Clip']);
        $liveVideo = $this->makeVideo($creator, ['title' => 'Live Clip', 'is_live' => true]);

        $nudity = $this->makeReport($video, $reporter, ['reason' => 'nudity', 'details' => 'Explicit frame']);
        $copyright = $this->makeReport($video, $creator, ['reason' => 'copyright', 'status' => 'reviewed']);
        $spam = $this->makeReport($liveVideo, $reporter, ['reason' => 'spam']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.reports_retrieved'))
            ->assertJsonPath('meta.summary.totalReports', 3)
            ->assertJsonPath('meta.summary.pendingReports', 2)
            ->assertJsonPath('meta.summary.reviewedReports', 1);

        $this->getJson('/api/v1/admin/reports?q=grace.h')
            ->assertOk()
            ->assertJsonCount(2, 'data.reports');

        $this->getJson('/api/v1/admin/reports?q=Unique Reported')
            ->assertOk()
            ->assertJsonCount(2, 'data.reports');

        $this->getJson('/api/v1/admin/reports?q='.$nudity->id)
            ->assertOk()
            ->assertJsonPath('data.reports.0.id', $nudity->id);

        $this->getJson('/api/v1/admin/reports?status=reviewed')
            ->assertOk()
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.id', $copyright->id);

        $this->getJson('/api/v1/admin/reports?reason=nudity')
            ->assertOk()
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.id', $nudity->id);

        $this->getJson('/api/v1/admin/reports?severity=high')
            ->assertOk()
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.severity', 'high');

        $this->getJson('/api/v1/admin/reports?severity=low')
            ->assertOk()
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.id', $spam->id);

        $this->getJson('/api/v1/admin/reports?type=live')
            ->assertOk()
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.id', $spam->id);

        $this->getJson('/api/v1/admin/reports?to=2000-01-01')
            ->assertOk()
            ->assertJsonCount(0, 'data.reports');
    }

    public function test_admin_can_view_report_detail_with_content_target_and_case(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create(['name' => 'Content Owner']);
        $reporter = User::factory()->create();

        $video = $this->makeVideo($creator, ['title' => 'Detailed Reported Clip']);
        $video->moderationCase()->create([
            'content_type' => 'video',
            'source' => 'user_report',
            'status' => 'pending_review',
            'ai_score' => 55,
            'ai_risk_level' => 'medium',
            'ai_flags' => ['spam'],
            'report_count' => 1,
        ]);
        $report = $this->makeReport($video, $reporter, ['reason' => 'harassment']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/reports/'.$report->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.report_retrieved'))
            ->assertJsonPath('data.report.id', $report->id)
            ->assertJsonPath('data.report.severity', 'medium')
            ->assertJsonPath('data.report.video.id', $video->id)
            ->assertJsonPath('data.targetUser.fullName', 'Content Owner')
            ->assertJsonPath('data.moderationCase.aiRiskLevel', 'medium')
            ->assertJsonPath('data.relatedReports.total', 1);
    }

    public function test_admin_can_mark_report_valid_dismiss_and_escalate_with_audit_logging(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $reporter = User::factory()->create();

        $video = $this->makeVideo($creator);
        $report = $this->makeReport($video, $reporter);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, [
            'status' => 'reviewed',
            'adminNotes' => 'Confirmed the violation.',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.report_updated'))
            ->assertJsonPath('data.report.status', 'reviewed')
            ->assertJsonPath('data.report.resolutionNotes', 'Confirmed the violation.');

        $this->assertDatabaseHas('video_reports', [
            'id' => $report->id,
            'status' => 'reviewed',
            'reviewed_by' => $admin->id,
            'resolution_notes' => 'Confirmed the violation.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.report_reviewed',
            'auditable_id' => $report->id,
        ]);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, ['status' => 'dismissed'])
            ->assertOk()
            ->assertJsonPath('data.report.status', 'dismissed');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.report_dismissed',
            'auditable_id' => $report->id,
        ]);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, ['status' => 'escalated'])
            ->assertOk()
            ->assertJsonPath('data.report.status', 'escalated');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.report_escalated',
            'auditable_id' => $report->id,
        ]);
    }

    public function test_admin_can_restrict_remove_and_restore_reported_content_with_audit_logging(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $reporter = User::factory()->create();

        $video = $this->makeVideo($creator, ['moderation_status' => 'visible']);
        $report = $this->makeReport($video, $reporter, ['reason' => 'nudity']);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, [
            'status' => 'reviewed',
            'contentAction' => 'restrict',
        ])
            ->assertOk()
            ->assertJsonPath('data.report.video.moderationStatus', 'restricted');

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'moderation_status' => 'restricted',
            'moderated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_restricted',
            'auditable_id' => $video->id,
        ]);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, ['contentAction' => 'remove'])
            ->assertOk()
            ->assertJsonPath('data.report.video.moderationStatus', 'removed');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_removed',
            'auditable_id' => $video->id,
        ]);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, ['contentAction' => 'restore'])
            ->assertOk()
            ->assertJsonPath('data.report.video.moderationStatus', 'visible');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_restored',
            'auditable_id' => $video->id,
        ]);
    }

    public function test_admin_can_notify_reporter_when_resolving_report(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $reporter = User::factory()->create();

        $video = $this->makeVideo($creator);
        $report = $this->makeReport($video, $reporter);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, [
            'status' => 'reviewed',
            'notifyReporter' => true,
        ])->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $reporter->id,
            'type' => 'content_report_update',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.report_reporter_notified',
            'auditable_id' => $report->id,
        ]);
    }

    public function test_update_validates_status_and_content_action(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();

        $video = $this->makeVideo($creator);
        $report = $this->makeReport($video, User::factory()->create());

        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/reports/'.$report->id, ['status' => 'bogus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->patchJson('/api/v1/admin/reports/'.$report->id, ['contentAction' => 'nuke'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contentAction');
    }
}
