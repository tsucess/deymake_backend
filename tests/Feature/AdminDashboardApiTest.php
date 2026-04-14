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

    public function test_admin_can_search_review_and_suspend_users_through_admin_management_api(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin Manager',
            'username' => 'admin.manager',
            'email' => 'admin-manager@example.com',
        ]);
        $creator = User::factory()->create([
            'name' => 'Stream Creator',
            'username' => 'stream.creator',
            'email' => 'stream-creator@example.com',
            'last_active_at' => now()->subMinutes(5),
        ]);
        User::factory()->create([
            'name' => 'Audience Fan',
            'username' => 'audience.fan',
            'email' => 'audience-fan@example.com',
        ]);

        Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Creator Clip',
            'caption' => 'Admin review me',
            'media_url' => 'https://cdn.example.com/creator.mp4',
            'is_draft' => false,
            'is_live' => false,
        ]);

        $adminToken = $admin->createToken('admin-test')->plainTextToken;
        $creator->createToken('creator-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/users?q=stream&role=creator')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.users_retrieved'))
            ->assertJsonPath('data.users.0.id', $creator->id)
            ->assertJsonPath('data.users.0.accountStatus', 'active')
            ->assertJsonPath('data.users.0.stats.videosCount', 1)
            ->assertJsonPath('meta.summary.totalUsers', 3)
            ->assertJsonPath('meta.summary.creatorUsers', 1);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/v1/admin/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_retrieved'))
            ->assertJsonPath('data.user.id', $creator->id)
            ->assertJsonPath('data.user.stats.publishedVideosCount', 1);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->patchJson('/api/v1/admin/users/'.$creator->id, [
                'accountStatus' => 'suspended',
                'accountStatusNotes' => 'Repeated impersonation reports.',
                'clearSessions' => true,
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_updated'))
            ->assertJsonPath('data.user.accountStatus', 'suspended')
            ->assertJsonPath('data.user.isSuspended', true)
            ->assertJsonPath('data.user.accountStatusNotes', 'Repeated impersonation reports.');

        $this->assertDatabaseHas('users', [
            'id' => $creator->id,
            'account_status' => 'suspended',
            'account_status_notes' => 'Repeated impersonation reports.',
            'suspended_by' => $admin->id,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $creator->getMorphClass(),
            'tokenable_id' => $creator->id,
        ]);

        Sanctum::actingAs($creator->fresh());

        $this
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.auth.account_suspended'));

        Sanctum::actingAs($admin->fresh());

        $this
            ->patchJson('/api/v1/admin/users/'.$admin->id, [
                'isAdmin' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('messages.admin.user_self_protection'));

        $this
            ->patchJson('/api/v1/admin/users/'.$creator->id, [
                'accountStatus' => 'active',
                'accountStatusNotes' => 'Suspension lifted after review.',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.accountStatus', 'active')
            ->assertJsonPath('data.user.isSuspended', false);
    }
}