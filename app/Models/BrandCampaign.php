<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'business_name',
        'title',
        'objective',
        'status',
        'summary',
        'niche',
        'budget_amount',
        'currency',
        'creators_needed',
        'creator_requirements',
        'min_subscribers',
        'target_categories',
        'target_locations',
        'deliverables',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'target_categories' => 'array',
            'target_locations' => 'array',
            'deliverables' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
