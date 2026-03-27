<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmokeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_public_endpoints_are_exposed(): void
    {
        $category = Category::create(['name' => 'Alpha Music', 'slug' => 'alpha-music']);
        Category::create(['name' => 'Zed Art', 'slug' => 'zed-art']);
        $creator = User::factory()->create(['name' => 'Alpha Creator', 'email' => 'alpha@example.com']);
        $otherCreator = User::factory()->create(['name' => 'Beta Creator', 'email' => 'beta@example.com']);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Alpha Anthem',
            'caption' => 'Alpha vibes',
            'description' => 'Alpha release',
            'is_draft' => false,
        ]);

        Video::create([
            'user_id' => $otherCreator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Draft Only',
            'is_draft' => true,
        ]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data.categories');

        $this->getJson('/api/v1/videos?category=alpha-music')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.id', $video->id);

        $this->getJson('/api/v1/search/suggestions?q=Alpha')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $video->id)
            ->assertJsonPath('data.creators.0.fullName', $creator->name)
            ->assertJsonPath('data.categories.0.slug', $category->slug);

        $this->getJson('/api/v1/search/videos?q=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.id', $video->id);

        $this->getJson('/api/v1/search/creators?q=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data.creators')
            ->assertJsonPath('data.creators.0.fullName', $creator->name);

        $this->getJson('/api/v1/search/categories?q=Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonPath('data.categories.0.slug', $category->slug);

        $this->getJson('/api/v1/help')
            ->assertOk()
            ->assertJsonPath('data.title', 'Help Center');

        $this->getJson('/api/v1/legal/privacy')
            ->assertOk()
            ->assertJsonPath('data.title', 'Privacy Policy');

        $this->getJson('/api/v1/legal/terms')
            ->assertOk()
            ->assertJsonPath('data.title', 'Terms of Service');
    }

    public function test_remaining_authenticated_endpoints_are_exposed(): void
    {
        $subscriber = User::factory()->create(['name' => 'Subscriber']);
        $creator = User::factory()->create(['name' => 'Creator']);

        Sanctum::actingAs($subscriber);

        $this->postJson('/api/v1/uploads/presign', [
            'type' => 'video',
            'originalName' => 'clip.mp4',
        ])->assertOk()
            ->assertJsonPath('data.strategy', 'server-upload')
            ->assertJsonPath('data.provider', 'cloudinary')
            ->assertJsonPath('data.method', 'POST')
            ->assertJsonPath('data.endpoint', url('/api/v1/uploads'))
            ->assertJsonPath('data.path', fn ($value) => is_string($value)
                && str_starts_with($value, 'deymake/uploads/')
                && str_ends_with($value, '.mp4'));

        $this->postJson('/api/v1/creators/'.$creator->id.'/subscribe')
            ->assertOk()
            ->assertJsonPath('data.creator.subscribed', true)
            ->assertJsonPath('data.creator.subscriberCount', 1);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $subscriber->id,
            'creator_id' => $creator->id,
        ]);

        $this->deleteJson('/api/v1/creators/'.$creator->id.'/subscribe')
            ->assertOk()
            ->assertJsonPath('data.creator.subscribed', false)
            ->assertJsonPath('data.creator.subscriberCount', 0);

        $this->assertDatabaseMissing('subscriptions', [
            'user_id' => $subscriber->id,
            'creator_id' => $creator->id,
        ]);
    }
}