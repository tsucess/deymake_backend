<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (int) $this->quantity,
            'unitPriceAmount' => (int) $this->unit_price_amount,
            'totalAmount' => (int) $this->total_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'discountCode' => $this->discount_code,
            'discountAmount' => (int) ($this->discount_amount ?? 0),
            'paymentStatus' => $this->resolvePaymentStatus(),
            'shippingStatus' => $this->resolveShippingStatus(),
            'shippingAddress' => $this->shipping_address ?? [],
            'notes' => $this->notes,
            'placedAt' => $this->placed_at?->toISOString(),
            'fulfilledAt' => $this->fulfilled_at?->toISOString(),
            'cancelledAt' => $this->cancelled_at?->toISOString(),
            'product' => $this->whenLoaded('product', fn () => new MerchProductResource($this->product)),
            'creator' => $this->whenLoaded('creator', fn () => new ProfileResource($this->creator)),
            'buyer' => $this->whenLoaded('buyer', fn () => new ProfileResource($this->buyer)),
            'payment' => $this->when(
                $this->relationLoaded('payments'),
                fn () => $this->latestPayment()
                    ? new PaymentResource($this->latestPayment())
                    : null
            ),
        ];
    }

    /**
     * Payment status for the order, preferring a linked provider payment when the
     * relation is loaded and otherwise deriving it from the order status.
     */
    private function resolvePaymentStatus(): string
    {
        $payment = $this->latestPayment();

        if ($payment !== null) {
            return (string) $payment->status;
        }

        return match ($this->status) {
            'paid', 'fulfilled' => 'paid',
            'refunded' => 'refunded',
            'cancelled' => 'unpaid',
            default => 'pending',
        };
    }

    /**
     * Fulfillment/shipping status derived from the order lifecycle.
     */
    private function resolveShippingStatus(): string
    {
        return match ($this->status) {
            'fulfilled' => 'shipped',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'paid' => 'processing',
            default => 'awaiting_payment',
        };
    }

    private function latestPayment(): ?Payment
    {
        if (! $this->relationLoaded('payments')) {
            return null;
        }

        return $this->payments->sortByDesc('created_at')->first();
    }
}
