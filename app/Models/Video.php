<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'upload_id',
        'type',
        'title',
        'caption',
        'description',
        'location',
        'tagged_users',
        'media_url',
        'thumbnail_url',
        'is_live',
        'is_draft',
        'views_count',
        'shares_count',
    ];

    protected function casts(): array
    {
        return [
            'tagged_users' => 'array',
            'is_live' => 'boolean',
            'is_draft' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}