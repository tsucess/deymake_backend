<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\AcademyLesson;
use App\Models\Category;
use App\Models\CreatorPlan;
use App\Models\Upload;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GrowthAndToolingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_academy_course_enrollment_and_completion_work(): void
    {
        $course = AcademyCourse::query()->create([
            'title' => 'Creator Basics',
            'slug' => 'creator-basics',
            'status' => 'published',
            'difficulty' => 'beginner',
            'summary' => 'Start strong on DeyMake.',
            'estimated_minutes' => 25,
            'published_at' => now(),
        ]);

        $lesson = AcademyLesson::query()->create([
            'academy_course_id' => $course->id,
            'title' => 'Find your content lane',
            'summary' => 'Pick one niche to dominate.',
            'content' => 'Long form lesson content',
            'sort_order' => 1,
            'duration_minutes' => 10,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $student = User::factory()->create();

        $this->getJson('/api/v1/academy/courses')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.academy.courses_retrieved'))
            ->assertJsonPath('data.courses.0.id', $course->id);

        Sanctum::actingAs($student);

        $this->postJson('/api/v1/academy/courses/'.$course->id.'/enroll')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.academy.enrolled'))
            ->assertJsonPath('data.enrollment.course.id', $course->id);

        $this->postJson('/api/v1/academy/lessons/'.$lesson->id.'/complete')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.academy.lesson_completed'))
            ->assertJsonPath('data.enrollment.progressPercent', 100);

        $this->getJson('/api/v1/academy/me')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.academy.learning_retrieved'))
            ->assertJsonPath('data.enrollments.0.course.id', $course->id)
            ->assertJsonPath('data.enrollments.0.completedLessons', 1);
    }

    public function test_ai_studio_projects_and_offline_upload_queue_work(): void
    {
        $user = User::factory()->create(['name' => 'Tooling User', 'username' => 'tooling.user']);
        $upload = Upload::query()->create([
            'user_id' => $user->id,
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'deymake/uploads/raw/video-1.mp4',
            'original_name' => 'video-1.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1200,
            'processing_status' => 'completed',
            'duration' => 42.5,
            'processed_url' => 'https://cdn.example.com/video-1.mp4',
        ]);

        $video = Video::query()->create([
            'user_id' => $user->id,
            'upload_id' => $upload->id,
            'type' => 'video',
            'title' => 'Creator Story',
            'caption' => 'A full behind the scenes story',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($user);

        $projectId = $this->postJson('/api/v1/ai/studio/projects', [
            'sourceVideoId' => $video->id,
            'sourceUploadId' => $upload->id,
            'title' => 'Creator Story Cutdown',
            'operations' => ['hooks', 'cutdowns', 'thumbnails'],
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.ai_studio.project_created'))
            ->assertJsonPath('data.project.status', 'draft')
            ->json('data.project.id');

        $this->postJson('/api/v1/ai/studio/projects/'.$projectId.'/generate')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.ai_studio.generated'))
            ->assertJsonPath('data.project.status', 'generated')
            ->assertJsonPath('data.project.output.seed', 'Creator Story Cutdown');

        $queueId = $this->postJson('/api/v1/uploads/offline-queue', [
            'clientReference' => 'ios-device-queue-1',
            'type' => 'video',
            'title' => 'Offline Campus Story',
            'uploadId' => $upload->id,
            'status' => 'queued',
            'metadata' => ['network' => 'offline'],
        ])
            ->assertCreated()
            ->assertJsonPath('message', trans('messages.offline_uploads.saved'))
            ->assertJsonPath('data.item.status', 'queued')
            ->json('data.item.id');

        $this->patchJson('/api/v1/uploads/offline-queue/'.$queueId, [
            'status' => 'synced',
            'videoId' => $video->id,
        ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.offline_uploads.updated'))
            ->assertJsonPath('data.item.videoId', $video->id);

        $this->getJson('/api/v1/uploads/offline-queue')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.offline_uploads.retrieved'))
            ->assertJsonPath('data.items.0.id', $queueId)
            ->assertJsonPath('data.items.0.status', 'synced');
    }

    public function test_talent_discovery_surfaces_verified_creators_with_relevant_filters(): void
    {
        $category = Category::create(['name' => 'Comedy', 'slug' => 'comedy']);
        $creator = User::factory()->create([
            'name' => 'Discovery Star',
            'username' => 'discovery.star',
            'creator_verification_status' => 'approved',
            'creator_verified_at' => now(),
        ]);

        CreatorPlan::query()->create([
            'creator_id' => $creator->id,
            'name' => 'Star Club',
            'price_amount' => 2500,
            'currency' => 'NGN',
            'billing_period' => 'monthly',
            'is_active' => true,
        ]);

        Video::query()->create([
            'user_id' => $creator->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Discovery Comedy Clip',
            'caption' => 'Campus comedy hit',
            'is_draft' => false,
            'views_count' => 900,
        ]);

        $this->getJson('/api/v1/talent/discovery?categoryId='.$category->id.'&verifiedOnly=1&hasActivePlans=1')
            ->assertOk()
            ->assertJsonPath('message', trans('messages.talent_discovery.retrieved'))
            ->assertJsonPath('data.creators.0.profile.id', $creator->id)
            ->assertJsonPath('data.creators.0.profile.isVerifiedCreator', true)
            ->assertJsonPath('data.creators.0.metrics.publishedVideos', 1);
    }
}