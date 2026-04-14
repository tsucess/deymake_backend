<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\CreatorPlan;
use App\Models\Membership;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreatorAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_fetch_dashboard_analytics_summary_top_content_and_live_metrics(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $creator = User::factory()->create(['name' => 'Analytics Creator', 'username' => 'analytics.creator']);
        $fan = User::factory()->create(['name' => 'Top Fan', 'username' => 'top.fan']);
        $subscriber = User::factory()->create(['name' => 'Recent Subscriber', 'username' => 'recent.subscriber']);
        $olderSubscriber = User::factory()->create(['name' => 'Older Subscriber', 'username' => 'older.subscriber']);

        $topVideo = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Top Campus Performance',
            'thumbnail_url' => 'https://cdn.example.com/top.jpg',
            'is_draft' => false,
            'views_count' => 220,
            'shares_count' => 4,
        ]);

        $liveVideo = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Late Night Live',
            'thumbnail_url' => 'https://cdn.example.com/live.jpg',
            'is_draft' => false,
            'is_live' => true,
            'views_count' => 70,
            'shares_count' => 2,
            'live_started_at' => now()->subHour(),
            'live_peak_viewers_count' => 9,
        ]);

        Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Draft Cut',
            'is_draft' => true,
            'views_count' => 5,
            'shares_count' => 0,
        ]);

        DB::table('video_interactions')->insert([
            [
                'video_id' => $topVideo->id,
                'user_id' => $fan->id,
                'type' => 'like',
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ],
            [
                'video_id' => $topVideo->id,
                'user_id' => $subscriber->id,
                'type' => 'like',
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'video_id' => $topVideo->id,
                'user_id' => $fan->id,
                'type' => 'save',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'video_id' => $liveVideo->id,
                'user_id' => $fan->id,
                'type' => 'like',
                'created_at' => now()->subDays(3),
                'updated_at' => now()->subDays(3),
            ],
        ]);

        Comment::create([
            'video_id' => $topVideo->id,
            'user_id' => $fan->id,
            'body' => 'This performance is wild',
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ]);

        Comment::create([
            'video_id' => $liveVideo->id,
            'user_id' => $fan->id,
            'body' => 'Live is fire',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('live_like_events')->insert([
            [
                'video_id' => $liveVideo->id,
                'user_id' => $fan->id,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'video_id' => $liveVideo->id,
                'user_id' => $subscriber->id,
                'created_at' => now()->subHours(10),
                'updated_at' => now()->subHours(10),
            ],
        ]);

        $creator->subscribers()->attach($subscriber->id, ['created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $creator->subscribers()->attach($olderSubscriber->id, ['created_at' => now()->subDays(45), 'updated_at' => now()->subDays(45)]);

        $plan = CreatorPlan::create([
            'creator_id' => $creator->id,
            'name' => 'Inner Circle',
            'price_amount' => 5000,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'is_active' => true,
        ]);

        Membership::create([
            'creator_plan_id' => $plan->id,
            'creator_id' => $creator->id,
            'member_id' => $fan->id,
            'status' => 'active',
            'price_amount' => 5000,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'started_at' => now()->subDays(7),
        ]);

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/me/analytics?period=30d&limit=3')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.analytics.dashboard_retrieved'))
            ->assertJsonPath('data.overview.videos.total', 3)
            ->assertJsonPath('data.overview.videos.published', 2)
            ->assertJsonPath('data.overview.videos.drafts', 1)
            ->assertJsonPath('data.overview.engagement.views', 295)
            ->assertJsonPath('data.overview.engagement.shares', 6)
            ->assertJsonPath('data.overview.engagement.likes', 5)
            ->assertJsonPath('data.overview.engagement.saves', 1)
            ->assertJsonPath('data.overview.engagement.comments', 2)
            ->assertJsonPath('data.overview.audience.subscribers', 2)
            ->assertJsonPath('data.overview.audience.newSubscribers', 1)
            ->assertJsonPath('data.overview.audience.activeMemberships', 1)
            ->assertJsonPath('data.overview.audience.estimatedMonthlyRevenue', 5000)
            ->assertJsonPath('data.topVideos.0.id', $topVideo->id)
            ->assertJsonPath('data.audience.topSupporters.0.user.id', $fan->id)
            ->assertJsonPath('data.live.videosCount', 1)
            ->assertJsonPath('data.live.totalLiveLikes', 2)
            ->assertJsonPath('data.live.totalLiveComments', 1)
            ->assertJsonPath('data.live.peakViewers', 9)
            ->assertJsonPath('data.live.bestVideo.id', $liveVideo->id);
    }

    public function test_creator_can_fetch_owned_video_analytics_detail(): void
    {
        $category = Category::create(['name' => 'Comedy', 'slug' => 'comedy']);
        $creator = User::factory()->create(['name' => 'Video Owner', 'username' => 'video.owner']);
        $reactor = User::factory()->create(['name' => 'Best Reactor', 'username' => 'best.reactor']);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Hostel Skit',
            'is_draft' => false,
            'is_live' => true,
            'views_count' => 90,
            'shares_count' => 3,
            'live_peak_viewers_count' => 11,
            'live_started_at' => now()->subHour(),
        ]);

        DB::table('video_interactions')->insert([
            'video_id' => $video->id,
            'user_id' => $reactor->id,
            'type' => 'like',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('video_interactions')->insert([
            'video_id' => $video->id,
            'user_id' => $reactor->id,
            'type' => 'save',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        Comment::create([
            'video_id' => $video->id,
            'user_id' => $reactor->id,
            'body' => 'Pinned this one',
            'created_at' => now()->subHours(5),
            'updated_at' => now()->subHours(5),
        ]);

        DB::table('live_like_events')->insert([
            [
                'video_id' => $video->id,
                'user_id' => $reactor->id,
                'created_at' => now()->subHours(5),
                'updated_at' => now()->subHours(5),
            ],
            [
                'video_id' => $video->id,
                'user_id' => $reactor->id,
                'created_at' => now()->subHours(4),
                'updated_at' => now()->subHours(4),
            ],
        ]);

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/me/analytics/videos/'.$video->id.'?period=30d')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.analytics.video_retrieved'))
            ->assertJsonPath('data.video.id', $video->id)
            ->assertJsonPath('data.summary.lifetime.views', 90)
            ->assertJsonPath('data.summary.lifetime.shares', 3)
            ->assertJsonPath('data.summary.lifetime.likes', 1)
            ->assertJsonPath('data.summary.lifetime.saves', 1)
            ->assertJsonPath('data.summary.lifetime.comments', 1)
            ->assertJsonPath('data.summary.lifetime.liveLikes', 2)
            ->assertJsonPath('data.summary.lifetime.peakViewers', 11)
            ->assertJsonPath('data.summary.periodMetrics.likes', 1)
            ->assertJsonPath('data.summary.periodMetrics.saves', 1)
            ->assertJsonPath('data.summary.periodMetrics.comments', 1)
            ->assertJsonPath('data.summary.periodMetrics.liveLikes', 2)
            ->assertJsonPath('data.audience.topReactors.0.user.id', $reactor->id)
            ->assertJsonPath('data.audience.topReactors.0.engagements', 4);
    }
}