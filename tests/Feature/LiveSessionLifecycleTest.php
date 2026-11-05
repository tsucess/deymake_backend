<?php

namespace Tests\Feature;

use App\Events\LiveEnded;
use App\Models\LivePresenceSession;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_leaving_presence_ends_the_stream_and_disconnects_other_participants(): void
    {
        Event::fake([LiveEnded::class]);

        $host = User::factory()->create();
        $viewer = User::factory()->create();
        $video = $this->makeLiveVideo($host);
        $this->makePresence($video, $viewer, 'audience', 'viewer-session');

        Sanctum::actingAs($host);

        $this->postJson('/api/videos/'.$video->id.'/live/presence', [
            'sessionKey' => 'host-session',
            'role' => 'host',
        ])->assertOk();

        $this->postJson('/api/videos/'.$video->id.'/live/presence/leave', [
            'sessionKey' => 'host-session',
        ])->assertOk();

        $this->assertFalse((bool) $video->fresh()->is_live);
        $this->assertNotNull($video->livePresenceSessions()->where('session_key', 'viewer-session')->value('left_at'));
        Event::assertDispatchedTimes(LiveEnded::class, 1);
    }

    public function test_scheduled_sweep_ends_only_streams_with_expired_host_presence(): void
    {
        Event::fake([LiveEnded::class]);

        $host = User::factory()->create();
        $staleVideo = $this->makeLiveVideo($host);
        $activeVideo = $this->makeLiveVideo($host);
        $staleVideo->forceFill(['is_live' => false, 'is_draft' => true])->save();
        Sanctum::actingAs($host);
        $this->postJson('/api/videos/'.$staleVideo->id.'/live/start')->assertOk();
        $staleHostPresence = $staleVideo->livePresenceSessions()->where('role', 'host')->firstOrFail();
        $staleHostPresence->forceFill(['last_seen_at' => now()->subMinute()])->save();
        $this->makePresence($activeVideo, $host, 'host', 'active-host');

        $this->artisan('videos:end-stale-live-streams')
            ->expectsOutput('Ended 1 livestream(s) with an inactive host.')
            ->assertSuccessful();

        $this->assertFalse((bool) $staleVideo->fresh()->is_live);
        $this->assertTrue((bool) $activeVideo->fresh()->is_live);
        Event::assertDispatchedTimes(LiveEnded::class, 1);
    }

    private function makeLiveVideo(User $host): Video
    {
        return Video::query()->create([
            'user_id' => $host->id,
            'type' => 'video',
            'title' => 'Live Session',
            'is_draft' => false,
            'is_live' => true,
            'live_started_at' => now()->subMinutes(5),
        ]);
    }

    private function makePresence(Video $video, User $user, string $role, string $sessionKey, array $attributes = []): LivePresenceSession
    {
        return LivePresenceSession::query()->create(array_merge([
            'video_id' => $video->id,
            'user_id' => $user->id,
            'session_key' => $sessionKey,
            'role' => $role,
            'joined_at' => now()->subMinutes(2),
            'last_seen_at' => now(),
        ], $attributes));
    }
}
