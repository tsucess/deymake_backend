<?php

namespace App\Services\Payments;

use App\Models\MerchOrder;
use App\Models\Payment;
use App\Services\WalletLedgerService;
use Illuminate\Support\Facades\DB;

class MerchOrderPaymentService
{
    public function __construct(private readonly WalletLedgerService $walletLedger)
    {
    }

    public function settle(Payment $payment): void
    {
        if ($payment->purpose !== 'merch_order' || ! $payment->purpose_id || $payment->status !== 'successful') {
            return;
        }

        DB::transaction(function () use ($payment): void {
            $order = MerchOrder::query()->lockForUpdate()->find($payment->purpose_id);

            if (! $order || $order->status !== 'pending') {
                return;
            }

            $order->forceFill([
                'status' => 'paid',
                'placed_at' => $order->placed_at ?? now(),
            ])->save();

            $this->walletLedger->recordCredit(
                $order->creator_id,
                'merch_sale_credit',
                (int) $order->total_amount,
                $order->currency,
                'Merch order payment confirmed.',
                [
                    'merchOrderId' => $order->id,
                    'buyerId' => $order->buyer_id,
                    'productId' => $order->merch_product_id,
                    'paymentId' => $payment->id,
                ],
                $order->placed_at,
            );
        });
    }
}
