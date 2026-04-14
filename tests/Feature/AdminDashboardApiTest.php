<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Models\Comment;
use App\Models\CreatorPlan;
use App\Models\Membership;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_cannot_access_admin_dashboard_routes(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.admin.access_denied'));
    }

    public function test_admin_can_view_dashboard_metrics_and_manage_video_reports(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin User',
            'username' => 'admin.user',
            'last_active_at' => now(),
        ]);
        $creator = User::factory()->create([
            'name' => 'Creator User',
            'username' => 'creator.user',
            'last_active_at' => now()->subHours(2),
        ]);
        $reporter = User::factory()->create([
            'name' => 'Reporter User',
            'username' => 'reporter.user',
            'last_active_at' => now()->subHours(3),
        ]);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Flagged Performance',
            'caption' => 'Needs review',
            'media_url' => 'https://cdn.example.com/flagged.mp4',
            'thumbnail_url' => 'https://cdn.example.com/flagged.jpg',
            'is_live' => true,
            'is_draft' => false,
        ]);

        Comment::query()->create([
            'video_id' => $video->id,
            'user_id' => $reporter->id,
            'body' => 'This may break the rules',
        ]);

        $challenge = Challenge::query()->create([
            'host_id' => $creator->id,
            'title' => 'Freestyle Contest',
            'submission_starts_at' => now()->subDay(),
            'submission_ends_at' => now()->addDays(2),
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        ChallengeSubmission::query()->create([
            'challenge_id' => $challenge->id,
            'user_id' => $reporter->id,
            'video_id' => $video->id,
            'title' => 'Contest Entry',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $plan = CreatorPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'VIP Club',
            'price_amount' => 1500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'is_active' => true,
        ]);

        Membership::query()->create([
            'creator_plan_id' => $plan->id,
            'creator_id' => $creator->id,
            'member_id' => $reporter->id,
            'status' => 'active',
            'price_amount' => 1500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'started_at' => now()->subDay(),
        ]);

        $pendingReport = VideoReport::query()->create([
            'video_id' => $video->id,
            'user_id' => $reporter->id,
            'reason' => 'spam',
            'details' => 'Looks suspicious',
            'status' => 'pending',
        ]);

        VideoReport::query()->create([
            'video_id' => $video->id,
            'user_id' => $creator->id,
            'reason' => 'copyright',
            'details' => 'Possible unauthorized sample',
            'status' => 'reviewed',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now()->subHour(),
            'resolution_notes' => 'Checked by admin',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.dashboard_retrieved'))
            ->assertJsonPath('data.summary.totalUsers', 3)
            ->assertJsonPath('data.summary.activeUsers', 3)
            ->assertJsonPath('data.summary.totalCreators', 1)
            ->assertJsonPath('data.summary.totalVideos', 1)
            ->assertJsonPath('data.summary.publishedVideos', 1)
            ->assertJsonPath('data.summary.liveVideos', 1)
            ->assertJsonPath('data.summary.totalComments', 1)
            ->assertJsonPath('data.summary.activeMemberships', 1)
            ->assertJsonPath('data.summary.totalChallenges', 1)
            ->assertJsonPath('data.summary.openChallenges', 1)
            ->assertJsonPath('data.summary.challengeSubmissions', 1)
            ->assertJsonPath('data.summary.pendingVideoReports', 1)
            ->assertJsonPath('data.summary.reviewedVideoReports', 1)
            ->assertJsonPath('data.recentVideoReports.0.id', $pendingReport->id)
            ->assertJsonPath('data.recentChallenges.0.id', $challenge->id)
            ->assertJsonPath('data.recentUsers.0.fullName', fn ($value) => in_array($value, ['Admin User', 'Creator User', 'Reporter User'], true));

        $this->getJson('/api/v1/admin/reports/videos?status=pending')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.video_reports_retrieved'))
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.id', $pendingReport->id)
            ->assertJsonPath('data.reports.0.video.id', $video->id)
            ->assertJsonPath('data.reports.0.reporter.fullName', 'Reporter User')
            ->assertJsonPath('meta.reports.total', 1);

        $this->patchJson('/api/v1/admin/reports/videos/'.$pendingReport->id, [
            'status' => 'escalated',
            'resolutionNotes' => 'Escalated for moderation review',
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.video_report_updated'))
            ->assertJsonPath('data.report.status', 'escalated')
            ->assertJsonPath('data.report.reviewer.fullName', 'Admin User')
            ->assertJsonPath('data.report.resolutionNotes', 'Escalated for moderation review');

        $this->assertDatabaseHas('video_reports', [
            'id' => $pendingReport->id,
            'status' => 'escalated',
            'reviewed_by' => $admin->id,
            'resolution_notes' => 'Escalated for moderation review',
        ]);
    }
}