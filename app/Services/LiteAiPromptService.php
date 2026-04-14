<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;

class LiteAiPromptService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{captions: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function generateCaptions(User $user, array $input, string $locale): array
    {
        $count = max(1, min(5, (int) ($input['count'] ?? 3)));
        $category = $this->resolveCategory($user, $input['categoryId'] ?? null);
        $topic = $this->resolveTopic($input['topic'] ?? null, $category?->name, $user);
        $tone = (string) ($input['tone'] ?? 'confident');
        $goal = (string) ($input['goal'] ?? 'engagement');
        $audience = trim((string) ($input['audience'] ?? 'your audience'));
        $keywords = $this->keywords($input['keywords'] ?? []);
        $includeHashtags = (bool) ($input['includeHashtags'] ?? true);
        $pack = $this->localePack($locale);
        $creatorVibe = $this->creatorVibe($user);

        $captionStyles = [
            [
                'label' => 'hook-first',
                'text' => fn () => trim(sprintf(
                    '%s %s %s %s',
                    $this->hookForTone($tone, $topic, $pack),
                    $this->contextSentence($topic, $category?->name, $creatorVibe),
                    $this->goalSentence($goal, $audience, $pack),
                    $this->ctaForGoal($goal, $pack),
                )),
            ],
            [
                'label' => 'community',
                'text' => fn () => trim(sprintf(
                    '%s %s %s',
                    $this->communityLead($topic, $pack),
                    $this->contextSentence($topic, $category?->name, $creatorVibe),
                    $this->ctaForGoal('community', $pack),
                )),
            ],
            [
                'label' => 'story',
                'text' => fn () => trim(sprintf(
                    '%s %s %s',
                    $this->storyLead($topic, $pack),
                    $this->storySentence($goal, $audience, $creatorVibe),
                    $this->ctaForGoal($goal, $pack),
                )),
            ],
            [
                'label' => 'challenge',
                'text' => fn () => trim(sprintf(
                    '%s %s %s',
                    $this->challengeLead($topic, $pack),
                    $this->challengeSentence($audience, $tone, $pack),
                    $this->ctaForGoal('challenge', $pack),
                )),
            ],
            [
                'label' => 'promo',
                'text' => fn () => trim(sprintf(
                    '%s %s %s',
                    $this->promoLead($topic, $pack),
                    $this->goalSentence('promotion', $audience, $pack),
                    $this->ctaForGoal('promotion', $pack),
                )),
            ],
        ];

        $captions = [];

        for ($i = 0; $i < $count; $i++) {
            $style = $captionStyles[$i % count($captionStyles)];
            $hashtags = $includeHashtags ? $this->hashtags($topic, $keywords, $category?->slug, $goal) : [];

            $captions[] = [
                'style' => $style['label'],
                'text' => preg_replace('/\s+/', ' ', trim($style['text']())),
                'hashtags' => $hashtags,
                'hook' => $this->hookForTone($tone, $topic, $pack),
            ];
        }

