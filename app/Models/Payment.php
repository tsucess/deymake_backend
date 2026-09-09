<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payment.
 *
 * Canonical record of a provider-processed payment (e.g. Paystack). A payment is
 * created in the `pending` state at initialization and is only promoted to
 * `successful` after the provider confirms it, either via a verified webhook or a
 * server-to-server verification call — never on client input alone.
 */
class Payment extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'processing', 'successful', 'failed', 'abandoned', 'refunded'];

    protected $fillable = [
        'reference',
        'provider',
        'provider_reference',
        'user_id',
        'email',
        'purpose',
        'purpose_type',
        'purpose_id',
        'amount',
        'currency',
        'status',
        'channel',
        'fees',
        'authorization_url',
        'gateway_response',
        'is_flagged',
        'flag_reason',
        'reviewed_by',
        'reviewed_at',
        'metadata',
        'paid_at',
        'refunded_at',
        'reconciled_at',
        'reconciliation_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fees' => 'integer',
            'purpose_id' => 'integer',
            'is_flagged' => 'boolean',
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }
}
