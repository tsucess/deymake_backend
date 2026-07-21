<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HashtagDailyCount extends Model
{
    protected $fillable = [
        'tag',
        'display_tag',
        'category_id',
        'bucket_date',
        'posts_count',
    ];

    protected $casts = [
        'bucket_date' => 'date',
        'posts_count' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
