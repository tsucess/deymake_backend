<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorshipProposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'brand_campaign_id',
        'title',
        'brief',
        'fee_amount',
        'currency',
        'status',
        'deliverables',
        'proposed_publish_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'deliverables' => 'array',
            'proposed_publish_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function brandCampaign(): BelongsTo
    {
        return $this->belongsTo(BrandCampaign::class);
    }
}