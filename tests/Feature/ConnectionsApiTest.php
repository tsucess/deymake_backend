<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Story;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_connections_feed_returns_videos_from_subscribed_creators(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music', 'subscribers_count' => 100]);
        $viewer = User::factory()->create();
        $subscribed = User::factory()->create(['name' => 'Subbed Creator']);
        $other = User::factory()->create(['name' => 'Other Creator']);

        $viewer->subscribedCreators()->attach($subscribed->id);

        Video::create([
            'user_id' => $subscribed->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'From Subbed',
            'is_draft' => false,
        ]);

        Video::create([
            'user_id' => $other->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'From Other',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/connections/feed')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.connections.feed_retrieved'))
            ->assertJsonPath('data.source', 'subscriptions')
            ->assertJsonPath('data.videos.0.title', 'From Subbed')
            ->assertJsonCount(1, 'data.videos');
    }

    public function test_connections_feed_falls_back_to_trending_when_no_subscriptions(): void
    {
        $category = Category::create(['name' => 'Vlogs', 'slug' => 'vlogs', 'subscribers_count' => 10]);
        $viewer = User::factory()->create();
        $author = User::factory()->create();

        Video::create([
            'user_id' => $author->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Trending Clip',
            'is_draft' => false,
            'views_count' => 999,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/connections/feed')
            ->assertOk()
            ->assertJsonPath('data.source', 'trending')
            ->assertJsonPath('data.videos.0.title', 'Trending Clip');
    }

    public function test_creator_suggestions_use_second_degree_graph_then_fallback(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();
        $mutualCreator = User::factory()->create(['name' => 'Mutual Creator']);
        $randomCreator = User::factory()->create(['name' => 'Random Creator']);

        $viewer->subscribedCreators()->attach($friend->id);
        $friend->subscribedCreators()->attach($mutualCreator->id);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/creators/suggestions')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.creators.suggestions_retrieved'));

        $names = collect($response->json('data.creators'))->pluck('fullName')->all();
        $this->assertContains('Mutual Creator', $names);
        $this->assertContains('Random Creator', $names);
        $this->assertNotContains($friend->name, $names);
        $this->assertNotContains($viewer->name, $names);
    }

    public function test_stories_feed_returns_active_stories_from_self_and_subscribed(): void
    {
        $viewer = User::factory()->create();
        $subbed = User::factory()->create();
        $stranger = User::factory()->create();
        $viewer->subscribedCreators()->attach($subbed->id);

        Story::create(['user_id' => $viewer->id, 'type' => 'image', 'media_url' => '/a.jpg', 'expires_at' => now()->addHours(20)]);
        Story::create(['user_id' => $subbed->id, 'type' => 'image', 'media_url' => '/b.jpg', 'expires_at' => now()->addHours(20)]);
        Story::create(['user_id' => $stranger->id, 'type' => 'image', 'media_url' => '/c.jpg', 'expires_at' => now()->addHours(20)]);
        Story::create(['user_id' => $subbed->id, 'type' => 'image', 'media_url' => '/expired.jpg', 'expires_at' => now()->subHour()]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/v1/stories/feed')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.stories.feed_retrieved'))
            ->assertJsonCount(2, 'data.stories');

        $urls = collect($response->json('data.stories'))->pluck('mediaUrl')->all();
        $this->assertContains('/a.jpg', $urls);
        $this->assertContains('/b.jpg', $urls);
    }

    public function test_story_view_records_view_and_increments_counter(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $story = Story::create(['user_id' => $author->id, 'type' => 'image', 'media_url' => '/x.jpg', 'expires_at' => now()->addHours(20)]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/v1/stories/{$story->id}/view")
            ->assertOk()
            ->assertJsonPath('data.story.views', 1)
            ->assertJsonPath('data.story.currentUserState.seen', true);

        $this->postJson("/api/v1/stories/{$story->id}/view")->assertOk();
        $this->assertSame(1, $story->fresh()->views_count);
    }

    public function test_story_owner_can_delete_and_others_cannot(): void
    {
        $author = User::factory()->create();
        $stranger = User::factory()->create();
        $story = Story::create(['user_id' => $author->id, 'type' => 'image', 'media_url' => '/y.jpg', 'expires_at' => now()->addHours(20)]);

        Sanctum::actingAs($stranger);
        $this->deleteJson("/api/v1/stories/{$story->id}")->assertForbidden();

        Sanctum::actingAs($author);
        $this->deleteJson("/api/v1/stories/{$story->id}")->assertOk();
        $this->assertNull(Story::find($story->id));
    }
}
