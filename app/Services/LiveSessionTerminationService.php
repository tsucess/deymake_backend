<?php

namespace App\Services;

use App\Events\LiveEnded;
use App\Models\Video;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LiveSessionTerminationService
{
    public function end(Video $video): bool
    {
        return $this->terminate($video);
    }

    public function endIfHostInactive(Video $video, Carbon $presenceCutoff): bool
    {
        return $this->terminate($video, $presenceCutoff);
    }

    private function terminate(Video $video, ?Carbon $presenceCutoff = null): bool
    {
        $endedSession = DB::transaction(function () use ($video, $presenceCutoff): ?array {
            $lockedVideo = Video::query()->whereKey($video->id)->lockForUpdate()->firstOrFail();
            if (! $lockedVideo->is_live) {
                return null;
            }

            if ($presenceCutoff !== null) {
                $hostPresence = $lockedVideo->livePresenceSessions()
                    ->where('user_id', $lockedVideo->user_id)
                    ->where('role', 'host')
                    ->where('joined_at', '>=', $lockedVideo->live_started_at)
                    ->exists();
                $activeHostPresence = $lockedVideo->livePresenceSessions()
                    ->where('user_id', $lockedVideo->user_id)
                    ->where('role', 'host')
                    ->whereNull('left_at')
                    ->where('joined_at', '>=', $lockedVideo->live_started_at)
                    ->where('last_seen_at', '>=', $presenceCutoff)
                    ->exists();

                if (! $hostPresence || $activeHostPresence) {
                    return null;
                }
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
