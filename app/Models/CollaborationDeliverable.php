<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollaborationDeliverable extends Model
{
    use HasFactory;

    protected $fillable = [
        'collaboration_invite_id',
        'created_by',
        'reviewed_by',
        'draft_video_id',
        'title',
        'brief',
        'feedback',
        'status',
        'submitted_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function collaborationInvite(): BelongsTo
    {
        return $this->belongsTo(CollaborationInvite::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function draftVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'draft_video_id');
    }
}