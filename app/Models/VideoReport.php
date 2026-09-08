<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoReport extends Model
{
    use HasFactory;

    /**
     * Reasons grouped by moderation severity. Any reason not listed here (or a
     * null reason) is treated as `low`.
     *
     * @var array<string, list<string>>
     */
    public const SEVERITY_REASONS = [
        'high' => ['nudity', 'sexual_content', 'violence', 'graphic_violence', 'hate', 'hate_speech', 'self_harm', 'csam', 'child_safety', 'terrorism', 'threat', 'threats'],
        'medium' => ['harassment', 'bullying', 'copyright', 'impersonation', 'misinformation', 'dangerous', 'dangerous_acts', 'scam'],
    ];

    protected $fillable = [
        'video_id',
        'user_id',
        'reason',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Derived moderation severity for the report's reason.
     */
    protected function severity(): Attribute
    {
        return Attribute::get(fn (): string => self::severityForReason($this->reason));
    }

    public static function severityForReason(?string $reason): string
    {
        $normalized = mb_strtolower(trim((string) $reason));

        foreach (self::SEVERITY_REASONS as $severity => $reasons) {
            if (in_array($normalized, $reasons, true)) {
                return $severity;
            }
        }

        return 'low';
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ContentModerationCase::class, 'video_id', 'moderatable_id')
            ->where('moderatable_type', Video::class);
    }
}
