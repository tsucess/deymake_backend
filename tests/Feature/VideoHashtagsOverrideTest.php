<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Upload;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoHashtagsOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_uses_client_hashtags_over_description_extraction(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $creator = User::factory()->create();
        $upload = $this->makeUpload($creator);

        Sanctum::actingAs($creator);

        $response = $this->postJson('/api/v1/videos', [
            'uploadId' => $upload->id,
            'categoryId' => $category->id,
            'type' => 'video',
            'title' => 'Overridden Tags',
            'description' => 'auto tags in here #ignored #extraction',
            'hashtags' => ['Nollywood', '#afrobeats', 'lagos'],
            'isDraft' => false,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.video.hashtags', ['nollywood', 'afrobeats', 'lagos']);

        $videoId = $response->json('data.video.id');
        $this->assertSame(
            ['nollywood', 'afrobeats', 'lagos'],
            Video::findOrFail($videoId)->hashtags,
        );
    }

    public function test_store_falls_back_to_description_extraction_when_no_override(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $creator = User::factory()->create();
        $upload = $this->makeUpload($creator);

        Sanctum::actingAs($creator);

        $response = $this->postJson('/api/v1/videos', [
            'uploadId' => $upload->id,
            'categoryId' => $category->id,
            'type' => 'video',
            'title' => 'Auto Tags',
            'description' => 'Loving this #Nollywood scene with #afrobeats',
            'isDraft' => false,
        ]);

        $response->assertCreated();
        $hashtags = $response->json('data.video.hashtags');

        $this->assertIsArray($hashtags);
        $this->assertContains('nollywood', $hashtags);
        $this->assertContains('afrobeats', $hashtags);
    }

    public function test_store_normalizes_and_filters_invalid_hashtags(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $creator = User::factory()->create();
        $upload = $this->makeUpload($creator);

        Sanctum::actingAs($creator);

        $response = $this->postJson('/api/v1/videos', [
            'uploadId' => $upload->id,
            'categoryId' => $category->id,
            'type' => 'video',
            'title' => 'Filter Tags',
            'hashtags' => ['GoodTag', '#GoodTag', 'a', 'has space', 'has-dash', '  spaced  ', 'valid_tag'],
            'isDraft' => false,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.video.hashtags', ['goodtag', 'spaced', 'valid_tag']);
    }

    public function test_store_rejects_hashtags_with_too_many_items(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $creator = User::factory()->create();
        $upload = $this->makeUpload($creator);

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/videos', [
            'uploadId' => $upload->id,
            'categoryId' => $category->id,
            'type' => 'video',
            'title' => 'Too Many Tags',
            'hashtags' => array_map(static fn (int $i) => 'tag'.$i, range(1, 31)),
            'isDraft' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['hashtags']);
    }

    public function test_update_override_replaces_existing_hashtags(): void
    {
        $creator = User::factory()->create();
        $video = Video::create([
            'user_id' => $creator->id,
            'type' => 'video',
            'title' => 'Original',
            'description' => 'a #nollywood clip',
            'hashtags' => ['nollywood'],
            'is_draft' => false,
        ]);

        Sanctum::actingAs($creator);

        $this->patchJson('/api/v1/videos/'.$video->id, [
            'hashtags' => ['naijamusic', '#lagos'],
        ])
            ->assertOk()
            ->assertJsonPath('data.video.hashtags', ['naijamusic', 'lagos']);

        $this->assertSame(['naijamusic', 'lagos'], $video->fresh()->hashtags);
    }

    private function makeUpload(User $user): Upload
    {
        return Upload::create([
            'user_id' => $user->id,
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-'.$user->id.'/clip.mp4',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'ready',
        ]);
    }
}
