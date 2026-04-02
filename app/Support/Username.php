<?php

namespace App\Support;

use Illuminate\Support\Str;

class Username
{
    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 30;

    public const VALIDATION_REGEX = '/^[a-z0-9._]{3,30}$/';

    public const MENTION_REGEX = '/(?P<type>@|#)(?P<handle>[a-zA-Z0-9._]{3,30})/';

    public static function normalize(string $value, string $fallback = 'user'): string
    {
        $normalized = self::normalizeBase($value);

        if ($normalized === '') {
            $normalized = self::normalizeBase($fallback);
        }

        if ($normalized === '') {
            $normalized = 'user';
        }

        $normalized = Str::substr($normalized, 0, self::MAX_LENGTH);
        $normalized = trim($normalized, '._');

        if ($normalized === '') {
            $normalized = 'user';
        }

        if (strlen($normalized) < self::MIN_LENGTH) {
            $normalized = str_pad($normalized, self::MIN_LENGTH, '0');
        }

        return Str::substr($normalized, 0, self::MAX_LENGTH);
    }

    public static function unique(string $value, callable $exists, string $fallback = 'user'): string
    {
        $base = self::normalize($value, $fallback);

        if (! $exists($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 9999; $suffix++) {
            $suffixString = '.'.$suffix;
            $trimmedBase = Str::substr($base, 0, self::MAX_LENGTH - strlen($suffixString));
            $trimmedBase = rtrim($trimmedBase, '._');
            $candidate = ($trimmedBase !== '' ? $trimmedBase : 'user').$suffixString;

            if (! $exists($candidate)) {
                return $candidate;
            }
        }

        do {
            $suffixString = '.'.Str::lower(Str::random(6));
            $trimmedBase = Str::substr($base, 0, self::MAX_LENGTH - strlen($suffixString));
            $trimmedBase = rtrim($trimmedBase, '._');
            $candidate = ($trimmedBase !== '' ? $trimmedBase : 'user').$suffixString;
        } while ($exists($candidate));

        return $candidate;
    }

    public static function extractMentionedHandles(?string ...$texts): array
    {
        $handles = [];

        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            preg_match_all(self::MENTION_REGEX, $text, $matches);

            foreach ($matches['handle'] ?? [] as $handle) {
                $handles[] = self::normalize($handle);
            }
        }

        return array_values(array_unique($handles));
    }

    private static function normalizeBase(string $value): string
    {
        $normalized = Str::lower($value);
        $normalized = preg_replace('/[^a-z0-9._]+/', '.', $normalized) ?? '';
        $normalized = preg_replace('/\.{2,}/', '.', $normalized) ?? $normalized;
        $normalized = preg_replace('/_{2,}/', '_', $normalized) ?? $normalized;

        return trim($normalized, '._');
    }
}