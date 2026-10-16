<?php

namespace App\Services;

use App\Events\LiveEnded;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

class LiveSessionTerminationService
{
    public function end(Video $video): bool
    {
        $endedSession = DB::transaction(function () use ($video): ?array {
            $lockedVideo = Video::query()->whereKey($video->id)->lockForUpdate()->firstOrFail();
            if (! $lockedVideo->is_live) {
                return null;
            }

            $endedAt = now();
            $liveStartedAt = $lockedVideo->live_started_at?->toISOString() ?? $endedAt->toISOString();
            $lockedVideo->liveSignals()->delete();
            $lockedVideo->livePresenceSessions()->whereNull('left_at')->update([
                'left_at' => $endedAt,
                'last_seen_at' => $endedAt,
            ]);
            $lockedVideo->forceFill([
                'is_live' => false,
                'is_draft' => true,
                'live_ended_at' => $endedAt,
                'live_notified_at' => null,
            ])->save();

            return [$endedAt->toISOString(), $liveStartedAt];
        });

        if ($endedSession === null) {
            return false;
        }

        LiveEnded::dispatch($video->id, $endedSession[0], $endedSession[1]);

        return true;
    }
}
