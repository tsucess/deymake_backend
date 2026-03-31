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
            ->assertJsonPath('data.standings.0.user.fullName', 'Creator One');

        $this->getJson('/api/v1/users/search?q=Creator&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.users.retrieved'))
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.fullName', 'Creator Two')
            ->assertJsonPath('meta.users.total', 2)
            ->assertJsonPath('meta.users.currentPage', 2);

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('message', trans('messages.users.profile_retrieved'))
            ->assertJsonPath('data.user.fullName', 'Creator One')
            ->assertJsonPath('data.user.subscriberCount', 1);

        $this->getJson('/api/v1/users/'.$creator->id.'/posts')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.users.posts_retrieved'))
            ->assertJsonCount(2, 'data.videos')
            ->assertJsonPath('meta.videos.total', 2);
    }

    public function test_leaderboard_resolves_current_user_rank_from_sanctum_token_on_public_route(): void
    {
        $leader = User::factory()->create(['name' => 'Leader', 'email' => 'leader@example.com']);
        $viewer = User::factory()->create(['name' => 'Viewer', 'email' => 'viewer-rank@example.com']);

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
            ->assertJsonPath('data.currentUserRank.user.fullName', 'Viewer');
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

        $uploadResponse
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.upload.stored'));
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

        $videoResponse->assertCreated()->assertJsonPath('message', trans('messages.videos.created'));
        $videoId = $videoResponse->json('data.video.id');

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
            ->assertJsonPath('data.video.mediaUrl', 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-2/live.mp4');

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
            ->assertJsonPath('data.profile.subscriberCount', 0)
            ->assertJsonPath('data.profile.currentUserState.subscribed', false);

        $this->getJson('/api/v1/users/'.$creator->id)
            ->assertOk()
            ->assertJsonPath('data.user.fullName', 'Creator')
            ->assertJsonPath('data.user.subscriberCount', 1)
            ->assertJsonPath('data.user.currentUserState.subscribed', true);

        $this->getJson('/api/v1/users/search?q=Creator')
            ->assertOk()
            ->assertJsonPath('data.users.0.fullName', 'Creator')
            ->assertJsonPath('data.users.0.currentUserState.subscribed', true);

        $this->getJson('/api/v1/search/creators?q=Creator')
            ->assertOk()
            ->assertJsonPath('data.creators.0.fullName', 'Creator')
            ->assertJsonPath('data.creators.0.currentUserState.subscribed', true);

        $this->patchJson('/api/v1/me/profile', ['fullName' => 'Viewer Updated', 'bio' => 'Updated bio'])
            ->assertOk()
            ->assertJsonPath('data.profile.fullName', 'Viewer Updated')
            ->assertJsonPath('data.profile.subscriberCount', 0)
            ->assertJsonPath('data.profile.currentUserState.subscribed', false);

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
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto,f_auto/v1/deymake/uploads/videos/live-processing.mp4',
        ])->save();

        $this->postJson('/api/v1/videos/'.$video->id.'/live/start')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.videos.live_started'))
            ->assertJsonPath('data.video.isLive', true)
            ->assertJsonPath(
                'data.video.mediaUrl',
                'https://res.cloudinary.com/demo/video/upload/q_auto,f_auto/v1/deymake/uploads/videos/live-processing.mp4'
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

        $this->assertDatabaseCount('live_signals', 0);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/videos/'.$video->id.'/live/signals')
            ->assertStatus(409)
            ->assertJsonPath('message', trans('messages.videos.live_not_active'));

        $this->assertSame(0, LiveSignal::query()->count());
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