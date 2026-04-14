<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoReport extends Model
{
    use HasFactory;

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