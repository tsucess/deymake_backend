<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $activityAt = $this->resolveActivityTimestamp($request);

        if ($user && $activityAt && (! $user->last_active_at || $activityAt->gt($user->last_active_at))) {
            $attributes = [
                'is_online' => true,
                'last_active_at' => $activityAt,
            ];

            $userAgent = (string) $request->header('User-Agent', '');
            $platform = $this->resolvePlatform($request, $userAgent);
            $deviceType = $this->resolveDeviceType($userAgent);

            if ($platform !== null) {
                $attributes['last_platform'] = $platform;
            }

            if ($deviceType !== null) {
                $attributes['last_device_type'] = $deviceType;
            }

            $user->forceFill($attributes)->save();
        }

        return $next($request);
    }

    /**
     * Resolve the operating-system platform from an explicit client header or
     * by parsing the User-Agent string (dependency-free).
     */
    private function resolvePlatform(Request $request, string $userAgent): ?string
    {
        $explicit = trim((string) $request->header('X-Client-Platform', ''));

        if ($explicit !== '') {
            return Str::of($explicit)->lower()->limit(30, '')->toString();
        }

        $agent = Str::lower($userAgent);

        return match (true) {
            $agent === '' => null,
            Str::contains($agent, 'android') => 'android',
            Str::contains($agent, ['iphone', 'ipad', 'ipod']) => 'ios',
            Str::contains($agent, 'windows') => 'windows',
            Str::contains($agent, ['mac os', 'macintosh']) => 'macos',
            Str::contains($agent, 'linux') => 'linux',
            default => 'other',
        };
    }

    /**
     * Classify the device form factor from the User-Agent string.
     */
    private function resolveDeviceType(string $userAgent): ?string
    {
        $agent = Str::lower($userAgent);

        if ($agent === '') {
            return null;
        }

        return match (true) {
            Str::contains($agent, ['ipad', 'tablet']) => 'tablet',
            Str::contains($agent, ['mobile', 'android', 'iphone', 'ipod']) => 'mobile',
            default => 'desktop',
        };
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
