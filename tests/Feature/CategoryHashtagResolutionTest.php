<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryHashtagResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Category::create([
            'name' => 'Music',
            'slug' => 'music',
            'hashtag_aliases' => ['afrobeats', 'amapiano', 'song'],
        ]);

        Category::create([
            'name' => 'Comedy',
            'slug' => 'comedy',
            'hashtag_aliases' => ['funny', 'skits', 'naijacomedy'],
        ]);

        Category::create([
            'name' => 'Tech',
            'slug' => 'tech',
            'hashtag_aliases' => ['coding', 'ai', 'startup'],
        ]);
    }

    public function test_extract_hashtags_returns_empty_for_null_or_empty_input(): void
    {
        $this->assertSame([], Category::extractHashtags(null));
        $this->assertSame([], Category::extractHashtags(''));
        $this->assertSame([], Category::extractHashtags('   '));
    }

    public function test_extract_hashtags_returns_empty_when_no_tags_present(): void
    {
        $this->assertSame([], Category::extractHashtags('just some plain text without tags'));
    }

    public function test_extract_hashtags_lowercases_and_dedupes(): void
    {
        $tags = Category::extractHashtags('Loving my #Afrobeats mix — pure #AFROBEATS energy #Naija');

        $this->assertSame(['afrobeats', 'naija'], $tags);
    }

    public function test_resolve_returns_null_when_no_hashtags(): void
    {
        $this->assertNull(Category::resolveFromHashtags('nothing tagged here'));
        $this->assertNull(Category::resolveFromHashtags(null));
        $this->assertNull(Category::resolveFromHashtags(''));
    }

    public function test_resolve_returns_null_when_no_alias_matches(): void
    {
        $this->assertNull(Category::resolveFromHashtags('random #unknowntag #nothingmatches'));
    }

    public function test_resolve_matches_case_insensitively(): void
    {
        $music = Category::where('slug', 'music')->firstOrFail();

        $this->assertSame($music->id, Category::resolveFromHashtags('New drop #AFROBEATS'));
        $this->assertSame($music->id, Category::resolveFromHashtags('New drop #afrobeats'));
    }

    public function test_resolve_matches_via_slug_even_without_alias_entry(): void
    {
        $tech = Category::where('slug', 'tech')->firstOrFail();

        $this->assertSame($tech->id, Category::resolveFromHashtags('Explaining #tech today'));
    }

    public function test_resolve_returns_first_matching_category_when_multiple_tags_match_different_categories(): void
    {
        $firstCategoryId = Category::query()->orderBy('id')->value('id');

        $this->assertSame(
            $firstCategoryId,
            Category::resolveFromHashtags('Cross-genre post #afrobeats #coding #funny'),
        );
    }

    public function test_resolve_ignores_hashes_that_are_not_valid_tags(): void
    {
        $this->assertNull(Category::resolveFromHashtags('empty hash # then #!bang'));
    }
}
