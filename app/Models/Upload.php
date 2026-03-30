<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Upload extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'processing_status',
        'width',
        'height',
        'duration',
        'processed_url',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function getUrlAttribute(): string
    {
        if ($this->disk === 'cloudinary') {
            return $this->processed_url ?: $this->path;
        }

        return Storage::disk($this->disk)->url($this->path);
    }
}