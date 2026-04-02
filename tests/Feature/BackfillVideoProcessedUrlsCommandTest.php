<?php

namespace Tests\Feature;

use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillVideoProcessedUrlsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_command_is_dry_run_by_default(): void
    {
        config(['services.cloudinary.url' => 'cloudinary://test-key:test-secret@demo']);

        $upload = Upload::create([
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/dry-run.mp4',
            'original_name' => 'dry-run.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'completed',
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/dry-run.mp4',
        ]);

        $this->artisan('uploads:backfill-video-processed-urls')
            ->assertExitCode(0);

        $this->assertDatabaseHas('uploads', [
            'id' => $upload->id,
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/dry-run.mp4',
        ]);
    }

    public function test_backfill_command_updates_only_managed_cloudinary_video_urls_when_write_is_enabled(): void
    {
        config(['services.cloudinary.url' => 'cloudinary://test-key:test-secret@demo']);

        $candidate = Upload::create([
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/candidate.mp4',
            'original_name' => 'candidate.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'completed',
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/candidate.mp4',
        ]);

        $missingProcessedUrl = Upload::create([
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/missing.mp4',
            'original_name' => 'missing.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'completed',
            'processed_url' => null,
        ]);

        $alreadyCurrent = Upload::create([
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/current.mp4',
            'original_name' => 'current.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'completed',
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto:best,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/current.mp4',
        ]);

        $customProcessedUrl = Upload::create([
            'type' => 'video',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/video/upload/v1/deymake/uploads/videos/user-1/custom.mp4',
            'original_name' => 'custom.mp4',
            'mime_type' => 'video/mp4',
            'size' => 1024,
            'processing_status' => 'completed',
            'processed_url' => 'https://cdn.example.com/custom-stream.m3u8',
        ]);

        $nonVideo = Upload::create([
            'type' => 'image',
            'disk' => 'cloudinary',
            'path' => 'https://res.cloudinary.com/demo/image/upload/v1/deymake/uploads/images/user-1/photo.jpg',
            'original_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 512,
            'processing_status' => 'completed',
            'processed_url' => 'https://res.cloudinary.com/demo/image/upload/q_auto,f_auto/v1/deymake/uploads/images/user-1/photo.jpg',
        ]);

        $this->artisan('uploads:backfill-video-processed-urls --write')
            ->assertExitCode(0);

        $this->assertDatabaseHas('uploads', [
            'id' => $candidate->id,
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto:best,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/candidate.mp4',
        ]);

        $this->assertDatabaseHas('uploads', [
            'id' => $missingProcessedUrl->id,
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto:best,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/missing.mp4',
        ]);

        $this->assertDatabaseHas('uploads', [
            'id' => $alreadyCurrent->id,
            'processed_url' => 'https://res.cloudinary.com/demo/video/upload/q_auto:best,f_auto,vc_auto/v1/deymake/uploads/videos/user-1/current.mp4',
        ]);

        $this->assertDatabaseHas('uploads', [
            'id' => $customProcessedUrl->id,
            'processed_url' => 'https://cdn.example.com/custom-stream.m3u8',
        ]);

        $this->assertDatabaseHas('uploads', [
            'id' => $nonVideo->id,
            'processed_url' => 'https://res.cloudinary.com/demo/image/upload/q_auto,f_auto/v1/deymake/uploads/images/user-1/photo.jpg',
        ]);
    }
}