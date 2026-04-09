<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $activityAt = $this->resolveActivityTimestamp($request);

        if ($user && $activityAt && (! $user->last_active_at || $activityAt->gt($user->last_active_at))) {
            $user->forceFill([
                'is_online' => true,
                'last_active_at' => $activityAt,
            ])->save();
        }

        return $next($request);
    }

    private function resolveActivityTimestamp(Request $request): ?Carbon
    {
        $value = trim((string) $request->header('X-User-Activity-At', ''));

        if ($value === '') {
            return null;
        }

        try {
            $activityAt = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $activityAt->isFuture() ? now() : $activityAt;
    }
}