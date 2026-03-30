<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SupportedLocales
{
    private const SUPPORTED = [
        'en',
        'en-GB',
        'fr',
        'es',
        'yo',
        'ha',
        'ig',
        'pcm',
        'sw',
        'am',
        'zu',
    ];

    public static function all(): array
    {
        return config('locales.supported', self::SUPPORTED);
    }

    public static function match(?string $locale): ?string
    {
        $normalized = static::normalize($locale);

        if ($normalized === '') {
            return null;
        }

        foreach (static::all() as $supportedLocale) {
            if (strcasecmp($supportedLocale, $normalized) === 0) {
                return $supportedLocale;
            }
        }

        $baseLocale = explode('-', strtolower($normalized))[0];

        foreach (static::all() as $supportedLocale) {
            if (explode('-', strtolower($supportedLocale))[0] === $baseLocale) {
                return $supportedLocale;
            }
        }

        return null;
    }

    public static function resolve(?string $locale): string
    {
        return static::match($locale)
            ?? static::match(config('app.locale'))
            ?? static::match(config('app.fallback_locale'))
            ?? 'en';
    }

    public static function fromHeader(?string $headerValue): ?string
    {
        if (! is_string($headerValue) || trim($headerValue) === '') {
            return null;
        }

        foreach (explode(',', $headerValue) as $segment) {
            $candidate = trim(explode(';', $segment)[0]);
            $locale = static::match($candidate);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    public static function fromRequest(Request $request): string
    {
        $authenticatedUser = $request->user()
            ?? auth()->user()
            ?? auth('sanctum')->user();

        return static::fromHeader($request->header('X-Locale'))
            ?? static::fromHeader($request->header('Accept-Language'))
            ?? static::match(data_get($authenticatedUser?->preferences, 'language'))
            ?? static::resolve(config('app.locale'));
    }

    public static function apply(Request $request): string
    {
        $locale = static::fromRequest($request);

        App::setLocale($locale);

        return $locale;
    }

    private static function normalize(?string $locale): string
    {
        return trim(str_replace('_', '-', (string) $locale));
    }
}