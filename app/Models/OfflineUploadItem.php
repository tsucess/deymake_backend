<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineUploadItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'upload_id',
        'video_id',
        'client_reference',
        'type',
        'title',
        'status',
        'failure_reason',
        'metadata',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}