        return [
            'captions' => $captions,
            'meta' => [
                'locale' => $locale,
                'topic' => $topic,
                'tone' => $tone,
                'goal' => $goal,
                'audience' => $audience,
                'category' => $category?->name,
                'creatorVibe' => $creatorVibe,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ideas: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function generateIdeas(User $user, array $input, string $locale): array
    {
        $count = max(1, min(6, (int) ($input['count'] ?? 4)));
        $category = $this->resolveCategory($user, $input['categoryId'] ?? null);
        $topic = $this->resolveTopic($input['topic'] ?? null, $category?->name, $user);
        $tone = (string) ($input['tone'] ?? 'playful');
        $goal = (string) ($input['goal'] ?? 'growth');
        $format = (string) ($input['format'] ?? 'video');
        $audience = trim((string) ($input['audience'] ?? 'new viewers'));
        $keywords = $this->keywords($input['keywords'] ?? []);
        $primaryKeyword = $keywords[0] ?? 'your core moment';
        $pack = $this->localePack($locale);
        $creatorVibe = $this->creatorVibe($user);

        $structures = [
            fn (int $i) => [
                'title' => Str::title($topic).' '.($format === 'series' ? 'Series' : 'Breakdown'),
                'hook' => $this->hookForTone($tone, $topic, $pack),
                'concept' => "Show {$topic} through a {$format} format built for {$audience}.",
                'execution' => "Open with a strong first 2 seconds, then reveal {$primaryKeyword} and end with a community CTA.",
                'captionStarter' => $this->hookForTone($tone, $topic, $pack),
                'hashtags' => $this->hashtags($topic, $keywords, $category?->slug, $goal),
            ],
            fn (int $i) => [
                'title' => 'POV: '.Str::title($topic),
                'hook' => $this->communityLead($topic, $pack),
                'concept' => "Create a relatable POV around {$topic} that makes {$audience} feel seen.",
                'execution' => "Use {$creatorVibe}, keep the edit tight, and ask viewers to share their version in the comments.",
                'captionStarter' => $this->communityLead($topic, $pack),
                'hashtags' => $this->hashtags($topic, $keywords, $category?->slug, 'community'),
            ],
            fn (int $i) => [
                'title' => Str::title($topic).' Challenge Prompt',
                'hook' => $this->challengeLead($topic, $pack),
                'concept' => "Turn {$topic} into a repeatable challenge that encourages remixes, duets, or stitches.",
                'execution' => "Demonstrate the format once, set one simple rule, and invite {$audience} to try it today.",
                'captionStarter' => $this->challengeLead($topic, $pack),
                'hashtags' => $this->hashtags($topic, $keywords, $category?->slug, 'challenge'),
            ],
            fn (int $i) => [
                'title' => 'Behind the scenes of '.Str::lower($topic),
                'hook' => $this->storyLead($topic, $pack),
                'concept' => "Break down the process, choices, and emotion behind {$topic} with a creator-first voice.",
                'execution' => "Start with the finished result, then cut back to the work-in-progress and one lesson learned.",
                'captionStarter' => $this->storyLead($topic, $pack),
                'hashtags' => $this->hashtags($topic, $keywords, $category?->slug, 'storytelling'),
            ],
            fn (int $i) => [
                'title' => Str::title($topic).' Live Warmup',
                'hook' => $this->promoLead($topic, $pack),
                'concept' => "Use a quick teaser to build momentum before a live session, drop, or launch around {$topic}.",
                'execution' => "Tease one surprising detail, mention when the full version is coming, and invite reminders or shares.",
                'captionStarter' => $this->promoLead($topic, $pack),
                'hashtags' => $this->hashtags($topic, $keywords, $category?->slug, 'promotion'),
            ],
            fn (int $i) => [
                'title' => 'Three ways to approach '.Str::lower($topic),
                'hook' => $this->hookForTone('inspiring', $topic, $pack),
                'concept' => "Teach {$audience} three distinct ways to explore {$topic} without making it feel too advanced.",
                'execution' => "Number each step clearly, add one shortcut tip, and close with a question to drive saves and comments.",
                'captionStarter' => $this->hookForTone('inspiring', $topic, $pack),
                'hashtags' => $this->hashtags($topic, $keywords, $category?->slug, 'education'),
            ],
        ];

        $ideas = [];

        for ($i = 0; $i < $count; $i++) {
            $ideas[] = $structures[$i % count($structures)]($i);
        }

        return [
            'ideas' => $ideas,
            'meta' => [
                'locale' => $locale,
                'topic' => $topic,
                'tone' => $tone,
                'goal' => $goal,
                'format' => $format,
                'audience' => $audience,
                'category' => $category?->name,
                'creatorVibe' => $creatorVibe,
            ],
        ];
    }

    private function resolveCategory(User $user, mixed $categoryId): ?Category
    {
        if ($categoryId) {
            return Category::query()->find($categoryId);
        }

        $recentCategoryId = $user->videos()->whereNotNull('category_id')->latest('id')->value('category_id');

        return $recentCategoryId ? Category::query()->find($recentCategoryId) : null;
    }

    private function resolveTopic(?string $topic, ?string $categoryName, User $user): string
    {
        $candidate = trim((string) $topic);

        if ($candidate !== '') {
            return $candidate;
        }

        if ($categoryName) {
            return Str::lower($categoryName).' content';
        }

        return $user->bio
            ? Str::of($user->bio)->limit(40, '')->toString()
            : 'your next creator post';
    }

    /**
     * @param  mixed  $keywords
     * @return array<int, string>
     */
    private function keywords(mixed $keywords): array
    {
        return collect(is_array($keywords) ? $keywords : [])
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    private function creatorVibe(User $user): string
    {
        $bio = trim((string) $user->bio);

        if ($bio === '') {
            return 'a confident creator voice';
        }

        return Str::of($bio)->limit(60, '...')->toString();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function localePack(string $locale): array
    {
        $base = strtolower(explode('-', $locale)[0]);

        return match ($base) {
            'fr' => [
                'cta' => [
                    'engagement' => 'Dis-moi ce que tu en penses en commentaire.',
                    'community' => 'Identifie quelqu’un qui doit voir ça.',
                    'promotion' => 'Reste branché pour la suite.',
                    'challenge' => 'Essaie-le et tague-moi.',
                ],
                'lead' => [
                    'story' => 'Petit aperçu derrière le moment :',
                    'community' => 'Question rapide pour la communauté :',
                ],
            ],
            'yo' => [
                'cta' => [
                    'engagement' => 'So fun mi ninu comment ohun ti o ro.',
                    'community' => 'Tag eniyan kan to gbodo ri eyi.',
                    'promotion' => 'Maa duro de eyi to n bo.',
                    'challenge' => 'Dan an wo, ki o si tag mi.',
                ],
                'lead' => [
                    'story' => 'Eyi ni bi nkan se sele ni kete:',
                    'community' => 'Ibeere kekere fun agbegbe wa:',
                ],
            ],
            'pcm' => [
                'cta' => [
                    'engagement' => 'Drop your mind for comment.',
                    'community' => 'Tag pesin wey suppose see this one.',
                    'promotion' => 'Make you dey ready for wetin dey come.',
                    'challenge' => 'Try am and tag me.',
                ],
                'lead' => [
                    'story' => 'Small backstory be this:',
                    'community' => 'Quick question for our people:',
                ],
            ],
            default => [
                'cta' => [
                    'engagement' => 'Tell me what you think in the comments.',
                    'community' => 'Tag someone who should see this.',
                    'promotion' => 'Stay close for the full drop.',
                    'challenge' => 'Try it and tag me when you do.',
                ],
                'lead' => [
                    'story' => 'A quick look behind the moment:',
                    'community' => 'Quick question for the community:',
                ],
            ],
        };
    }

    private function hookForTone(string $tone, string $topic, array $pack): string
    {
        return match ($tone) {
            'bold' => 'Stop scrolling — this '.Str::lower($topic).' needed to be said.',
            'playful' => 'POV: '.Str::lower($topic).' just got more fun.',
            'inspiring' => 'A little reminder from '.Str::lower($topic).': keep showing up.',
            'funny' => 'Honestly, '.Str::lower($topic).' had no reason to go this hard.',
            'smooth' => 'Soft energy, strong message: '.Str::lower($topic).'.',
            default => 'Here is your next take on '.Str::lower($topic).'.',
        };
    }

    private function contextSentence(string $topic, ?string $categoryName, string $creatorVibe): string
    {
        $categoryChunk = $categoryName ? "Built inside the {$categoryName} lane" : 'Built around your core niche';

        return trim("{$categoryChunk}, this version leans into {$creatorVibe} and keeps {$topic} easy to connect with.");
    }

    private function goalSentence(string $goal, string $audience, array $pack): string
    {
        return match ($goal) {
            'storytelling' => "Let {$audience} into the real story behind the moment.",
            'promotion' => "Give {$audience} a reason to come back for the next drop.",
            'challenge' => "Make {$audience} want to join in immediately.",
            'community' => "Turn this post into a shared moment for {$audience}.",
            default => "Give {$audience} something worth reacting to.",
        };
    }

    private function ctaForGoal(string $goal, array $pack): string
    {
        return $pack['cta'][$goal] ?? $pack['cta']['engagement'];
    }

    private function communityLead(string $topic, array $pack): string
    {
        return trim(($pack['lead']['community'] ?? 'Quick question for the community:').' '.Str::headline($topic).'.');
    }

    private function storyLead(string $topic, array $pack): string
    {
        return trim(($pack['lead']['story'] ?? 'A quick look behind the moment:').' '.Str::headline($topic).'.');
    }

    private function storySentence(string $goal, string $audience, string $creatorVibe): string
    {
        return "This one carries {$creatorVibe} and gives {$audience} a clearer reason to care.";
    }

    private function challengeLead(string $topic, array $pack): string
    {
        return 'Your turn: put your own spin on '.Str::lower($topic).'.';
    }

    private function challengeSentence(string $audience, string $tone, array $pack): string
    {
        return "Keep it {$tone}, make it easy to copy, and invite {$audience} to respond with their version.";
    }

    private function promoLead(string $topic, array $pack): string
    {
        return 'Saving this '.$topic.' for the people who have been paying attention.';
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array<int, string>
     */
    private function hashtags(string $topic, array $keywords, ?string $categorySlug, string $goal): array
    {
        $tags = collect([
            $categorySlug,
            Str::slug($topic, ''),
            ...array_map(fn ($keyword) => Str::slug($keyword, ''), $keywords),
            Str::slug($goal, ''),
            'deymake',
        ])
            ->filter()
            ->map(fn ($tag) => '#'.Str::of((string) $tag)->replace('-', '')->toString())
            ->unique()
            ->take(6)
            ->values()
            ->all();

        return $tags;
    }
}