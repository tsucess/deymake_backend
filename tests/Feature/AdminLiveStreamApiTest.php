<?php

namespace Tests\Feature;

use App\Models\LivePresenceSession;
use App\Models\LiveSignal;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLiveStreamApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeLiveVideo(User $creator, array $attributes = []): Video
    {
        return Video::query()->create(array_merge([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Live Session',
            'media_url' => 'https://cdn.example.com/live.mp4',
            'thumbnail_url' => 'https://cdn.example.com/live.jpg',
            'is_draft' => false,
            'is_live' => true,
            'moderation_status' => 'visible',
            'live_started_at' => now()->subMinutes(30),
            'live_peak_viewers_count' => 10,
            'live_comments_count' => 5,
        ], $attributes));
    }

    private function makePresence(Video $video, User $user, string $role, array $attributes = []): LivePresenceSession
    {
        return LivePresenceSession::query()->create(array_merge([
            'video_id' => $video->id,
            'user_id' => $user->id,
            'session_key' => 'sess-'.$user->id.'-'.$role,
            'role' => $role,
            'joined_at' => now()->subMinutes(5),
            'last_seen_at' => now(),
            'left_at' => null,
        ], $attributes));
    }

    private function makeReport(Video $video, User $reporter, array $attributes = []): VideoReport
    {
        return VideoReport::query()->create(array_merge([
            'video_id' => $video->id,
            'user_id' => $reporter->id,
            'reason' => 'harassment',
            'details' => 'Reported live content.',
            'status' => 'pending',
        ], $attributes));
    }

    public function test_non_admin_users_cannot_access_admin_live_stream_routes(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/live-streams')
            ->assertForbidden()
            ->assertJsonPath('message', trans('messages.admin.access_denied'));
    }

    public function test_admin_can_list_search_and_filter_live_streams(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create(['name' => 'Ada Lovelace', 'username' => 'ada.dev']);
        $other = User::factory()->create(['name' => 'Bob Stone', 'username' => 'bob.s']);

        $live = $this->makeLiveVideo($creator, ['title' => 'Distinctive Live Show']);
        $this->makeLiveVideo($other, [
            'title' => 'Old Ended Show',
            'is_live' => false,
            'live_started_at' => now()->subDays(2),
            'live_ended_at' => now()->subDays(2)->addHour(),
        ]);

        $viewerA = User::factory()->create();
        $viewerB = User::factory()->create();
        $this->makePresence($live, $viewerA, 'audience');
        $this->makePresence($live, $viewerB, 'audience');

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/live-streams')
            ->assertOk()
            ->assertJsonPath('meta.summary.liveNow', 1)
            ->assertJsonPath('meta.summary.endedTotal', 1);

        $this->getJson('/api/admin/live-streams?status=live')
            ->assertOk()
            ->assertJsonCount(1, 'data.liveStreams')
            ->assertJsonPath('data.liveStreams.0.title', 'Distinctive Live Show')
            ->assertJsonPath('data.liveStreams.0.liveAnalytics.currentViewers', 2)
            ->assertJsonPath('data.liveStreams.0.liveAnalytics.peakViewers', 10);

        $this->getJson('/api/admin/live-streams?q=Ada')
            ->assertOk()
            ->assertJsonCount(1, 'data.liveStreams')
            ->assertJsonPath('data.liveStreams.0.id', $live->id);

        $this->getJson('/api/admin/live-streams?q=nonexistentcreator')
            ->assertOk()
            ->assertJsonCount(0, 'data.liveStreams');
    }

    public function test_admin_can_view_live_stream_details_with_audience_cohosts_and_violations(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $live = $this->makeLiveVideo($creator);

        $viewerA = User::factory()->create();
        $viewerB = User::factory()->create();
        $coHost = User::factory()->create(['name' => 'Guest Host', 'username' => 'guest.host']);

        $this->makePresence($live, $viewerA, 'audience');
        $this->makePresence($live, $viewerB, 'audience');
        $this->makePresence($live, $coHost, 'host');
        $this->makePresence($live, $creator, 'host');

        $reporter = User::factory()->create();
        $this->makeReport($live, $reporter, ['reason' => 'nudity']);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/live-streams/'.$live->public_id)
            ->assertOk()
            ->assertJsonPath('data.stream.id', $live->id)
            ->assertJsonCount(2, 'data.audience')
            ->assertJsonCount(1, 'data.coHosts')
            ->assertJsonPath('data.coHosts.0.user.username', 'guest.host')
            ->assertJsonPath('data.violations.counts.total', 1)
            ->assertJsonPath('data.violations.counts.pending', 1)
            ->assertJsonPath('data.violations.reports.0.reason', 'nudity');
    }

    public function test_admin_stop_updates_state_preserves_analytics_notifies_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $live = $this->makeLiveVideo($creator, [
            'live_started_at' => now()->subHour(),
            'live_peak_viewers_count' => 42,
            'live_comments_count' => 17,
        ]);

        $this->makePresence($live, User::factory()->create(), 'audience');
        $this->makePresence($live, User::factory()->create(), 'audience');

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/live-streams/'.$live->public_id.'/stop', ['reason' => 'Policy breach'])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.admin.live_stream_stopped'))
            ->assertJsonPath('data.viewersDisconnected', 2)
            ->assertJsonPath('data.video.isLive', false);

        $this->assertDatabaseHas('videos', [
            'id' => $live->id,
            'is_live' => false,
            'live_peak_viewers_count' => 42,
            'live_comments_count' => 17,
        ]);
        $this->assertNotNull($live->fresh()->live_ended_at);
        $this->assertSame(0, LivePresenceSession::query()->where('video_id', $live->id)->whereNull('left_at')->count());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.live_stream_stopped',
            'auditable_id' => $live->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'moderation',
            'title' => trans('messages.notifications.live_stopped_title'),
        ]);
    }

    public function test_admin_can_remove_viewer_from_live_stream(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $live = $this->makeLiveVideo($creator);
        $viewer = User::factory()->create();
        $session = $this->makePresence($live, $viewer, 'audience');

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/live-streams/'.$live->public_id.'/viewers/remove', ['sessionId' => $session->id])
            ->assertOk()
            ->assertJsonCount(0, 'data.audience');

        $this->assertNotNull($session->fresh()->left_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.live_viewer_removed',
            'auditable_id' => $live->id,
        ]);
    }

    public function test_admin_can_remove_cohost_but_not_the_creator(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $live = $this->makeLiveVideo($creator);

        $coHost = User::factory()->create();
        $coHostSession = $this->makePresence($live, $coHost, 'host');
        $creatorSession = $this->makePresence($live, $creator, 'host');
        LiveSignal::query()->create([
            'video_id' => $live->id,
            'sender_id' => $coHost->id,
            'recipient_id' => $creator->id,
            'kind' => 'join_request_accepted',
            'payload' => [],
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/live-streams/'.$live->public_id.'/co-hosts/remove', ['sessionId' => $coHostSession->id])
            ->assertOk()
            ->assertJsonCount(0, 'data.coHosts');

        $this->assertNotNull($coHostSession->fresh()->left_at);
        $this->assertSame(0, LiveSignal::query()->where('video_id', $live->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.live_cohost_removed',
            'auditable_id' => $live->id,
        ]);

        $this->postJson('/api/admin/live-streams/'.$live->public_id.'/co-hosts/remove', ['sessionId' => $creatorSession->id])
            ->assertStatus(422);
    }

    public function test_admin_can_restrict_creator_of_live_stream(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create(['account_status' => 'active']);
        $live = $this->makeLiveVideo($creator);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/live-streams/'.$live->public_id.'/restrict-creator', ['notes' => 'Repeated violations'])
            ->assertOk()
            ->assertJsonPath('data.creator.accountStatus', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $creator->id,
            'account_status' => 'suspended',
            'suspended_by' => $admin->id,
        ]);
        $this->assertFalse((bool) $live->fresh()->is_live);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.live_creator_restricted',
            'auditable_id' => $creator->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $creator->id,
            'type' => 'moderation',
            'title' => trans('messages.notifications.account_restricted_title'),
        ]);
    }

    public function test_admin_can_review_live_violations_and_apply_moderation(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->create();
        $live = $this->makeLiveVideo($creator);
        $reporter = User::factory()->create();
        $this->makeReport($live, $reporter, ['reason' => 'harassment']);
        $this->makeReport($live, User::factory()->create(), ['reason' => 'spam']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/live-streams/'.$live->public_id.'/review-violations', [
            'action' => 'restrict',
            'notes' => 'Confirmed abuse',
            'resolveReports' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.violations.counts.pending', 0)
            ->assertJsonPath('data.violations.moderationStatus', 'restricted');

        $this->assertDatabaseHas('videos', [
            'id' => $live->id,
            'moderation_status' => 'restricted',
        ]);
        $this->assertSame(0, VideoReport::query()->where('video_id', $live->id)->where('status', 'pending')->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.live_violations_reviewed',
            'auditable_id' => $live->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'admin.video_restricted',
            'auditable_id' => $live->id,
        ]);
    }
}
