<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_generate_caption_suggestions_with_locale_and_category_context(): void
    {
        $category = Category::create(['name' => 'Music', 'slug' => 'music']);
        $user = User::factory()->create([
            'bio' => 'Street creator helping shy performers show up boldly.',
            'preferences' => ['language' => 'fr'],
        ]);

        Sanctum::actingAs($user);

        $this->withHeaders(['X-Locale' => 'fr'])
            ->postJson('/api/v1/ai/captions', [
                'topic' => 'campus dance challenge',
                'categoryId' => $category->id,
                'tone' => 'bold',
                'goal' => 'engagement',
                'audience' => 'young performers',
                'keywords' => ['campus', 'moves'],
                'count' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.ai.captions_generated'))
            ->assertJsonCount(3, 'data.captions')
            ->assertJsonPath('data.meta.locale', 'fr')
            ->assertJsonPath('data.meta.category', 'Music')
            ->assertJsonPath('data.meta.topic', 'campus dance challenge')
            ->assertJsonPath('data.captions.0.style', 'hook-first')
            ->assertJsonPath('data.captions.0.hashtags.0', '#music')
            ->assertJsonPath('data.captions.0.hashtags.1', '#campusdancechallenge');
    }

    public function test_authenticated_user_can_generate_idea_prompts_using_recent_creator_context(): void
    {
        $category = Category::create(['name' => 'Comedy', 'slug' => 'comedy']);
        $user = User::factory()->create([
            'bio' => 'I create funny campus skits and group moments.',
            'preferences' => ['language' => 'en'],
        ]);

        Video::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'video',
            'title' => 'Late Night Hostel Skit',
            'is_draft' => false,
        ]);

        Sanctum::actingAs($user);

        $this->withHeaders(['X-Locale' => 'pcm'])
            ->postJson('/api/v1/ai/ideas', [
                'goal' => 'growth',
                'format' => 'series',
                'tone' => 'playful',
                'audience' => 'campus viewers',
                'keywords' => ['hostel', 'friends'],
                'count' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('message', trans('messages.ai.ideas_generated'))
            ->assertJsonCount(4, 'data.ideas')
            ->assertJsonPath('data.meta.locale', 'pcm')
            ->assertJsonPath('data.meta.category', 'Comedy')
            ->assertJsonPath('data.meta.topic', 'comedy content')
            ->assertJsonPath('data.ideas.0.title', 'Comedy Content Series')
            ->assertJsonPath('data.ideas.0.hashtags.0', '#comedy')
            ->assertJsonPath('data.ideas.1.title', 'POV: Comedy Content');
    }
}