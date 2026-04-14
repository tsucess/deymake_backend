<?php

namespace App\Services;

use App\Models\AiEditingProject;
use Illuminate\Support\Str;

class AiEditingStudioService
{
    /**
     * @return array<string, mixed>
     */
    public function generate(AiEditingProject $project): array
    {
        $video = $project->sourceVideo;
        $upload = $project->sourceUpload;
        $seed = trim((string) ($project->title ?: $video?->title ?: $video?->caption ?: $upload?->original_name ?: 'creator story'));
        $duration = (float) ($upload?->duration ?? 30.0);
        $operations = collect($project->operations ?? ['hooks', 'captions', 'cutdowns', 'thumbnails'])
            ->map(fn ($value) => (string) $value)
            ->values()
            ->all();

        return [
            'seed' => $seed,
            'operations' => $operations,
            'hooks' => [
                'Start with the strongest reveal from '.$seed.'.',
                'Open with a bold promise tied to '.Str::headline($seed).'.',
                'Lead with a fast before-and-after comparison.',
            ],
            'cutSuggestions' => [
                ['label' => 'Hook', 'startSecond' => 0, 'endSecond' => min(5, (int) ceil($duration / 6))],
                ['label' => 'Payoff', 'startSecond' => max(1, (int) floor($duration / 2) - 3), 'endSecond' => min((int) ceil($duration), max(8, (int) floor($duration / 2) + 4))],
                ['label' => 'CTA', 'startSecond' => max(1, (int) floor($duration) - 4), 'endSecond' => max(2, (int) floor($duration))],
            ],
            'captionHighlights' => [
                'Key line: '.Str::headline($seed),
                'Use concise subtitles for the first 3 seconds.',
                'Add a final CTA overlay to drive comments.',
            ],
            'thumbnailIdeas' => [
                'Tight face crop with high-contrast text',
                'Mid-action frame with one bold promise',
                'Before vs after split-frame layout',
            ],
            'publishingChecklist' => [
                'Keep opening under 2 seconds.',
                'Show the payoff before the midpoint.',
                'End with one comment-driving question.',
            ],
        ];
    }
}