<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'thumbnail_url',
        'hashtag_aliases',
        'subscribers_count',
    ];

    protected $casts = [
        'hashtag_aliases' => 'array',
    ];

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public static function extractHashtags(?string $text): array
    {
        if (! is_string($text) || $text === '') {
            return [];
        }

        if (! preg_match_all('/#([\p{L}\p{N}_]{2,50})/u', $text, $matches)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn ($tag) => Str::lower($tag),
            $matches[1]
        )));
    }

    public static function resolveFromHashtags(?string $description): ?int
    {
        $tags = static::extractHashtags($description);

        if ($tags === []) {
            return null;
        }

        foreach (static::query()->get(['id', 'slug', 'hashtag_aliases']) as $category) {
            $aliases = collect($category->hashtag_aliases ?? [])
                ->map(fn ($alias) => Str::lower((string) $alias))
                ->push(Str::lower((string) $category->slug))
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($tags as $tag) {
                if (in_array($tag, $aliases, true)) {
                    return (int) $category->id;
                }
            }
        }

        return null;
    }
}