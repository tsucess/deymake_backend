<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin dashboard response resource.
 *
 * Wraps the already-assembled dashboard payload (summary counts, chart series,
 * and recent-activity feeds) so the endpoint has a single response-shaping
 * class. Nested resource collections in the payload (users, reports, payouts)
 * are resolved recursively by Laravel's resource serialization.
 *
 * Produced by AdminDashboardController::dashboard().
 */
class AdminDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
