<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Music',
                'slug' => 'music',
                'hashtag_aliases' => ['music', 'afrobeats', 'amapiano', 'naijamusic', 'song', 'lyrics', 'freestyle', 'cover', 'rap', 'hiphop'],
            ],
            [
                'name' => 'Dance',
                'slug' => 'dance',
                'hashtag_aliases' => ['dance', 'dancechallenge', 'choreography', 'legwork', 'zanku', 'shaku', 'afrodance'],
            ],
            [
                'name' => 'Comedy',
                'slug' => 'comedy',
                'hashtag_aliases' => ['comedy', 'funny', 'skits', 'skit', 'laugh', 'humour', 'jokes', 'naijacomedy'],
            ],
            [
                'name' => 'Fashion',
                'slug' => 'fashion',
                'hashtag_aliases' => ['fashion', 'style', 'outfit', 'ootd', 'ankara', 'gele', 'aso', 'asoebi', 'thrift', 'streetwear'],
            ],
            [
                'name' => 'Beauty',
                'slug' => 'beauty',
                'hashtag_aliases' => ['beauty', 'makeup', 'mua', 'skincare', 'hair', 'wig', 'braids', 'grwm'],
            ],
            [
                'name' => 'Food',
                'slug' => 'food',
                'hashtag_aliases' => ['food', 'foodie', 'cooking', 'recipe', 'jollof', 'naijafood', 'foodtok', 'chef', 'baking'],
            ],
            [
                'name' => 'Sports',
                'slug' => 'sports',
                'hashtag_aliases' => ['sports', 'football', 'soccer', 'basketball', 'boxing', 'workout', 'fitness', 'gym', 'superfalcons', 'supereagles'],
            ],
            [
                'name' => 'Gaming',
                'slug' => 'gaming',
                'hashtag_aliases' => ['gaming', 'gamer', 'fifa', 'callofduty', 'pubg', 'esports', 'twitch', 'stream'],
            ],
            [
                'name' => 'Tech',
                'slug' => 'tech',
                'hashtag_aliases' => ['tech', 'technology', 'ai', 'startup', 'coding', 'developer', 'programming', 'gadgets'],
            ],
            [
                'name' => 'Education',
                'slug' => 'education',
                'hashtag_aliases' => ['education', 'learn', 'learning', 'school', 'university', 'jamb', 'waec', 'studytok', 'tutorial'],
            ],
            [
                'name' => 'News',
                'slug' => 'news',
                'hashtag_aliases' => ['news', 'update', 'breakingnews', 'naijanews', 'politics', 'election'],
            ],
            [
                'name' => 'Lifestyle',
                'slug' => 'lifestyle',
                'hashtag_aliases' => ['lifestyle', 'vlog', 'dailyvlog', 'aestheticlife', 'motivation', 'selfcare'],
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'hashtag_aliases' => ['business', 'entrepreneur', 'hustle', 'sme', 'ecommerce', 'marketing', 'branding'],
            ],
            [
                'name' => 'Travel',
                'slug' => 'travel',
                'hashtag_aliases' => ['travel', 'tourism', 'wanderlust', 'lagos', 'abuja', 'ph', 'japa', 'nomad'],
            ],
            [
                'name' => 'Nollywood',
                'slug' => 'nollywood',
                'hashtag_aliases' => ['nollywood', 'nollywoodmovies', 'movie', 'film', 'actor', 'actress', 'shortfilm'],
            ],
            [
                'name' => 'Faith',
                'slug' => 'faith',
                'hashtag_aliases' => ['faith', 'church', 'gospel', 'prayer', 'praise', 'worship', 'islam', 'christianity'],
            ],
            [
                'name' => 'Health',
                'slug' => 'health',
                'hashtag_aliases' => ['health', 'wellness', 'mentalhealth', 'yoga', 'nutrition', 'diet'],
            ],
            [
                'name' => 'Art',
                'slug' => 'art',
                'hashtag_aliases' => ['art', 'artist', 'drawing', 'painting', 'sketch', 'digitalart', 'craft'],
            ],
        ];

        foreach ($categories as $data) {
            Category::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'hashtag_aliases' => $data['hashtag_aliases'],
                ],
            );
        }
    }
}
