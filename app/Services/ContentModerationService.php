<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\ContentModerationCase;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoReport;
use Illuminate\Database\Eloquent\Model;

class ContentModerationService
{
    public function scanVideo(Video $video, string $source = 'ai_scan'): ContentModerationCase
    {
        return $this->scanModeratable(
            $video,
            'video',
            implode("\n", array_filter([$video->title, $video->caption, $video->description])),
            $source,
        );
    }

    public function scanComment(Comment $comment, string $source = 'ai_scan'): ContentModerationCase
    {
        return $this->scanModeratable($comment, 'comment', (string) $comment->body, $source);
    }

    public function registerVideoReport(VideoReport $report): ContentModerationCase
    {
        $report->loadMissing('video');
        $case = $this->scanVideo($report->video, 'user_report');

        $case->report_count = (int) $case->report_count + 1;
        $case->last_reported_at = now();

        if (! $this->isManualDecisionLocked($case) && ! in_array($case->status, ['restricted', 'removed'], true)) {
            $case->status = 'pending_review';
            $case->source = 'user_report';
        }

        $case->save();

        return $case->fresh(['moderatable', 'reviewer']);
    }

    public function applyManualDecision(
        ContentModerationCase $moderationCase,
        User $admin,
        string $action,
        ?string $notes = null,
        ?string $reason = null,
    ): ContentModerationCase {
        $moderatable = $moderationCase->moderatable;

        $status = match ($action) {
            'approve' => 'approved',
            'restrict' => 'restricted',
            'remove' => 'removed',
        };

        $contentStatus = match ($action) {
            'approve' => 'visible',
            'restrict' => 'restricted',
            'remove' => 'removed',
        };

        $moderationCase->forceFill([
            'source' => 'manual',
            'status' => $status,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
            'action_reason' => $reason,
        ])->save();

        if ($moderatable) {
            $this->applyContentStatus($moderatable, $contentStatus, $admin->id, $notes);
        }

        return $moderationCase->fresh(['moderatable', 'reviewer']);
    }

    private function scanModeratable(Model $moderatable, string $contentType, string $text, string $source): ContentModerationCase
    {
        $analysis = $this->analyzeText($text);

        $moderationCase = ContentModerationCase::query()->firstOrNew([
            'moderatable_type' => $moderatable->getMorphClass(),
            'moderatable_id' => $moderatable->getKey(),
        ]);

        $moderationCase->content_type = $contentType;
        $moderationCase->source = $source;
        $moderationCase->ai_score = $analysis['score'];
        $moderationCase->ai_risk_level = $analysis['riskLevel'];
        $moderationCase->ai_flags = $analysis['flags'];
        $moderationCase->ai_summary = $analysis['summary'];

        if (! $this->isManualDecisionLocked($moderationCase)) {
            $recommendedStatus = $analysis['recommendedStatus'];

            if ((int) $moderationCase->report_count > 0 && $recommendedStatus === 'clean') {
                $recommendedStatus = 'pending_review';
            }

            $moderationCase->status = $recommendedStatus;

            $contentStatus = $recommendedStatus === 'restricted' ? 'restricted' : 'visible';
            $contentNotes = $contentStatus === 'restricted' ? $analysis['summary'] : null;

            $this->applyContentStatus($moderatable, $contentStatus, null, $contentNotes);
        }

        $moderationCase->save();

        return $moderationCase->fresh(['moderatable', 'reviewer']);
    }

    /**
     * @return array{score:int,riskLevel:string,flags:array<int,string>,summary:?string,recommendedStatus:string}
     */
    private function analyzeText(string $text): array
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '') {
            return [
                'score' => 0,
                'riskLevel' => 'none',
                'flags' => [],
                'summary' => null,
                'recommendedStatus' => 'clean',
            ];
        }

        $categories = [
            'spam' => ['free money', 'crypto giveaway', 'bet now', 'click here', 'telegram', 'whatsapp', 'dm now'],
            'sexual' => ['xxx', 'nude', 'explicit', 'sex tape', 'onlyfans'],
            'violence' => ['kill', 'murder', 'shoot', 'stab', 'bomb'],
            'hate' => ['racist', 'terrorist', 'genocide', 'hate speech'],
            'harassment' => ['idiot', 'stupid', 'die', 'worthless'],
        ];

        $weights = [
            'spam' => 55,
            'sexual' => 85,
            'violence' => 75,
            'hate' => 85,
            'harassment' => 45,
        ];

        $flags = [];
        $score = 0;

        foreach ($categories as $flag => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, $phrase)) {
                    $flags[] = $flag;
                    $score += $weights[$flag];
                    break;
                }
            }
        }

        if (preg_match('/https?:\/\//i', $text) || preg_match('/www\./i', $text)) {
            $flags[] = 'external_link';
            $score += 15;
        }

        if (preg_match('/\b[A-Z]{6,}\b/', $text)) {
            $flags[] = 'aggressive_formatting';
            $score += 10;
        }

        $flags = array_values(array_unique($flags));
        $score = min(100, $score);

        $riskLevel = match (true) {
            $score >= 85 => 'high',
            $score >= 45 => 'medium',
            $score > 0 => 'low',
            default => 'none',
        };

        $recommendedStatus = match (true) {
            $score >= 85 => 'restricted',
            $score >= 45 => 'pending_review',
            default => 'clean',
        };

        $summary = empty($flags)
            ? 'AI scan found no significant moderation concerns.'
            : 'AI scan flagged: '.implode(', ', $flags).'.';

        return [
            'score' => $score,
            'riskLevel' => $riskLevel,
            'flags' => $flags,
            'summary' => $summary,
            'recommendedStatus' => $recommendedStatus,
        ];
    }

    private function isManualDecisionLocked(ContentModerationCase $moderationCase): bool
    {
        return $moderationCase->exists
            && $moderationCase->reviewed_by !== null
            && in_array($moderationCase->status, ['approved', 'restricted', 'removed'], true);
    }

    private function applyContentStatus(Model $moderatable, string $status, ?int $adminId = null, ?string $notes = null): void
    {
        $moderatable->forceFill([
            'moderation_status' => $status,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'moderation_notes' => $notes,
        ])->save();
    }
}