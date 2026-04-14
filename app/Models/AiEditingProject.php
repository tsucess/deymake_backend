<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEditingProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_video_id',
        'source_upload_id',
        'title',
        'operations',
        'output',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'operations' => 'array',
            'output' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'source_video_id');
    }

    public function sourceUpload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'source_upload_id');
    }
}