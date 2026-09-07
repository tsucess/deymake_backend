<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Centralised audit trail writer.
 *
 * Records who performed a sensitive (mostly financial) action against which
 * record, with contextual metadata. Used across the commerce/payments domain
 * (orders, refunds, discounts, payouts, coins, gifts) to keep an immutable
 * record of state transitions independent of webhooks and notifications.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        string $action,
        ?Model $auditable = null,
        ?int $actorId = null,
        array $metadata = [],
        ?string $ip = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $actorId,
            'action' => $action,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => $ip,
        ]);
    }
}
