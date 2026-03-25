<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\Upload;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentAndProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_discovery_search_and_public_profile_endpoints_return_data(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music', 'subscribers_count' => 1200]);
        $creator = User::factory()->create(['name' => 'Creator One', 'email' => 'creator@example.com']);
        $otherCreator = User::factory()->create(['name' => 'Creator Two', 'email' => 'creator2@example.com']);

        $mainVideo = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Alpha Hit',
            'caption' => 'Top track',
            'description' => 'Alpha release',
            'is_draft' => false,
            'views_count' => 500,
        ]);

        $relatedVideo = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Beta Hit',
            'is_draft' => false,
            'views_count' => 200,
        ]);

        $liveVideo = Video::create([
            'user_id' => $otherCreator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Live Set',
            'is_live' => true,
            'is_draft' => false,
            'views_count' => 300,
        ]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.categories.0.slug', 'music');

        $this->getJson('/api/v1/videos/trending')
            ->assertOk()
            ->assertJsonCount(3, 'data.videos');

        $this->getJson('/api/v1/videos/live')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $liveVideo->id);

        $this->getJson('/api/v1/videos/'.$mainVideo->id)
            ->assertOk()
            ->assertJsonPath('data.video.author.fullName', 'Creator One');

        $this->getJson('/api/v1/videos/'.$mainVideo->id.'/related')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $relatedVideo->id);

        $this->postJson('/api/v1/videos/'.$mainVideo->id.'/view')
            ->assertOk()
            ->assertJsonPath('data.views', 501);

        $this->postJson('/api/v1/videos/'.$mainVideo->id.'/share')
            ->assertOk()
            ->assertJsonPath('data.shares', 1);

        $this->getJson('/api/v1/search?q=Alpha')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $mainVideo->id);

        $this->getJson('/api/v1/leaderboard?period=monthly')
            ->assertOk()
            ->assertJsonPath('data.standings.0.user.fullName', 'Creator One');

        $this->getJson('/api/v1/users/search?q=Creator')
            ->assertOk()
            ->assertJsonCount(2, 'data.users');

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.user.fullName', 'Creator One');

        $this->getJson('/api/v1/users/'.$creator->id.'/posts')
            ->assertOk()
            ->assertJsonCount(2, 'data.videos');
    }

    public function test_authenticated_user_can_manage_uploads_videos_engagement_profile_and_notifications(): void
    {
        Storage::fake('public');

        $category = Category::create(['name' => 'Comedy', 'slug' => 'comedy']);
        $creator = User::factory()->create(['name' => 'Creator', 'email' => 'creator@example.com']);
        $viewer = User::factory()->create(['name' => 'Viewer', 'email' => 'viewer@example.com']);

        $creatorVideo = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Creator Clip',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($viewer);

        $uploadResponse = $this->postJson('/api/v1/uploads', [
            'file' => UploadedFile::fake()->image('poster.jpg'),
        ]);

        $uploadResponse->assertCreated();
        $uploadId = $uploadResponse->json('data.upload.id');
        Storage::disk('public')->assertExists(Upload::query()->findOrFail($uploadId)->path);

        $videoResponse = $this->postJson('/api/v1/videos', [
            'uploadId' => $uploadId,
            'categoryId' => $category->id,
            'type' => 'image',
            'caption' => 'Draft caption',
            'isDraft' => true,
        ]);

        $videoId = $videoResponse->json('data.video.id');

        $this->patchJson('/api/v1/videos/'.$videoId, ['title' => 'Viewer Draft'])
            ->assertOk()
            ->assertJsonPath('data.video.title', 'Viewer Draft');

        $this->postJson('/api/v1/videos/'.$videoId.'/publish')
            ->assertOk()
            ->assertJsonPath('data.video.isDraft', false);

        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/like')->assertOk();
        $this->deleteJson('/api/v1/videos/'.$creatorVideo->id.'/like')->assertOk();
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/dislike')->assertOk();
        $this->deleteJson('/api/v1/videos/'.$creatorVideo->id.'/dislike')->assertOk();
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/save')->assertOk();
        $this->deleteJson('/api/v1/videos/'.$creatorVideo->id.'/save')->assertOk();
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/like')->assertOk();
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/save')->assertOk();
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/report', ['reason' => 'spam'])->assertCreated();
        $this->postJson('/api/v1/creators/'.$creator->id.'/subscribe')->assertOk();

        $commentResponse = $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/comments', [
            'body' => 'Great post!',
        ]);

        $commentId = $commentResponse->json('data.comment.id');

        $this->getJson('/api/v1/videos/'.$creatorVideo->id.'/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data.comments');

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.fullName', 'Viewer');

        $this->patchJson('/api/v1/me/profile', ['fullName' => 'Viewer Updated', 'bio' => 'Updated bio'])
            ->assertOk()
            ->assertJsonPath('data.profile.fullName', 'Viewer Updated');

        $this->getJson('/api/v1/me/posts')->assertOk()->assertJsonCount(1, 'data.videos');
        $this->getJson('/api/v1/me/liked')->assertOk()->assertJsonCount(1, 'data.videos');
        $this->getJson('/api/v1/me/saved')->assertOk()->assertJsonCount(1, 'data.videos');
        $this->getJson('/api/v1/me/drafts')->assertOk()->assertJsonCount(0, 'data.videos');

        $this->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->assertJsonPath('data.preferences.language', 'en');

        $this->patchJson('/api/v1/me/preferences', ['language' => 'fr'])
            ->assertOk()
            ->assertJsonPath('data.preferences.language', 'fr');

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/comments/'.$commentId.'/replies', ['body' => 'Thanks!'])
            ->assertCreated();

        $this->postJson('/api/v1/comments/'.$commentId.'/like')->assertOk();
        $this->deleteJson('/api/v1/comments/'.$commentId.'/like')->assertOk();
        $this->postJson('/api/v1/comments/'.$commentId.'/dislike')->assertOk();
        $this->deleteJson('/api/v1/comments/'.$commentId.'/dislike')->assertOk();

        $this->getJson('/api/v1/comments/'.$commentId.'/replies')
            ->assertOk()
            ->assertJsonCount(1, 'data.replies');

        $notifications = $this->getJson('/api/v1/notifications')
            ->assertOk();

        $this->assertGreaterThanOrEqual(2, count($notifications->json('data.notifications')));

        $notificationId = $notifications->json('data.notifications.0.id');

        $this->postJson('/api/v1/notifications/'.$notificationId.'/read')
            ->assertOk()
            ->assertJsonPath('data.notification.readAt', fn ($value) => $value !== null);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->deleteJson('/api/v1/notifications/'.$notificationId)->assertOk();

        Sanctum::actingAs($viewer);

        $viewerNotifications = $this->getJson('/api/v1/notifications')
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, count($viewerNotifications->json('data.notifications')));

        $this->patchJson('/api/v1/comments/'.$commentId, ['body' => 'Edited comment'])
            ->assertOk()
            ->assertJsonPath('data.comment.body', 'Edited comment');

        $this->deleteJson('/api/v1/comments/'.$commentId)->assertOk();
        $this->assertDatabaseMissing('comments', ['id' => $commentId]);
    }
}