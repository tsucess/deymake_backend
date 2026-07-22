<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Upload;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_own_draft_video(): void
    {
        $creator = User::factory()->create();
        $video = $this->makeVideo($creator, isDraft: true);

        Sanctum::actingAs($creator);

        $this->deleteJson('/api/v1/videos/'.$video->id)
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    }

    public function test_owner_can_delete_own_published_video(): void
    {
        $creator = User::factory()->create();
        $video = $this->makeVideo($creator, isDraft: false);

        Sanctum::actingAs($creator);

        $this->deleteJson('/api/v1/videos/'.$video->id)->assertOk();

        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
    }

    public function test_non_owner_cannot_delete_video(): void
    {
        $creator = User::factory()->create();
        $intruder = User::factory()->create();
        $video = $this->makeVideo($creator, isDraft: true);

        Sanctum::actingAs($intruder);

        $this->deleteJson('/api/v1/videos/'.$video->id)->assertForbidden();

        $this->assertDatabaseHas('videos', ['id' => $video->id]);
    }

    public function test_unauthenticated_delete_is_rejected(): void
    {
        $creator = User::factory()->create();
        $video = $this->makeVideo($creator, isDraft: true);

        $this->deleteJson('/api/v1/videos/'.$video->id)->assertUnauthorized();

        $this->assertDatabaseHas('videos', ['id' => $video->id]);
    }

    private function makeVideo(User $user, bool $isDraft): Video
    {
        $category = Category::firstOrCreate(['slug' => 'music'], ['name' => 'Music']);
        $upload = Upload::create([
            'user_id' => $user->id,
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-'.$user->id.'/clip.mp4',
            'original_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'ready',
        ]);

        return Video::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'upload_id' => $upload->id,
            'type' => 'video',
            'title' => 'Delete me',
            'caption' => 'delete me',
            'description' => 'delete me',
            'is_draft' => $isDraft,
            'moderation_status' => $isDraft ? 'pending' : 'visible',
        ]);
    }
}
