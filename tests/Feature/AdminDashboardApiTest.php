<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Challenge;
use App\Models\ChallengeSubmission;
use App\Models\Comment;
use App\Models\ContentModerationCase;
use App\Models\CreatorPlan;
use App\Models\CreatorVerificationRequest;
use App\Models\FanTip;
use App\Models\Membership;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use App\Models\WalletTransaction;
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

        $this->getJson('/api/admin/dashboard')
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

        $category = Category::query()->create([
            'name' => 'Music',
            'slug' => 'music',
        ]);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Flagged Performance',
            'caption' => 'Needs review',
            'media_url' => 'https://cdn.example.com/flagged.mp4',
            'thumbnail_url' => 'https://cdn.example.com/flagged.jpg',
            'is_live' => true,
            'is_draft' => false,
            'views_count' => 500,
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

        $this->getJson('/api/admin/dashboard')
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
            ->assertJsonPath('data.recentUsers.0.fullName', fn ($value) => in_array($value, ['Admin User', 'Creator User', 'Reporter User'], true))
            ->assertJsonCount(7, 'data.charts.growth.labels')
            ->assertJsonCount(7, 'data.charts.growth.newCreators')
            ->assertJsonCount(7, 'data.charts.growth.activeCreators')
            ->assertJsonPath('data.charts.totalViews', 500)
            ->assertJsonPath('data.charts.categories.0.name', 'Music')
            ->assertJsonPath('data.charts.categories.0.views', 500);

        $this->getJson('/api/admin/reports/videos?status=pending')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.video_reports_retrieved'))
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.id', $pendingReport->id)
            ->assertJsonPath('data.reports.0.video.id', $video->id)
            ->assertJsonPath('data.reports.0.reporter.fullName', 'Reporter User')
            ->assertJsonPath('meta.reports.total', 1);

        $this->patchJson('/api/admin/reports/videos/'.$pendingReport->id, [
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
            ->getJson('/api/admin/users?q=stream&role=creator')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.users_retrieved'))
            ->assertJsonPath('data.users.0.id', $creator->id)
            ->assertJsonPath('data.users.0.accountStatus', 'active')
            ->assertJsonPath('data.users.0.stats.videosCount', 1)
            ->assertJsonPath('meta.summary.totalUsers', 3)
            ->assertJsonPath('meta.summary.creatorUsers', 1);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->getJson('/api/admin/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_retrieved'))
            ->assertJsonPath('data.user.id', $creator->id)
            ->assertJsonPath('data.user.stats.publishedVideosCount', 1);

        $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->patchJson('/api/admin/users/'.$creator->id, [
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
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.auth.account_suspended'));

        Sanctum::actingAs($admin->fresh());

        $this
            ->patchJson('/api/admin/users/'.$admin->id, [
                'isAdmin' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('messages.admin.user_self_protection'));

        $this
            ->patchJson('/api/admin/users/'.$creator->id, [
                'accountStatus' => 'active',
                'accountStatusNotes' => 'Suspension lifted after review.',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.accountStatus', 'active')
            ->assertJsonPath('data.user.isSuspended', false);
    }

    public function test_admin_can_ban_and_unban_a_user_through_admin_management_api(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Admin Enforcer',
            'username' => 'admin.enforcer',
            'email' => 'admin-enforcer@example.com',
        ]);
        $member = User::factory()->create([
            'name' => 'Rule Breaker',
            'username' => 'rule.breaker',
            'email' => 'rule-breaker@example.com',
        ]);

        $member->createToken('member-test')->plainTextToken;

        Sanctum::actingAs($admin);

        $this
            ->patchJson('/api/admin/users/'.$member->id, [
                'accountStatus' => 'banned',
                'accountStatusNotes' => 'Severe terms of service violation.',
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_updated'))
            ->assertJsonPath('data.user.accountStatus', 'banned')
            ->assertJsonPath('data.user.isBanned', true)
            ->assertJsonPath('data.user.isSuspended', false)
            ->assertJsonPath('data.user.accountStatusNotes', 'Severe terms of service violation.');

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'account_status' => 'banned',
            'account_status_notes' => 'Severe terms of service violation.',
            'banned_by' => $admin->id,
        ]);
        $this->assertNotNull($member->fresh()->banned_at);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => $member->getMorphClass(),
            'tokenable_id' => $member->id,
        ]);

        Sanctum::actingAs($member->fresh());

        $this
            ->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.auth.account_banned'));

        Sanctum::actingAs($admin->fresh());

        $this
            ->patchJson('/api/admin/users/'.$member->id, [
                'accountStatus' => 'active',
                'accountStatusNotes' => 'Ban lifted after appeal.',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.accountStatus', 'active')
            ->assertJsonPath('data.user.isBanned', false);

        $fresh = $member->fresh();
        $this->assertNull($fresh->banned_at);
        $this->assertNull($fresh->banned_by);
    }

    public function test_admin_dashboard_returns_expanded_metrics_charts_and_recent_feeds(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Metrics Admin',
            'username' => 'metrics.admin',
        ]);
        $creator = User::factory()->create([
            'name' => 'Revenue Creator',
            'username' => 'revenue.creator',
            'creator_verification_status' => 'approved',
            'creator_verified_at' => now()->subDay(),
        ]);
        User::factory()->create([
            'name' => 'Suspended Person',
            'username' => 'suspended.person',
            'account_status' => 'suspended',
        ]);
        User::factory()->create([
            'name' => 'Banned Person',
            'username' => 'banned.person',
            'account_status' => 'banned',
        ]);
        $reporter = User::factory()->create([
            'name' => 'Report Author',
            'username' => 'report.author',
        ]);

        $category = Category::query()->create(['name' => 'Dance', 'slug' => 'dance']);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Live Set',
            'caption' => 'Streaming now',
            'media_url' => 'https://cdn.example.com/live.mp4',
            'thumbnail_url' => 'https://cdn.example.com/live.jpg',
            'is_live' => true,
            'is_draft' => false,
            'views_count' => 320,
            'live_started_at' => now(),
        ]);

        VideoReport::query()->create([
            'video_id' => $video->id,
            'user_id' => $reporter->id,
            'reason' => 'spam',
            'details' => 'Reported clip',
            'status' => 'pending',
        ]);

        WalletTransaction::query()->create([
            'user_id' => $creator->id,
            'type' => 'membership_credit',
            'direction' => 'credit',
            'status' => 'posted',
            'amount' => 5000,
            'currency' => 'NGN',
            'occurred_at' => now(),
        ]);

        FanTip::query()->create([
            'creator_id' => $creator->id,
            'fan_id' => $reporter->id,
            'video_id' => $video->id,
            'amount' => 1200,
            'currency' => 'NGN',
            'status' => 'posted',
            'tipped_at' => now(),
        ]);

        PayoutRequest::query()->create([
            'user_id' => $creator->id,
            'amount' => 3000,
            'currency' => 'NGN',
            'status' => 'requested',
            'requested_at' => now(),
        ]);

        CreatorVerificationRequest::query()->create([
            'user_id' => $reporter->id,
            'status' => 'pending',
            'legal_name' => 'Report Author',
            'country' => 'Nigeria',
            'document_type' => 'passport',
            'document_url' => 'https://cdn.example.com/doc.pdf',
            'submitted_at' => now(),
        ]);

        ContentModerationCase::query()->create([
            'moderatable_type' => Video::class,
            'moderatable_id' => $video->id,
            'content_type' => 'video',
            'source' => 'user_report',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.suspendedUsers', 1)
            ->assertJsonPath('data.summary.bannedUsers', 1)
            ->assertJsonPath('data.summary.verifiedCreators', 1)
            ->assertJsonPath('data.summary.totalCreators', 1)
            ->assertJsonPath('data.summary.reportedVideos', 1)
            ->assertJsonPath('data.summary.liveStreams', 1)
            ->assertJsonPath('data.summary.revenue', 5000)
            ->assertJsonPath('data.summary.tips', 1200)
            ->assertJsonPath('data.summary.payoutRequests', 1)
            ->assertJsonPath('data.summary.pendingModerationCases', 1)
            ->assertJsonPath('data.summary.pendingVerificationRequests', 1)
            ->assertJsonCount(7, 'data.charts.labels')
            ->assertJsonCount(7, 'data.charts.revenue.revenue')
            ->assertJsonCount(7, 'data.charts.live.liveStreams')
            ->assertJsonCount(7, 'data.charts.content.newVideos')
            ->assertJsonCount(7, 'data.charts.users.newUsers')
            ->assertJsonPath('data.recentPayouts.0.amount', 3000)
            ->assertJsonPath('data.recentPayouts.0.status', 'requested')
            ->assertJsonPath('data.topCreators.0.id', $creator->id)
            ->assertJsonPath('data.topCreators.0.views', 320)
            ->assertJsonPath('data.topCreators.0.earnings', 5000)
            ->assertJsonPath('data.recentVerificationRequests.0.status', 'pending')
            ->assertJsonPath('data.recentVerificationRequests.0.creator.id', $reporter->id)
            ->assertJsonPath('data.recentVerificationRequests.0.creator.username', 'report.author')
            ->assertJsonPath('data.recentVideos.0.thumbnailUrl', 'https://cdn.example.com/live.jpg');
    }

    public function test_trending_videos_use_media_as_thumbnail_when_stored_thumbnail_is_missing(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'thumb.admin']);
        $creator = User::factory()->create(['username' => 'thumb.creator']);

        // Image post without an explicit thumbnail: the image itself is the thumbnail.
        Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'image',
            'title' => 'Cover Shot',
            'media_url' => 'https://cdn.example.com/cover.jpg',
            'is_draft' => false,
            'is_live' => false,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.recentVideos.0.thumbnailUrl', 'https://cdn.example.com/cover.jpg');
    }

    public function test_admin_dashboard_respects_date_range_filter(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Range Admin',
            'username' => 'range.admin',
        ]);

        Sanctum::actingAs($admin);

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();

        $this->getJson('/api/admin/dashboard?from='.$from.'&to='.$to)
            ->assertOk()
            ->assertJsonCount(3, 'data.charts.labels')
            ->assertJsonCount(3, 'data.charts.growth.labels')
            ->assertJsonCount(3, 'data.charts.revenue.revenue');
    }

    public function test_admin_dashboard_returns_dynamic_engagement_sections(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'sections.admin']);

        $ngCreatorA = User::factory()->create([
            'username' => 'ng.creator.a',
            'country_code' => 'NG',
            'last_active_at' => now(),
            'creator_verification_status' => 'approved',
            'creator_verified_at' => now(),
        ]);
        $ngCreatorB = User::factory()->create([
            'username' => 'ng.creator.b',
            'country_code' => 'NG',
            'last_active_at' => now()->subHour(),
        ]);
        $ghCreator = User::factory()->create([
            'username' => 'gh.creator',
            'country_code' => 'GH',
            'last_active_at' => now()->subHours(2),
        ]);

        $firstVideoId = null;
        foreach ([$ngCreatorA, $ngCreatorB, $ghCreator] as $creator) {
            $video = Video::query()->create([
                'user_id' => $creator->id,
                'type' => 'video',
                'title' => 'Clip '.$creator->username,
                'media_url' => 'https://cdn.example.com/'.$creator->username.'.mp4',
                'is_draft' => false,
                'is_live' => false,
            ]);
            $firstVideoId ??= $video->id;
        }

        foreach (['violence', 'nudity', 'copyright'] as $reason) {
            VideoReport::query()->create([
                'video_id' => $firstVideoId,
                'user_id' => $ghCreator->id,
                'reason' => $reason,
                'status' => 'pending',
            ]);
        }

        WalletTransaction::query()->create([
            'user_id' => $ngCreatorA->id,
            'type' => 'membership_credit',
            'direction' => 'credit',
            'status' => 'posted',
            'amount' => 250000,
            'currency' => 'NGN',
            'occurred_at' => now(),
        ]);

        FanTip::query()->create([
            'creator_id' => $ngCreatorA->id,
            'fan_id' => $ghCreator->id,
            'video_id' => $firstVideoId,
            'amount' => 90000,
            'currency' => 'NGN',
            'status' => 'posted',
            'tipped_at' => now(),
        ]);

        $challenge = Challenge::query()->create([
            'host_id' => $ngCreatorA->id,
            'title' => 'Dance with Deymake',
            'submission_starts_at' => now()->subDay(),
            'submission_ends_at' => now()->addDays(3),
            'status' => 'published',
            'published_at' => now()->subHour(),
            'thumbnail_url' => 'https://cdn.example.com/challenge.jpg',
        ]);

        ChallengeSubmission::query()->create([
            'challenge_id' => $challenge->id,
            'user_id' => $ngCreatorB->id,
            'video_id' => $firstVideoId,
            'title' => 'Entry',
            'status' => 'submitted',
            'is_winner' => true,
            'winner_rank' => 1,
            'submitted_at' => now(),
        ]);

        $ngCreatorB->subscribers()->attach($ghCreator->id);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard')
            ->assertOk()
            // Moderation alerts: five fixed categories, in order.
            ->assertJsonCount(5, 'data.moderationAlerts')
            ->assertJsonPath('data.moderationAlerts.0.key', 'violent_content')
            ->assertJsonPath('data.moderationAlerts.0.value', 1)
            // Percent is a proportion: 1 violent report out of 3 published contents.
            ->assertJsonPath('data.moderationAlerts.0.percent', '33.3%')
            ->assertJsonPath('data.moderationAlerts.1.key', 'nudity_sexual')
            ->assertJsonPath('data.moderationAlerts.1.value', 1)
            ->assertJsonPath('data.moderationAlerts.1.percent', '33.3%')
            // Hate speech is measured out of total comments; none exist, so 0%.
            ->assertJsonPath('data.moderationAlerts.2.key', 'hate_speech')
            ->assertJsonPath('data.moderationAlerts.2.percent', '0%')
            ->assertJsonPath('data.moderationAlerts.4.key', 'copyright')
            ->assertJsonPath('data.moderationAlerts.4.value', 1)
            ->assertJsonPath('data.moderationAlerts.4.percent', '33.3%')

            // Creator growth: three fresh creators, one verified, money in kobo.
            ->assertJsonPath('data.creatorGrowth.0.key', 'new_creators')
            ->assertJsonPath('data.creatorGrowth.0.value', 3)
            // 3 new creators this week out of 3 in the trailing year.
            ->assertJsonPath('data.creatorGrowth.0.percent', '100%')
            ->assertJsonPath('data.creatorGrowth.1.key', 'verified_creators')
            ->assertJsonPath('data.creatorGrowth.1.value', 1)
            // 1 verified creator out of 4 total users.
            ->assertJsonPath('data.creatorGrowth.1.percent', '25%')
            ->assertJsonPath('data.creatorGrowth.2.key', 'creator_earnings')
            ->assertJsonPath('data.creatorGrowth.2.value', 250000)
            ->assertJsonPath('data.creatorGrowth.2.isMoney', true)
            // Weekly earnings equal the month's only credit, so 100%.
            ->assertJsonPath('data.creatorGrowth.2.percent', '100%')
            ->assertJsonPath('data.creatorGrowth.3.key', 'revenue_shared')
            ->assertJsonPath('data.creatorGrowth.3.value', 90000)
            // 90,000 shared out of 250,000 all-time platform earnings.
            ->assertJsonPath('data.creatorGrowth.3.percent', '36%')
            // Top challenges.
            ->assertJsonPath('data.topChallenges.0.title', 'Dance with Deymake')
            ->assertJsonPath('data.topChallenges.0.entries', 1)
            ->assertJsonPath('data.topChallenges.0.status', 'active')
            // Top challengers: only ng.creator.b won a challenge, with one follower.
            ->assertJsonCount(1, 'data.topChallengers')
            ->assertJsonPath('data.topChallengers.0.username', 'ng.creator.b')
            ->assertJsonPath('data.topChallengers.0.wins', 1)
            ->assertJsonPath('data.topChallengers.0.followersCount', 1)
            ->assertJsonPath('data.topChallengers.0.role', 'creator')
            // Top regions by DAU: NG (2 active) ranks above GH (1 active).
            ->assertJsonPath('data.topRegions.0.code', 'NG')
            ->assertJsonPath('data.topRegions.0.region', 'Nigeria')
            ->assertJsonPath('data.topRegions.0.value', 2)
            ->assertJsonPath('data.topRegions.1.code', 'GH')
            ->assertJsonPath('data.topRegions.1.value', 1);
    }

    public function test_admin_can_filter_managed_users_by_verification_status(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'verify.admin']);
        $verified = User::factory()->create([
            'username' => 'verified.creator',
            'creator_verification_status' => 'approved',
            'creator_verified_at' => now(),
        ]);
        $pending = User::factory()->create([
            'username' => 'pending.creator',
            'creator_verification_status' => 'pending',
        ]);
        User::factory()->create([
            'username' => 'plain.member',
            'creator_verification_status' => 'unsubmitted',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?verificationStatus=verified')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $verified->id)
            ->assertJsonPath('meta.summary.verifiedUsers', 1)
            ->assertJsonPath('meta.summary.pendingVerificationUsers', 1);

        $this->getJson('/api/admin/users?verificationStatus=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.id', $pending->id);

        // Unverified covers everyone whose status is not "approved" (admin, pending, member).
        $this->getJson('/api/admin/users?verificationStatus=unverified')
            ->assertOk()
            ->assertJsonCount(3, 'data.users');
    }

    public function test_admin_can_view_user_videos_reports_and_activity_feeds(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'feeds.admin']);
        $creator = User::factory()->create(['username' => 'feeds.creator']);
        $reporter = User::factory()->create(['username' => 'feeds.reporter']);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Creator Feed Clip',
            'caption' => 'Watch me',
            'media_url' => 'https://cdn.example.com/feed.mp4',
            'is_draft' => false,
            'is_live' => false,
        ]);

        VideoReport::query()->create([
            'video_id' => $video->id,
            'user_id' => $reporter->id,
            'reason' => 'spam',
            'details' => 'Reported clip',
            'status' => 'pending',
        ]);

        PayoutRequest::query()->create([
            'user_id' => $creator->id,
            'amount' => 4500,
            'currency' => 'NGN',
            'status' => 'requested',
            'requested_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.user.stats.reportsAgainstCount', 1);

        $this->getJson('/api/admin/users/'.$creator->id.'/videos')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_videos_retrieved'))
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.id', $video->id);

        $this->getJson('/api/admin/users/'.$creator->id.'/reports')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_reports_retrieved'))
            ->assertJsonCount(1, 'data.reports')
            ->assertJsonPath('data.reports.0.reason', 'spam')
            ->assertJsonPath('data.reports.0.video.id', $video->id);

        $this->getJson('/api/admin/users/'.$creator->id.'/activity')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_activity_retrieved'))
            ->assertJsonCount(2, 'data.activity')
            ->assertJsonFragment(['type' => 'video'])
            ->assertJsonFragment(['type' => 'payout']);
    }

    public function test_admin_user_details_include_wallet_membership_and_moderation_summaries(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'detail.admin']);
        $creator = User::factory()->create(['username' => 'detail.creator']);
        $member = User::factory()->create(['username' => 'detail.member']);

        $video = Video::query()->create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Detail Clip',
            'media_url' => 'https://cdn.example.com/detail.mp4',
            'is_draft' => false,
            'is_live' => false,
        ]);

        WalletTransaction::query()->create([
            'user_id' => $creator->id,
            'type' => 'membership_credit',
            'direction' => 'credit',
            'status' => 'posted',
            'amount' => 8000,
            'currency' => 'NGN',
            'description' => 'Membership payout',
            'occurred_at' => now(),
        ]);

        WalletTransaction::query()->create([
            'user_id' => $creator->id,
            'type' => 'payout_debit',
            'direction' => 'debit',
            'status' => 'posted',
            'amount' => 3000,
            'currency' => 'NGN',
            'occurred_at' => now(),
        ]);

        $plan = CreatorPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Detail Club',
            'price_amount' => 2500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'is_active' => true,
        ]);

        Membership::query()->create([
            'creator_plan_id' => $plan->id,
            'creator_id' => $creator->id,
            'member_id' => $member->id,
            'status' => 'active',
            'price_amount' => 2500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'started_at' => now()->subDay(),
        ]);

        ContentModerationCase::query()->create([
            'moderatable_type' => Video::class,
            'moderatable_id' => $video->id,
            'content_type' => 'video',
            'source' => 'user_report',
            'status' => 'flagged',
            'ai_risk_level' => 'high',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_retrieved'))
            ->assertJsonPath('data.wallet.credited', 8000)
            ->assertJsonPath('data.wallet.debited', 3000)
            ->assertJsonPath('data.wallet.balance', 5000)
            ->assertJsonPath('data.wallet.transactionsCount', 2)
            ->assertJsonPath('data.membership.asCreator.active', 1)
            ->assertJsonPath('data.membership.asCreator.monthlyRevenue', 2500)
            ->assertJsonPath('data.moderation.total', 1)
            ->assertJsonPath('data.moderation.flagged', 1);

        $this->getJson('/api/admin/users/'.$creator->id.'/transactions')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.user_transactions_retrieved'))
            ->assertJsonCount(2, 'data.transactions')
            ->assertJsonPath('meta.transactions.total', 2);
    }

    public function test_admin_can_reset_a_user_verification_status_and_records_an_audit_log(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'reset.admin']);
        $creator = User::factory()->create([
            'username' => 'reset.creator',
            'creator_verification_status' => 'approved',
            'creator_verified_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/users/'.$creator->id, ['resetVerification' => true])
            ->assertOk()
            ->assertJsonPath('data.user.creatorVerificationStatus', 'unsubmitted')
            ->assertJsonPath('data.user.isVerifiedCreator', false)
            ->assertJsonPath('data.user.creatorVerifiedAt', null);

        $this->assertDatabaseHas('users', [
            'id' => $creator->id,
            'creator_verification_status' => 'unsubmitted',
            'creator_verified_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.user_verification_reset',
            'auditable_type' => $creator->getMorphClass(),
            'auditable_id' => $creator->id,
        ]);
    }

    public function test_admin_can_promote_and_demote_users_and_records_audit_logs(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'role.admin']);
        $member = User::factory()->create(['username' => 'role.member']);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/users/'.$member->id, ['isAdmin' => true])
            ->assertOk()
            ->assertJsonPath('data.user.isAdmin', true);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.user_promoted',
            'auditable_id' => $member->id,
        ]);

        $this->patchJson('/api/admin/users/'.$member->id, ['isAdmin' => false])
            ->assertOk()
            ->assertJsonPath('data.user.isAdmin', false);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.user_demoted',
            'auditable_id' => $member->id,
        ]);
    }

    public function test_suspension_records_an_audit_log_and_the_last_administrator_is_protected(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'audit.admin']);
        $member = User::factory()->create(['username' => 'audit.member']);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/users/'.$member->id, [
            'accountStatus' => 'suspended',
            'accountStatusNotes' => 'Policy violation.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.user_suspended',
            'auditable_id' => $member->id,
        ]);

        // The only administrator cannot remove their own admin access.
        $this->patchJson('/api/admin/users/'.$admin->id, ['isAdmin' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', trans('messages.admin.user_self_protection'));

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_admin' => true]);
    }
}
