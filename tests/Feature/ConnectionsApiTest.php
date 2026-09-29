<?php

namespace Tests\Feature;

use App\Events\StoryPublished;
use App\Models\Category;
use App\Models\Story;
use App\Models\Upload;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
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

        $this->getJson('/api/connections/feed')
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

        $this->getJson('/api/connections/feed')
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

        $response = $this->getJson('/api/creators/suggestions')
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

        $response = $this->getJson('/api/stories/feed')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.stories.feed_retrieved'))
            ->assertJsonCount(2, 'data.stories');

        $this->assertSame(
            ['active', 'active'],
            collect($response->json('data.stories'))->pluck('status')->all(),
        );

        $urls = collect($response->json('data.stories'))->pluck('mediaUrl')->all();
        $this->assertContains('/a.jpg', $urls);
        $this->assertContains('/b.jpg', $urls);
    }

    public function test_stories_feed_does_not_hide_unexpired_stories_after_the_first_fifty(): void
    {
        $viewer = User::factory()->create();
        $creator = User::factory()->create();
        $viewer->subscribedCreators()->attach($creator->id);

        for ($index = 0; $index < 55; $index++) {
            Story::create([
                'user_id' => $creator->id,
                'type' => 'image',
                'media_url' => '/story-'.$index.'.jpg',
                'expires_at' => now()->addHours(24)->subMinutes($index),
                'created_at' => now()->subMinutes($index),
            ]);
        }

        Sanctum::actingAs($viewer);

        $this->getJson('/api/stories/feed')
            ->assertOk()
            ->assertJsonCount(55, 'data.stories');
    }

    public function test_publishing_a_story_broadcasts_to_the_author_and_followers(): void
    {
        Event::fake([StoryPublished::class]);

        $author = User::factory()->create();
        $follower = User::factory()->create();
        $stranger = User::factory()->create();
        $follower->subscribedCreators()->attach($author->id);

        Sanctum::actingAs($author);

        $response = $this->postJson('/api/stories', [
            'type' => 'text',
            'caption' => 'Fresh status',
            'backgroundColor' => '#123456',
        ])->assertCreated();

        $storyId = (int) $response->json('data.story.id');
        Event::assertDispatchedTimes(StoryPublished::class, 2);
        Event::assertDispatched(StoryPublished::class, fn (StoryPublished $event): bool =>
            $event->storyId === $storyId
            && $event->authorId === $author->id
            && in_array($event->recipientId, [$author->id, $follower->id], true)
        );
        Event::assertNotDispatched(StoryPublished::class, fn (StoryPublished $event): bool =>
            $event->recipientId === $stranger->id
        );
    }

    public function test_media_story_expires_24_hours_after_its_upload_time(): void
    {
        $author = User::factory()->create();
        $upload = Upload::create([
            'user_id' => $author->id,
            'type' => 'image',
            'disk' => 'public',
            'path' => 'stories/uploaded.jpg',
            'original_name' => 'uploaded.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ]);
        $uploadedAt = now()->subHours(23)->startOfSecond();
        $upload->forceFill(['created_at' => $uploadedAt])->saveQuietly();

        Sanctum::actingAs($author);

        $response = $this->postJson('/api/stories', [
            'type' => 'image',
            'mediaUrl' => '/stories/uploaded.jpg',
            'uploadId' => $upload->id,
        ])->assertCreated();

        $this->assertTrue(
            Carbon::parse($response->json('data.story.expiresAt'))
                ->equalTo($uploadedAt->copy()->addHours(24)),
        );
    }

    public function test_expired_scope_identifies_stories_outside_the_active_24_hour_period(): void
    {
        $author = User::factory()->create();
        $active = Story::create(['user_id' => $author->id, 'type' => 'image', 'media_url' => '/active.jpg', 'expires_at' => now()->addHour()]);
        $expired = Story::create(['user_id' => $author->id, 'type' => 'image', 'media_url' => '/expired.jpg', 'expires_at' => now()->subMinute()]);

        $this->assertTrue(Story::query()->active()->whereKey($active->id)->exists());
        $this->assertTrue(Story::query()->expired()->whereKey($expired->id)->exists());
        $this->assertSame('expired', (new \App\Http\Resources\StoryResource($expired))->toArray(request())['status']);
    }

    public function test_story_view_records_view_and_increments_counter(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $story = Story::create(['user_id' => $author->id, 'type' => 'image', 'media_url' => '/x.jpg', 'expires_at' => now()->addHours(20)]);

        Sanctum::actingAs($viewer);

        $this->postJson("/api/stories/{$story->id}/view")
            ->assertOk()
            ->assertJsonPath('data.story.views', 1)
            ->assertJsonPath('data.story.currentUserState.seen', true);

        $this->postJson("/api/stories/{$story->id}/view")->assertOk();
        $this->assertSame(1, $story->fresh()->views_count);
    }

    public function test_story_owner_can_delete_and_others_cannot(): void
    {
        $author = User::factory()->create();
        $stranger = User::factory()->create();
        $story = Story::create(['user_id' => $author->id, 'type' => 'image', 'media_url' => '/y.jpg', 'expires_at' => now()->addHours(20)]);

        Sanctum::actingAs($stranger);
        $this->deleteJson("/api/stories/{$story->id}")->assertForbidden();

        Sanctum::actingAs($author);
        $this->deleteJson("/api/stories/{$story->id}")->assertOk();
        $this->assertNull(Story::find($story->id));
    }

    public function test_story_owner_can_view_story_viewers_only(): void
    {
        $author = User::factory()->create();
        $viewer = User::factory()->create();
        $story = Story::create(['user_id' => $author->id, 'type' => 'image', 'media_url' => '/viewers.jpg', 'expires_at' => now()->addHours(20)]);

        Sanctum::actingAs($viewer);
        $this->postJson("/api/stories/{$story->id}/view")->assertOk();
        $this->getJson("/api/stories/{$story->id}/viewers")->assertForbidden();

        Sanctum::actingAs($author);
        $this->getJson("/api/stories/{$story->id}/viewers")
            ->assertOk()
            ->assertJsonPath('data.viewers.0.id', $viewer->id);
    }
}
