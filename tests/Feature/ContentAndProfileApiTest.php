<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Services\CloudinaryUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

        $creator->subscribers()->attach($otherCreator->id);
        $mainVideo->likes()->attach($otherCreator->id, ['type' => 'like']);

        Comment::create([
            'video_id' => $mainVideo->id,
            'user_id' => $otherCreator->id,
            'body' => 'Love this track!',
        ]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.categories.0.slug', 'music');

        $this->getJson('/api/v1/videos/trending')
            ->assertOk()
            ->assertJsonCount(3, 'data.videos')
            ->assertJsonPath('meta.videos.total', 3)
            ->assertJsonPath('meta.videos.currentPage', 1);

        $this->getJson('/api/v1/videos/trending?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.id', $relatedVideo->id)
            ->assertJsonPath('meta.videos.lastPage', 2)
            ->assertJsonPath('meta.videos.currentPage', 2);

        $this->getJson('/api/v1/videos/live')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $liveVideo->id)
            ->assertJsonPath('meta.videos.total', 1);

        $this->getJson('/api/v1/videos/'.$mainVideo->id)
            ->assertOk()
            ->assertJsonPath('data.video.author.fullName', 'Creator One')
            ->assertJsonPath('data.video.author.subscriberCount', 1)
            ->assertJsonPath('data.video.likes', 1)
            ->assertJsonPath('data.video.commentsCount', 1)
            ->assertJsonPath('data.video.currentUserState.liked', false)
            ->assertJsonPath('data.video.currentUserState.subscribed', false);

        $this->getJson('/api/v1/videos/'.$mainVideo->id.'/related')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $relatedVideo->id)
            ->assertJsonPath('meta.videos.total', 2);

        $this->postJson('/api/v1/videos/'.$mainVideo->id.'/view')
            ->assertOk()
            ->assertJsonPath('data.views', 501);

        $this->postJson('/api/v1/videos/'.$mainVideo->id.'/share')
            ->assertOk()
            ->assertJsonPath('data.shares', 1);

        $this->getJson('/api/v1/search?q=Alpha')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $mainVideo->id)
            ->assertJsonPath('data.videos.0.author.subscriberCount', 1)
            ->assertJsonPath('data.videos.0.likes', 1)
            ->assertJsonPath('meta.videos.total', 1)
            ->assertJsonPath('meta.creators.total', 0)
            ->assertJsonPath('meta.categories.total', 0);

        $this->getJson('/api/v1/leaderboard?period=monthly')
            ->assertOk()
            ->assertJsonPath('data.standings.0.user.fullName', 'Creator One');

        $this->getJson('/api/v1/users/search?q=Creator&per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.fullName', 'Creator Two')
            ->assertJsonPath('meta.users.total', 2)
            ->assertJsonPath('meta.users.currentPage', 2);

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.user.fullName', 'Creator One')
            ->assertJsonPath('data.user.subscriberCount', 1);

        $this->getJson('/api/v1/users/'.$creator->id.'/posts')
            ->assertOk()
            ->assertJsonCount(2, 'data.videos')
            ->assertJsonPath('meta.videos.total', 2);
    }

    public function test_authenticated_user_can_manage_uploads_videos_engagement_profile_and_notifications(): void
    {
        $this->mock(CloudinaryUploadService::class, function ($mock): void {
            $mock->shouldReceive('upload')->twice()->andReturn(
                [
                    'disk' => 'cloudinary',
                    'path' => 'https://res.cloudinary.com/demo/image/upload/v1/deymake/uploads/images/user-2/poster.jpg',
                    'url' => 'https://res.cloudinary.com/demo/image/upload/v1/deymake/uploads/images/user-2/poster.jpg',
                ],
                [
                    'disk' => 'cloudinary',
                    'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-2/live.mp4',
                    'url' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-2/live.mp4',
                ],
            );
        });

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
        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/image/upload/v1/deymake/uploads/images/user-2/poster.jpg',
        ]);

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

        $liveUploadResponse = $this->postJson('/api/v1/uploads', [
            'file' => UploadedFile::fake()->create('live.mp4', 1024, 'video/mp4'),
        ]);

        $liveUploadResponse->assertCreated();
        $liveUploadId = $liveUploadResponse->json('data.upload.id');
        $this->assertDatabaseHas('uploads', [
            'id' => $liveUploadId,
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-2/live.mp4',
        ]);

        $liveVideoResponse = $this->postJson('/api/v1/videos', [
            'uploadId' => $liveUploadId,
            'categoryId' => $category->id,
            'type' => 'video',
            'title' => 'Viewer Live',
            'isLive' => true,
            'isDraft' => false,
        ]);

        $liveVideoResponse
            ->assertCreated()
            ->assertJsonPath('data.video.isLive', true)
            ->assertJsonPath('data.video.isDraft', false)
            ->assertJsonPath('data.video.mediaUrl', 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-2/live.mp4');

        $liveVideoId = $liveVideoResponse->json('data.video.id');

        $this->getJson('/api/v1/videos/live')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $liveVideoId)
            ->assertJsonPath('meta.videos.total', 1);

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

        $commentResponse
            ->assertCreated()
            ->assertJsonPath('data.comment.user.fullName', 'Viewer')
            ->assertJsonPath('data.comment.user.subscriberCount', 0)
            ->assertJsonPath('data.comment.currentUserState.liked', false);

        $commentId = $commentResponse->json('data.comment.id');

        $this->getJson('/api/v1/videos/'.$creatorVideo->id)
            ->assertOk()
            ->assertJsonPath('data.video.likes', 1)
            ->assertJsonPath('data.video.saves', 1)
            ->assertJsonPath('data.video.commentsCount', 1)
            ->assertJsonPath('data.video.author.subscriberCount', 1)
            ->assertJsonPath('data.video.currentUserState.liked', true)
            ->assertJsonPath('data.video.currentUserState.saved', true)
            ->assertJsonPath('data.video.currentUserState.subscribed', true);

        $this->getJson('/api/v1/videos/'.$creatorVideo->id.'/comments')
            ->assertOk()
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.repliesCount', 0)
            ->assertJsonPath('data.comments.0.currentUserState.liked', false);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.fullName', 'Viewer')
            ->assertJsonPath('data.profile.subscriberCount', 0);

        $this->patchJson('/api/v1/me/profile', ['fullName' => 'Viewer Updated', 'bio' => 'Updated bio'])
            ->assertOk()
            ->assertJsonPath('data.profile.fullName', 'Viewer Updated')
            ->assertJsonPath('data.profile.subscriberCount', 0);

        $this->getJson('/api/v1/me/posts?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.title', 'Viewer Live')
            ->assertJsonPath('meta.videos.total', 2)
            ->assertJsonPath('meta.videos.currentPage', 2);

        $this->getJson('/api/v1/me/liked')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('meta.videos.total', 1);

        $this->getJson('/api/v1/me/saved')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('meta.videos.total', 1);

        $this->getJson('/api/v1/me/drafts')
            ->assertOk()
            ->assertJsonCount(0, 'data.videos')
            ->assertJsonPath('meta.videos.total', 0);

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.user.subscriberCount', 1);

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
            ->assertJsonCount(1, 'data.replies')
            ->assertJsonPath('data.replies.0.user.fullName', 'Creator')
            ->assertJsonPath('data.replies.0.user.subscriberCount', 1)
            ->assertJsonPath('data.replies.0.currentUserState.liked', false);

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

    public function test_blank_search_queries_return_empty_paginated_results_instead_of_listing_everything(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $creator = User::factory()->create(['name' => 'Creator One', 'email' => 'creator@example.com']);

        Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Alpha Hit',
            'caption' => 'Top track',
            'description' => 'Alpha release',
            'is_draft' => false,
        ]);

        $this->getJson('/api/v1/search?per_page=7')
            ->assertOk()
            ->assertJsonCount(0, 'data.videos')
            ->assertJsonCount(0, 'data.creators')
            ->assertJsonCount(0, 'data.categories')
            ->assertJsonPath('meta.videos.total', 0)
            ->assertJsonPath('meta.videos.perPage', 7)
            ->assertJsonPath('meta.creators.total', 0)
            ->assertJsonPath('meta.categories.total', 0);

        $this->getJson('/api/v1/search/suggestions?q=%20%20%20')
            ->assertOk()
            ->assertJsonCount(0, 'data.videos')
            ->assertJsonCount(0, 'data.creators')
            ->assertJsonCount(0, 'data.categories')
            ->assertJsonPath('meta.videos.total', 0)
            ->assertJsonPath('meta.videos.perPage', 5);

        $this->getJson('/api/v1/search/videos?q=%20%20%20')
            ->assertOk()
            ->assertJsonCount(0, 'data.videos')
            ->assertJsonPath('meta.videos.total', 0);

        $this->getJson('/api/v1/search/creators?q=%20%20%20')
            ->assertOk()
            ->assertJsonCount(0, 'data.creators')
            ->assertJsonPath('meta.creators.total', 0);

        $this->getJson('/api/v1/search/categories?q=%20%20%20')
            ->assertOk()
            ->assertJsonCount(0, 'data.categories')
            ->assertJsonPath('meta.categories.total', 0);

        $this->getJson('/api/v1/users/search?q=%20%20%20&per_page=3')
            ->assertOk()
            ->assertJsonCount(0, 'data.users')
            ->assertJsonPath('meta.users.total', 0)
            ->assertJsonPath('meta.users.perPage', 3);
    }

    public function test_public_view_and_share_metrics_are_deduplicated_per_client_fingerprint(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $creator = User::factory()->create(['name' => 'Creator One', 'email' => 'creator@example.com']);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Alpha Hit',
            'is_draft' => false,
            'views_count' => 10,
            'shares_count' => 0,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['User-Agent' => 'Engagement Test Agent'])
            ->postJson('/api/v1/videos/'.$video->id.'/view')
            ->assertOk()
            ->assertJsonPath('data.views', 11);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['User-Agent' => 'Engagement Test Agent'])
            ->postJson('/api/v1/videos/'.$video->id.'/view')
            ->assertOk()
            ->assertJsonPath('data.views', 11);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeaders(['User-Agent' => 'Second Engagement Agent'])
            ->postJson('/api/v1/videos/'.$video->id.'/view')
            ->assertOk()
            ->assertJsonPath('data.views', 12);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['User-Agent' => 'Engagement Test Agent'])
            ->postJson('/api/v1/videos/'.$video->id.'/share')
            ->assertOk()
            ->assertJsonPath('data.shares', 1);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->withHeaders(['User-Agent' => 'Engagement Test Agent'])
            ->postJson('/api/v1/videos/'.$video->id.'/share')
            ->assertOk()
            ->assertJsonPath('data.shares', 1);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.24'])
            ->withHeaders(['User-Agent' => 'Second Engagement Agent'])
            ->postJson('/api/v1/videos/'.$video->id.'/share')
            ->assertOk()
            ->assertJsonPath('data.shares', 2);

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'views_count' => 12,
            'shares_count' => 2,
        ]);
    }
}