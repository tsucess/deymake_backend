<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Video;
use Illuminate\Console\Command;

class BackfillVideoHashtags extends Command
{
    protected $signature = 'videos:backfill-hashtags
        {--chunk=200 : Number of rows to process per batch}
        {--force : Re-extract even when the row already has a hashtags array}';

    protected $description = 'Populate the videos.hashtags JSON column from existing description/caption text.';

    public function handle(): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $force = (bool) $this->option('force');

        $query = Video::query()->select(['id', 'description', 'caption', 'hashtags']);

        if (! $force) {
            $query->whereNull('hashtags');
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No videos require hashtag backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling hashtags for {$total} video(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;

        $query->orderBy('id')->chunkById($chunkSize, function ($videos) use (&$updated, $bar): void {
            foreach ($videos as $video) {
                $hashtags = Category::extractHashtags($video->description ?? $video->caption);
                $video->hashtags = $hashtags;
                $video->saveQuietly();

                if ($hashtags !== []) {
                    $updated++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Rows with hashtags after run: {$updated}.");

        return self::SUCCESS;
    }
}
