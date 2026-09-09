<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment webhook event.
 *
 * Idempotency ledger for inbound provider webhooks. Each verified webhook is
 * recorded once under a unique (provider, dedupe_key); a duplicate delivery of
 * the same event is a no-op, guaranteeing exactly-once processing.
 */
class PaymentWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'event_type',
        'reference',
        'dedupe_key',
        'signature_valid',
        'payload',
        'payment_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
