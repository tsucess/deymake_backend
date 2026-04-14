<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentModerationCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'moderatable_type',
        'moderatable_id',
        'content_type',
        'source',
        'status',
        'ai_score',
        'ai_risk_level',
        'ai_flags',
        'ai_summary',
        'report_count',
        'last_reported_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'action_reason',
    ];

    protected function casts(): array
    {
        return [
            'ai_flags' => 'array',
            'last_reported_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function moderatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}