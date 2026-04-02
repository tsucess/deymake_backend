<?php

use App\Models\Upload;
use App\Services\CloudinaryUploadService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('uploads:backfill-video-processed-urls {--write : Persist changes instead of running in dry-run mode}', function () {
    $service = app(CloudinaryUploadService::class);
    $write = (bool) $this->option('write');
    $scanned = 0;
    $candidates = 0;
    $updated = 0;
    $alreadyCurrent = 0;
    $skippedUnmanagedPath = 0;
    $skippedCustomUrl = 0;

    $this->components->info($write
        ? 'Applying Cloudinary video processed_url backfill.'
        : 'Dry run only. No records will be updated.'
    );

    Upload::query()
        ->where('type', 'video')
        ->where('disk', 'cloudinary')
        ->orderBy('id')
        ->chunkById(200, function ($uploads) use (
            $service,
            $write,
            &$scanned,
            &$candidates,
            &$updated,
            &$alreadyCurrent,
            &$skippedUnmanagedPath,
            &$skippedCustomUrl,
        ): void {
            foreach ($uploads as $upload) {
                $scanned++;

                if (! $service->isManagedUrl($upload->path)) {
                    $skippedUnmanagedPath++;

                    continue;
                }

                if ($upload->processed_url && ! $service->isManagedUrl($upload->processed_url)) {
                    $skippedCustomUrl++;

                    continue;
                }

                $expectedProcessedUrl = $service->processedUrlFor($upload->path, 'video');

                if ($upload->processed_url === $expectedProcessedUrl) {
                    $alreadyCurrent++;

                    continue;
                }

                $candidates++;

                if (! $write) {
                    continue;
                }

                $upload->forceFill([
                    'processed_url' => $expectedProcessedUrl,
                ])->saveQuietly();

                $updated++;
            }
        });

    $this->newLine();
    $this->table(['Metric', 'Count'], [
        ['Scanned Cloudinary videos', $scanned],
        ['Already current', $alreadyCurrent],
        ['Candidates for update', $candidates],
        ['Updated', $updated],
        ['Skipped unmanaged path', $skippedUnmanagedPath],
        ['Skipped custom processed_url', $skippedCustomUrl],
    ]);

    if (! $write && $candidates > 0) {
        $this->newLine();
        $this->components->warn('Re-run with --write to persist these changes.');
    }

    return 0;
})->purpose('Backfill Cloudinary video processed_url values to the current default transform');
