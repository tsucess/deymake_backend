<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExploreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_explore_index_returns_hashtags_rising_creators_and_top_videos(): void
    {
        $music = Category::create(['name' => 'Music', 'slug' => 'music', 'subscribers_count' => 0]);
        $dance = Category::create(['name' => 'Dance', 'slug' => 'dance', 'subscribers_count' => 0]);

        $topCreator = User::factory()->create(['name' => 'Top Creator']);
        $quietCreator = User::factory()->create(['name' => 'Quiet Creator']);
        $fan = User::factory()->create();

        $topVideo = Video::create([
            'user_id' => $topCreator->id,
            'category_id' => $music->id,
            'type' => 'video',
            'title' => 'Big Hit',
            'description' => 'Loving this #Afrobeats #Naija vibe today',
            'is_draft' => false,
            'moderation_status' => 'visible',
            'views_count' => 500,
        ]);

        Video::create([
            'user_id' => $quietCreator->id,
            'category_id' => $dance->id,
            'type' => 'video',
            'title' => 'Quiet Clip',
            'description' => 'Just a #Dance step',
            'is_draft' => false,
            'moderation_status' => 'visible',
            'views_count' => 5,
        ]);

        DB::table('video_interactions')->insert([
            ['video_id' => $topVideo->id, 'user_id' => $fan->id, 'type' => 'like', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Comment::create([
            'video_id' => $topVideo->id,
            'user_id' => $fan->id,
            'body' => 'Fire!',
            'moderation_status' => 'visible',
        ]);

        $response = $this->getJson('/api/v1/explore')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.explore.retrieved'))
            ->assertJsonStructure([
                'data' => [
                    'categories',
                    'hero',
                    'trendingHashtags',
                    'risingCreators',
                    'topVideos',
                ],
            ]);

        $tags = collect($response->json('data.trendingHashtags'))->pluck('tag')->all();
        $this->assertContains('#Afrobeats', $tags);
        $this->assertContains('#Naija', $tags);

        $risingNames = collect($response->json('data.risingCreators'))
            ->pluck('profile.fullName')
            ->all();
        $this->assertContains('Top Creator', $risingNames);

        $topVideoTitles = collect($response->json('data.topVideos'))->pluck('title')->all();
        $this->assertSame('Big Hit', $topVideoTitles[0] ?? null);
    }

    public function test_explore_videos_filters_by_category_and_paginates(): void
    {
        $music = Category::create(['name' => 'Music', 'slug' => 'music', 'subscribers_count' => 0]);
        $tech = Category::create(['name' => 'Tech', 'slug' => 'tech', 'subscribers_count' => 0]);

        $creator = User::factory()->create();

        Video::create([
            'user_id' => $creator->id,
            'category_id' => $music->id,
            'type' => 'video',
            'title' => 'Music Video',
            'is_draft' => false,
            'moderation_status' => 'visible',
            'views_count' => 100,
        ]);

        Video::create([
            'user_id' => $creator->id,
            'category_id' => $tech->id,
            'type' => 'video',
            'title' => 'Tech Video',
            'is_draft' => false,
            'moderation_status' => 'visible',
            'views_count' => 200,
        ]);

        $response = $this->getJson('/api/v1/explore/videos?category=music')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.explore.videos_retrieved'))
            ->assertJsonPath('data.activeCategory.slug', 'music');

        $titles = collect($response->json('data.videos'))->pluck('title')->all();
        $this->assertContains('Music Video', $titles);
        $this->assertNotContains('Tech Video', $titles);
    }

    public function test_explore_trending_tab_returns_global_results(): void
    {
        $music = Category::create(['name' => 'Music', 'slug' => 'music', 'subscribers_count' => 0]);
        $creator = User::factory()->create();

        Video::create([
            'user_id' => $creator->id,
            'category_id' => $music->id,
            'type' => 'video',
            'title' => 'Global Hit',
            'is_draft' => false,
            'moderation_status' => 'visible',
            'views_count' => 50,
        ]);

        $this->getJson('/api/v1/explore?category=trending')
            ->assertOk()
            ->assertJsonPath('data.activeCategory', null);
    }
}
