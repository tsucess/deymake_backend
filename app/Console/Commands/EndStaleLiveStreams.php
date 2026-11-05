<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\LiveSessionTerminationService;
use Illuminate\Console\Command;

class EndStaleLiveStreams extends Command
{
    protected $signature = 'videos:end-stale-live-streams';

    protected $description = 'End livestreams whose host presence heartbeat has expired';

    public function handle(LiveSessionTerminationService $terminationService): int
    {
        $presenceCutoff = now()->subSeconds(30);
        $ended = 0;

        Video::query()
            ->where('is_live', true)
            ->orderBy('id')
            ->chunkById(100, function ($videos) use ($terminationService, $presenceCutoff, &$ended): void {
                foreach ($videos as $video) {
                    if ($terminationService->endIfHostInactive($video, $presenceCutoff)) {
                        $ended++;
                    }
                }
            });

        $this->info("Ended {$ended} livestream(s) with an inactive host.");

        return self::SUCCESS;
    }
}
