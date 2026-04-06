<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Comment;
use App\Models\LiveSignal;
use App\Models\Upload;
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
        $creator = User::factory()->create(['name' => 'Creator One', 'username' => 'creator.one', 'email' => 'creator@example.com']);
        $otherCreator = User::factory()->create(['name' => 'Creator Two', 'username' => 'creator.two', 'email' => 'creator2@example.com']);

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
            ->assertJsonPath('message', trans('messages.home.retrieved'))
            ->assertJsonPath('data.categories.0.slug', 'music');

        $this->getJson('/api/v1/videos/trending')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.trending_retrieved'))
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
            ->assertJsonPath('message', trans('messages.videos.live_retrieved'))
            ->assertJsonPath('data.videos.0.id', $liveVideo->id)
            ->assertJsonPath('meta.videos.total', 1);

        $this->getJson('/api/v1/videos/'.$mainVideo->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.retrieved'))
            ->assertJsonPath('data.video.author.fullName', 'Creator One')
            ->assertJsonPath('data.video.author.username', 'creator.one')
            ->assertJsonPath('data.video.author.subscriberCount', 1)
            ->assertJsonPath('data.video.likes', 1)
            ->assertJsonPath('data.video.commentsCount', 1)
            ->assertJsonPath('data.video.currentUserState.liked', false)
            ->assertJsonPath('data.video.currentUserState.subscribed', false);

        $this->getJson('/api/v1/videos/'.$mainVideo->id.'/related')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.related_retrieved'))
            ->assertJsonPath('data.videos.0.id', $relatedVideo->id)
            ->assertJsonPath('meta.videos.total', 2);

        $this->postJson('/api/v1/videos/'.$mainVideo->id.'/view')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.view_recorded'))
            ->assertJsonPath('data.views', 501);

        $this->postJson('/api/v1/videos/'.$mainVideo->id.'/share')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.share_recorded'))
            ->assertJsonPath('data.shares', 1);

        $this->getJson('/api/v1/search?q=Alpha')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.search.global_retrieved'))
            ->assertJsonPath('data.videos.0.id', $mainVideo->id)
            ->assertJsonPath('data.videos.0.author.subscriberCount', 1)
            ->assertJsonPath('data.videos.0.likes', 1)
            ->assertJsonPath('meta.videos.total', 1)
            ->assertJsonPath('meta.creators.total', 0)
            ->assertJsonPath('meta.categories.total', 0);

        $this->getJson('/api/v1/leaderboard?period=monthly')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.leaderboard.retrieved'))
            ->assertJsonPath('data.standings.0.user.fullName', 'Creator One')
            ->assertJsonPath('data.standings.0.user.username', 'creator.one');

        $this->getJson('/api/v1/users/search?q=Creator&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.users.retrieved'))
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.fullName', 'Creator Two')
            ->assertJsonPath('data.users.0.username', 'creator.two')
            ->assertJsonPath('meta.users.total', 2)
            ->assertJsonPath('meta.users.currentPage', 2);

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.users.profile_retrieved'))
            ->assertJsonPath('data.user.fullName', 'Creator One')
            ->assertJsonPath('data.user.username', 'creator.one')
            ->assertJsonPath('data.user.subscriberCount', 1);

        $this->getJson('/api/v1/users/'.$creator->id.'/posts')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.users.posts_retrieved'))
            ->assertJsonCount(2, 'data.videos')
            ->assertJsonPath('meta.videos.total', 2);
    }

    public function test_leaderboard_resolves_current_user_rank_from_sanctum_token_on_public_route(): void
    {
        $leader = User::factory()->create(['name' => 'Leader', 'username' => 'leader.rank', 'email' => 'leader@example.com']);
        $viewer = User::factory()->create(['name' => 'Viewer', 'username' => 'viewer.rank', 'email' => 'viewer-rank@example.com']);

        Video::create([
            'user_id' => $leader->id,
            'type' => 'video',
            'title' => 'Top clip',
            'is_draft' => false,
            'views_count' => 200,
        ]);

        Video::create([
            'user_id' => $viewer->id,
            'type' => 'video',
            'title' => 'Second clip',
            'is_draft' => false,
            'views_count' => 120,
        ]);

        $token = $viewer->createToken('leaderboard-token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/leaderboard?period=monthly')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.leaderboard.retrieved'))
            ->assertJsonPath('data.currentUserRank.userId', $viewer->id)
            ->assertJsonPath('data.currentUserRank.rank', 2)
            ->assertJsonPath('data.currentUserRank.user.fullName', 'Viewer')
            ->assertJsonPath('data.currentUserRank.user.username', 'viewer.rank');
    }

    public function test_authenticated_user_can_fetch_their_subscribers(): void
    {
        $creator = User::factory()->create(['name' => 'Creator Prime', 'username' => 'creator.prime']);
        $firstSubscriber = User::factory()->create(['name' => 'Grace Hopper', 'username' => 'grace.hopper']);
        $secondSubscriber = User::factory()->create(['name' => 'Katherine Johnson', 'username' => 'katherine.j']);

        $creator->subscribers()->attach($firstSubscriber->id, ['created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()]);
        $creator->subscribers()->attach($secondSubscriber->id, ['created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/me/subscribers')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.profile.subscribers_retrieved'))
            ->assertJsonPath('meta.subscribers.total', 2)
            ->assertJsonPath('data.subscribers.0.fullName', 'Katherine Johnson')
            ->assertJsonPath('data.subscribers.1.fullName', 'Grace Hopper');
    }

    public function test_authenticated_user_can_manage_uploads_videos_engagement_profile_and_notifications(): void
    {
        config(['services.cloudinary.url' => 'cloudinary://test-key:test-secret@demo']);

        $this->partialMock(CloudinaryUploadService::class, function ($mock): void {
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
        $creator = User::factory()->create(['name' => 'Creator', 'username' => 'creator.handle', 'email' => 'creator@example.com']);
        $viewer = User::factory()->create(['name' => 'Viewer', 'username' => 'viewer.handle', 'email' => 'viewer@example.com']);
        $featuredCreator = User::factory()->create(['name' => 'Featured Creator', 'username' => 'featured.creator', 'email' => 'featured@example.com']);

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

        $uploadResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.upload.stored'));
        $uploadId = $uploadResponse->json('data.upload.id');
        $this->assertDatabaseHas('uploads', [
            'id' => $uploadId,
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/image/upload/v1/deymake/uploads/images/user-2/poster.jpg',
        ]);

        $expectedTaggedUsers = [$creator->id, $viewer->id, $featuredCreator->id];
        sort($expectedTaggedUsers);

        $videoResponse = $this->postJson('/api/v1/videos', [
            'uploadId' => $uploadId,
            'categoryId' => $category->id,
            'type' => 'image',
            'caption' => 'Draft caption with @creator.handle and #featured.creator',
            'description' => 'Featuring @viewer.handle too',
            'isDraft' => true,
        ]);

        $videoResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.videos.created'))
            ->assertJsonPath('data.video.taggedUsers', $expectedTaggedUsers);
        $videoId = $videoResponse->json('data.video.id');
        $this->assertSame($expectedTaggedUsers, Video::findOrFail($videoId)->tagged_users);

        $this->patchJson('/api/v1/videos/'.$videoId, ['title' => 'Viewer Draft'])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.updated'))
            ->assertJsonPath('data.video.title', 'Viewer Draft');

        $this->postJson('/api/v1/videos/'.$videoId.'/publish')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.published'))
            ->assertJsonPath('data.video.isDraft', false);

        $liveUploadResponse = $this->postJson('/api/v1/uploads', [
            'file' => UploadedFile::fake()->create('live.mp4', 1024, 'video/mp4'),
        ]);

        $liveUploadResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.upload.stored'));
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
            ->assertJsonPath('message', trans('messages.videos.created'))
            ->assertJsonPath('data.video.isLive', true)
            ->assertJsonPath('data.video.isDraft', false)
            ->assertJsonPath('data.video.mediaUrl', 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-2/live.mp4')
            ->assertJsonPath('data.video.streamUrl', 'https://res.cloudinary.com/demo/video/upload/sp_auto/v1/deymake/uploads/videos/user-2/live.m3u8');

        $liveVideoId = $liveVideoResponse->json('data.video.id');

        $this->getJson('/api/v1/videos/live')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.live_retrieved'))
            ->assertJsonPath('data.videos.0.id', $liveVideoId)
            ->assertJsonPath('meta.videos.total', 1);

        $this->postJson('/api/v1/videos/'.$liveVideoId.'/share')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.share_recorded'))
            ->assertJsonPath('data.shareUrl', rtrim((string) config('app.frontend_url'), '/').'/live/'.$liveVideoId);

        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/like')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.liked'));
        $this->deleteJson('/api/v1/videos/'.$creatorVideo->id.'/like')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.like_removed'));
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/dislike')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.disliked'));
        $this->deleteJson('/api/v1/videos/'.$creatorVideo->id.'/dislike')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.dislike_removed'));
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/save')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.saved'));
        $this->deleteJson('/api/v1/videos/'.$creatorVideo->id.'/save')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.save_removed'));
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/like')->assertOk();
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/save')->assertOk();
        $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/report', ['reason' => 'spam'])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.videos.reported'));
        $this->postJson('/api/v1/creators/'.$creator->id.'/subscribe')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.subscriptions.created'));

        $commentResponse = $this->postJson('/api/v1/videos/'.$creatorVideo->id.'/comments', [
            'body' => 'Great post!',
        ]);

        $commentResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.comments.created'))
            ->assertJsonPath('data.comment.user.fullName', 'Viewer')
            ->assertJsonPath('data.comment.user.username', 'viewer.handle')
            ->assertJsonPath('data.comment.user.subscriberCount', 0)
            ->assertJsonPath('data.comment.currentUserState.liked', false);

        $commentId = $commentResponse->json('data.comment.id');

        $this->getJson('/api/v1/videos/'.$creatorVideo->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.retrieved'))
            ->assertJsonPath('data.video.likes', 1)
            ->assertJsonPath('data.video.saves', 1)
            ->assertJsonPath('data.video.commentsCount', 1)
            ->assertJsonPath('data.video.author.subscriberCount', 1)
            ->assertJsonPath('data.video.currentUserState.liked', true)
            ->assertJsonPath('data.video.currentUserState.saved', true)
            ->assertJsonPath('data.video.currentUserState.subscribed', true);

        $this->getJson('/api/v1/videos/'.$creatorVideo->id.'/comments')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.retrieved'))
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonPath('data.comments.0.repliesCount', 0)
            ->assertJsonPath('data.comments.0.currentUserState.liked', false);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.profile.fullName', 'Viewer')
            ->assertJsonPath('data.profile.username', 'viewer.handle')
            ->assertJsonPath('data.profile.subscriberCount', 0)
            ->assertJsonPath('data.profile.currentUserState.subscribed', false);

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.user.fullName', 'Creator')
            ->assertJsonPath('data.user.username', 'creator.handle')
            ->assertJsonPath('data.user.subscriberCount', 1)
            ->assertJsonPath('data.user.currentUserState.subscribed', true);

        $this->getJson('/api/v1/users/search?q=Creator')
            ->assertOk()
            ->assertJsonPath('data.users.0.fullName', 'Creator')
            ->assertJsonPath('data.users.0.username', 'creator.handle')
            ->assertJsonPath('data.users.0.currentUserState.subscribed', true);

        $this->getJson('/api/v1/search/creators?q=Creator')
            ->assertOk()
            ->assertJsonPath('data.creators.0.fullName', 'Creator')
            ->assertJsonPath('data.creators.0.username', 'creator.handle')
            ->assertJsonPath('data.creators.0.currentUserState.subscribed', true);

        $this->patchJson('/api/v1/me/profile', ['fullName' => 'Viewer Updated', 'username' => 'viewer.updated', 'bio' => 'Updated bio'])
            ->assertOk()
            ->assertJsonPath('data.profile.fullName', 'Viewer Updated')
            ->assertJsonPath('data.profile.username', 'viewer.updated')
            ->assertJsonPath('data.profile.subscriberCount', 0)
            ->assertJsonPath('data.profile.currentUserState.subscribed', false);

        $this->getJson('/api/v1/me/posts?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.videos.0.title', fn ($value) => in_array($value, ['Viewer Draft', 'Viewer Live'], true))
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
            ->assertJsonPath('data.user.subscriberCount', 1)
            ->assertJsonPath('data.user.currentUserState.subscribed', true);

        $this->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->assertJsonPath('data.preferences.language', 'en');

        $this->patchJson('/api/v1/me/preferences', ['language' => 'fr'])
            ->assertOk()
            ->assertJsonPath('data.preferences.language', 'fr');

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/comments/'.$commentId.'/replies', ['body' => 'Thanks!'])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.comments.reply_created'));

        $this->postJson('/api/v1/comments/'.$commentId.'/like')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.liked'));
        $this->deleteJson('/api/v1/comments/'.$commentId.'/like')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.like_removed'));
        $this->postJson('/api/v1/comments/'.$commentId.'/dislike')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.disliked'));
        $this->deleteJson('/api/v1/comments/'.$commentId.'/dislike')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.dislike_removed'));

        $this->getJson('/api/v1/comments/'.$commentId.'/replies')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.replies_retrieved'))
            ->assertJsonCount(1, 'data.replies')
            ->assertJsonPath('data.replies.0.user.fullName', 'Creator')
            ->assertJsonPath('data.replies.0.user.username', 'creator.handle')
            ->assertJsonPath('data.replies.0.user.subscriberCount', 1)
            ->assertJsonPath('data.replies.0.currentUserState.liked', false);

        $notifications = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.notifications.retrieved'));

        $this->assertGreaterThanOrEqual(2, count($notifications->json('data.notifications')));

        $notificationId = $notifications->json('data.notifications.0.id');

        $this->postJson('/api/v1/notifications/'.$notificationId.'/read')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.notifications.marked_read'))
            ->assertJsonPath('data.notification.readAt', fn ($value) => $value !== null);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.notifications.all_marked_read'));
        $this->deleteJson('/api/v1/notifications/'.$notificationId)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.notifications.deleted'));

        Sanctum::actingAs($viewer);

        $viewerNotifications = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.notifications.retrieved'));

        $this->assertGreaterThanOrEqual(1, count($viewerNotifications->json('data.notifications')));
        $viewerNotificationTitles = array_column($viewerNotifications->json('data.notifications'), 'title');
        $viewerNotificationBodies = array_column($viewerNotifications->json('data.notifications'), 'body');

        $this->assertContains(trans('messages.notifications.reply_title', [], 'fr'), $viewerNotificationTitles);
        $this->assertContains(trans('messages.notifications.reply_body', ['name' => 'Creator'], 'fr'), $viewerNotificationBodies);
        $this->assertContains(trans('messages.notifications.comment_like_title', [], 'fr'), $viewerNotificationTitles);
        $this->assertContains(trans('messages.notifications.comment_like_body', ['name' => 'Creator'], 'fr'), $viewerNotificationBodies);
        $this->assertContains(trans('messages.notifications.comment_dislike_title', [], 'fr'), $viewerNotificationTitles);
        $this->assertContains(trans('messages.notifications.comment_dislike_body', ['name' => 'Creator'], 'fr'), $viewerNotificationBodies);

        $this->patchJson('/api/v1/comments/'.$commentId, ['body' => 'Edited comment'])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.updated'))
            ->assertJsonPath('data.comment.body', 'Edited comment');

        $this->deleteJson('/api/v1/comments/'.$commentId)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.comments.deleted'));
        $this->assertDatabaseMissing('comments', ['id' => $commentId]);
    }

    public function test_authenticated_user_can_finalize_a_direct_cloudinary_upload_without_streaming_the_file_through_the_api(): void
    {
        $creator = User::factory()->create(['name' => 'Direct Upload Creator', 'email' => 'direct-upload@example.com']);

        config(['services.cloudinary.url' => 'cloudinary://test-key:test-secret@demo']);

        Sanctum::actingAs($creator);

        $response = $this->postJson('/api/v1/uploads', [
            'type' => 'video',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/direct.mp4',
            'originalName' => 'direct.mp4',
            'mimeType' => 'video/mp4',
            'size' => 204800,
            'width' => 1920,
            'height' => 1080,
            'duration' => 18.75,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.upload.stored'))
            ->assertJsonPath('data.upload.type', 'video')
            ->assertJsonPath('data.upload.disk', 'cloudinary')
            ->assertJsonPath('data.upload.path', 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/direct.mp4')
            ->assertJsonPath('data.upload.processedUrl', 'https://res.cloudinary.com/demo/video/upload/q_auto:best,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/direct.mp4')
            ->assertJsonPath('data.upload.processingStatus', 'completed');

        $this->assertDatabaseHas('uploads', [
            'user_id' => $creator->id,
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/direct.mp4',
            'original_name' => 'direct.mp4',
            'mime_type' => 'video/mp4',
            'size' => 204800,
            'processing_status' => 'completed',
        ]);
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

    public function test_video_uploads_must_finish_processing_before_they_can_go_live(): void
    {
        config(['services.cloudinary.url' => 'cloudinary://test-key:test-secret@demo']);

        $category = Category::create(['name' => 'Live', 'slug' => 'live']);
        $creator = User::factory()->create(['name' => 'Live Creator', 'email' => 'live-creator@example.com']);

        Sanctum::actingAs($creator);

        $upload = Upload::create([
            'user_id' => $creator->id,
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/live-processing.mp4',
            'original_name' => 'live-processing.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'processing',
            'processed_url' => null,
        ]);

        $this->postJson('/api/v1/videos', [
            'uploadId' => $upload->id,
            'categoryId' => $category->id,
            'type' => 'video',
            'title' => 'Blocked Live Create',
            'isLive' => true,
            'isDraft' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', trans('messages.videos.upload_must_finish_processing_for_live'));

        $this->assertDatabaseMissing('videos', [
            'user_id' => $creator->id,
            'upload_id' => $upload->id,
            'title' => 'Blocked Live Create',
        ]);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'upload_id' => $upload->id,
            'type' => 'video',
            'title' => 'Blocked Live Start',
            'is_draft' => false,
        ]);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/start')
            ->assertUnprocessable()
            ->assertJsonPath('message', trans('messages.videos.upload_must_finish_processing_for_live'));

        $video->refresh();

        $this->assertFalse($video->is_live);
        $this->assertNull($video->live_started_at);

        $upload->forceFill([
            'processing_status' => 'completed',
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto:best,f_auto,vc_auto/v1/deymake/uploads/videos/live-processing.mp4',
        ])->save();

        $this->postJson('/api/v1/videos/'.$video->id.'/live/start')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.live_started'))
            ->assertJsonPath('data.video.isLive', true)
            ->assertJsonPath(
                'data.video.mediaUrl',
                'https://res.cloudinary.com/demo/video/upload/q_auto:best,f_auto,vc_auto/v1/deymake/uploads/videos/live-processing.mp4'
            )
            ->assertJsonPath(
                'data.video.streamUrl',
                'https://res.cloudinary.com/demo/video/upload/sp_auto/v1/deymake/uploads/videos/live-processing.m3u8'
            );
    }

    public function test_live_signals_can_be_exchanged_between_viewer_and_creator_and_are_cleared_when_live_stops(): void
    {
        $category = Category::create(['name' => 'Live', 'slug' => 'live']);
        $creator = User::factory()->create(['name' => 'Live Creator', 'email' => 'live-owner@example.com']);
        $viewer = User::factory()->create(['name' => 'Live Viewer', 'email' => 'live-viewer@example.com']);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'RTC Live',
            'is_live' => true,
            'is_draft' => false,
            'live_started_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $offerResponse = $this->postJson('/api/v1/videos/'.$video->id.'/live/signals', [
            'type' => 'offer',
            'sdp' => 'viewer-offer-sdp',
        ]);

        $offerResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.videos.live_signal_sent'))
            ->assertJsonPath('data.signal.type', 'offer')
            ->assertJsonPath('data.signal.senderId', $viewer->id)
            ->assertJsonPath('data.signal.recipientId', $creator->id)
            ->assertJsonPath('data.signal.payload.sdp', 'viewer-offer-sdp');

        Sanctum::actingAs($creator);

        $creatorSignals = $this->getJson('/api/v1/videos/'.$video->id.'/live/signals')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.live_signals_retrieved'))
            ->assertJsonPath('data.signals.0.type', 'offer')
            ->assertJsonPath('data.signals.0.senderId', $viewer->id)
            ->assertJsonPath('data.signals.0.payload.sdp', 'viewer-offer-sdp');

        $this->postJson('/api/v1/videos/'.$video->id.'/live/signals', [
            'recipientId' => $viewer->id,
            'type' => 'answer',
            'sdp' => 'creator-answer-sdp',
        ])
            ->assertCreated()
            ->assertJsonPath('data.signal.type', 'answer')
            ->assertJsonPath('data.signal.recipientId', $viewer->id);

        Sanctum::actingAs($viewer);

        $viewerSignals = $this->getJson('/api/v1/videos/'.$video->id.'/live/signals')
            ->assertOk()
            ->assertJsonPath('data.signals.0.type', 'answer')
            ->assertJsonPath('data.signals.0.payload.sdp', 'creator-answer-sdp');

        $latestSignalId = $viewerSignals->json('data.latestSignalId');

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/signals', [
            'recipientId' => $viewer->id,
            'type' => 'candidate',
            'candidate' => [
                'candidate' => 'candidate:1 1 udp 2122260223 127.0.0.1 3478 typ host',
                'sdpMid' => '0',
                'sdpMLineIndex' => 0,
            ],
        ])
            ->assertCreated();

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/videos/'.$video->id.'/live/signals?after='.$latestSignalId)
            ->assertOk()
            ->assertJsonCount(1, 'data.signals')
            ->assertJsonPath('data.signals.0.type', 'candidate')
            ->assertJsonPath('data.signals.0.payload.candidate.sdpMid', '0');

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/stop')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.live_stopped'));

        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'is_live' => false,
            'is_draft' => true,
        ]);

        $this->assertDatabaseCount('live_signals', 0);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/videos/'.$video->id.'/live/signals')
            ->assertStatus(409)
            ->assertJsonPath('message', trans('messages.videos.live_not_active'));

        $this->assertSame(0, LiveSignal::query()->count());
    }

    public function test_live_session_returns_agora_credentials_for_creator_and_viewer_roles(): void
    {
        config()->set('services.agora.app_id', 'test-agora-app');
        config()->set('services.agora.app_certificate', 'test-agora-certificate');
        config()->set('services.agora.token_ttl', 900);

        $category = Category::create(['name' => 'Live', 'slug' => 'live']);
        $creator = User::factory()->create(['name' => 'Live Creator', 'email' => 'agora-owner@example.com']);
        $viewer = User::factory()->create(['name' => 'Live Viewer', 'email' => 'agora-viewer@example.com']);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Agora Live',
            'is_live' => true,
            'is_draft' => false,
            'live_started_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/videos/'.$video->id.'/live/session?role=audience')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.live_session_retrieved'))
            ->assertJsonPath('data.session.appId', 'test-agora-app')
            ->assertJsonPath('data.session.channelName', 'live-video-'.$video->id)
            ->assertJsonPath('data.session.uid', 'user-'.$viewer->id)
            ->assertJsonPath('data.session.role', 'audience');

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/videos/'.$video->id.'/live/session?role=host')
            ->assertOk()
            ->assertJsonPath('data.session.uid', 'user-'.$creator->id)
            ->assertJsonPath('data.session.role', 'host')
            ->assertJsonPath('data.session.token', fn (string $token) => $token !== '');
    }

    public function test_live_likes_can_be_sent_multiple_times_by_host_and_audience_and_remain_visible_in_profile_feeds(): void
    {
        $category = Category::create(['name' => 'Live', 'slug' => 'live-likes']);
        $creator = User::factory()->create(['name' => 'Live Creator', 'username' => 'live.creator', 'email' => 'live-creator-likes@example.com']);
        $viewer = User::factory()->create(['name' => 'Live Viewer', 'username' => 'live.viewer', 'email' => 'live-viewer-likes@example.com']);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Crowded Live',
            'is_live' => true,
            'is_draft' => false,
            'live_started_at' => now(),
            'views_count' => 12,
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/like')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.liked'))
            ->assertJsonPath('data.video.likes', 1)
            ->assertJsonPath('data.video.liveLikes', 1)
            ->assertJsonPath('data.engagement.type', 'like')
            ->assertJsonPath('data.video.currentUserState.liked', false);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/like')
            ->assertOk()
            ->assertJsonPath('data.video.likes', 2)
            ->assertJsonPath('data.video.liveLikes', 2);

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/like')
            ->assertOk()
            ->assertJsonPath('data.video.likes', 3)
            ->assertJsonPath('data.video.liveLikes', 3);

        $this->assertDatabaseCount('live_like_events', 3);

        $this->getJson('/api/v1/videos/'.$video->id)
            ->assertOk()
            ->assertJsonPath('data.video.likes', 3)
            ->assertJsonPath('data.video.liveLikes', 3);

        $this->getJson('/api/v1/me/posts')
            ->assertOk()
            ->assertJsonPath('data.videos.0.id', $video->id)
            ->assertJsonPath('data.videos.0.likes', 3)
            ->assertJsonPath('data.videos.0.liveLikes', 3)
            ->assertJsonPath('data.videos.0.views', 12);
    }

    public function test_live_presence_and_engagements_track_peak_viewers_and_live_comments(): void
    {
        $category = Category::create(['name' => 'Live Presence', 'slug' => 'live-presence']);
        $creator = User::factory()->create(['name' => 'Presence Host', 'username' => 'presence.host', 'email' => 'presence-host@example.com']);
        $viewer = User::factory()->create(['name' => 'Presence Viewer', 'username' => 'presence.viewer', 'email' => 'presence-viewer@example.com']);
        $viewerTwo = User::factory()->create(['name' => 'Presence Fan', 'username' => 'presence.fan', 'email' => 'presence-fan@example.com']);

        $video = Video::create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Tracked Live',
            'is_live' => true,
            'is_draft' => false,
            'live_started_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/presence', [
            'sessionKey' => 'viewer-one',
            'role' => 'audience',
        ])
            ->assertOk()
            ->assertJsonPath('data.analytics.currentViewers', 1)
            ->assertJsonPath('data.analytics.peakViewers', 1);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/like')->assertOk();

        $this->postJson('/api/v1/videos/'.$video->id.'/comments', [
            'body' => 'This stream is amazing',
        ])
            ->assertCreated();

        $this->postJson('/api/v1/videos/'.$video->id.'/comments', [
            'body' => 'Need an encore',
        ])
            ->assertCreated();

        Sanctum::actingAs($creator);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/presence', [
            'sessionKey' => 'host-one',
            'role' => 'host',
        ])
            ->assertOk()
            ->assertJsonPath('data.analytics.currentViewers', 2)
            ->assertJsonPath('data.analytics.peakViewers', 2);

        Sanctum::actingAs($viewerTwo);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/presence', [
            'sessionKey' => 'viewer-two',
            'role' => 'audience',
        ])
            ->assertOk()
            ->assertJsonPath('data.analytics.currentViewers', 3)
            ->assertJsonPath('data.analytics.peakViewers', 3);

        $this->postJson('/api/v1/videos/'.$video->id.'/live/like')->assertOk();
        $this->postJson('/api/v1/videos/'.$video->id.'/live/like')->assertOk();
        $this->postJson('/api/v1/videos/'.$video->id.'/live/like')->assertOk();

        $this->postJson('/api/v1/videos/'.$video->id.'/comments', [
            'body' => 'Best live today',
        ])
            ->assertCreated();

        $this->postJson('/api/v1/videos/'.$video->id.'/live/presence/leave', [
            'sessionKey' => 'viewer-two',
        ])
            ->assertOk()
            ->assertJsonPath('data.analytics.currentViewers', 2)
            ->assertJsonPath('data.analytics.peakViewers', 3);

        Sanctum::actingAs($creator);

        $this->getJson('/api/v1/videos/'.$video->id.'/live/engagements?includeSummary=1')
            ->assertOk()
            ->assertJsonFragment(['type' => 'comment', 'body' => 'This stream is amazing'])
            ->assertJsonFragment(['type' => 'like'])
            ->assertJsonPath('data.summary.topCommenters.0.actor.fullName', 'Presence Viewer')
            ->assertJsonPath('data.summary.topCommenters.0.commentsCount', 2)
            ->assertJsonPath('data.summary.topFans.0.actor.fullName', 'Presence Fan')
            ->assertJsonPath('data.summary.topFans.0.engagementCount', 4)
            ->assertJsonPath('data.summary.topLikers.0.actor.fullName', 'Presence Fan')
            ->assertJsonPath('data.summary.topLikers.0.likesCount', 3)
            ->assertJsonPath('data.summary.totals.likes', 4)
            ->assertJsonPath('data.summary.totals.comments', 3)
            ->assertJsonPath('data.summary.totals.uniqueFans', 2)
            ->assertJsonPath('data.summary.retention.peakViewers', 3)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'timeline' => [['label', 'likesCount', 'commentsCount', 'engagementCount', 'viewersCount']],
                        'viewerTrend' => [['label', 'viewersCount']],
                        'peakMoments' => [['label', 'engagementCount', 'viewersCount']],
                        'retention' => ['startViewers', 'endViewers', 'averageViewers', 'peakViewers', 'retentionRate'],
                    ],
                ],
            ]);

        $this->getJson('/api/v1/videos/'.$video->id)
            ->assertOk()
            ->assertJsonPath('data.video.currentViewers', 2)
            ->assertJsonPath('data.video.liveAnalytics.peakViewers', 3)
            ->assertJsonPath('data.video.liveComments', 3)
            ->assertJsonPath('data.video.liveAnalytics.liveComments', 3);

        $this->getJson('/api/v1/me/posts')
            ->assertOk()
            ->assertJsonPath('data.videos.0.liveAnalytics.peakViewers', 3)
            ->assertJsonPath('data.videos.0.liveComments', 3);
    }

    public function test_locale_headers_localize_api_messages_and_preferences_validate_supported_languages(): void
    {
        $user = User::factory()->create([
            'preferences' => ['language' => 'yo'],
        ]);

        Sanctum::actingAs($user);

        $this->withHeaders(['X-Locale' => 'yo'])
            ->getJson('/api/v1/me/preferences')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.preferences.retrieved', [], 'yo'))
            ->assertJsonPath('data.preferences.language', 'yo');

        $this->withHeaders(['X-Locale' => 'ha'])
            ->patchJson('/api/v1/me/preferences', [
                'displayPreferences' => ['theme' => 'dark'],
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.preferences.updated', [], 'ha'))
            ->assertJsonPath('data.preferences.displayPreferences.theme', 'dark');

        $this->withHeaders(['X-Locale' => 'en'])
            ->patchJson('/api/v1/me/preferences', ['language' => 'invalid-locale'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['language'])
            ->assertJsonPath('errors.language.0', trans('messages.validation.language_supported'));

        $this->withHeaders(['X-Locale' => 'ig'])
            ->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.home.retrieved', [], 'ig'));

        $this->withHeaders(['X-Locale' => 'yo'])
            ->getJson('/api/v1/leaderboard')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.leaderboard.retrieved', [], 'yo'));
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
            ->assertJsonPath('data.shares', 1)
            ->assertJsonPath('data.shareUrl', rtrim((string) config('app.frontend_url'), '/').'/video/'.$video->id);

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