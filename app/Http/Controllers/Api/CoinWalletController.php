<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoinPackageResource;
use App\Http\Resources\CoinPurchaseResource;
use App\Models\CoinPackage;
use App\Models\CoinPurchase;
use App\Models\Gift;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CoinWalletController extends Controller
{
    public function packages(): JsonResponse
    {
        $packages = CoinPackage::query()->where('is_active', true)->orderBy('sort_order')->orderBy('price_amount')->get();

        return response()->json(['data' => ['packages' => CoinPackageResource::collection($packages)]]);
    }
 
    
    public function overview(Request $request): JsonResponse
    {
        $purchases = CoinPurchase::query()->where('user_id', $request->user()->id)->latest('created_at')->get();
        $completed = $purchases->where('status', 'completed');

        return response()->json(['data' => [
            'balance' => (int) $completed->sum('coins'),
            'purchases' => CoinPurchaseResource::collection($purchases),
        ]]);
    }

    public function purchase(Request $request, PaymentGatewayManager $gateways): JsonResponse
    {
        $data = $request->validate([
            'coinId' => ['required', 'integer', 'exists:gifts,id'],
            'provider' => ['nullable', \Illuminate\Validation\Rule::in(PaymentGatewayManager::PROVIDERS)],
        ]);
        $coin = Gift::query()->where('is_active', true)->findOrFail($data['coinId']);
        $user = $request->user();
        $gateway = $gateways->gateway($data['provider'] ?? null);
        abort_unless($gateway->isConfigured(), 503, 'Payment provider is not configured.');
        $payment = Payment::query()->where('user_id', $user->id)
            ->where('purpose', 'coin_purchase')->where('purpose_id', $coin->id)
            ->whereIn('status', ['pending', 'processing'])->latest()->first();

        if (! $payment) {
            $payment = Payment::query()->create([
                'reference' => 'DMK_COIN_'.str()->upper(str()->random(20)),
                'provider' => $gateway->name(),
                'user_id' => $user->id,
                'email' => $user->email,
                'purpose' => 'coin_purchase',
                'purpose_id' => $package->id,
                'amount' => $coin->price_amount,
                'currency' => $coin->currency,
                'status' => 'pending',
                'metadata' => ['coins' => $coin->coin_cost, 'catalog' => 'gift'],
            ]);
        } elseif ($payment->provider !== $gateway->name()) {
            $payment->update(['provider' => $gateway->name()]);
        }

        try {
            $result = $gateway->initialize($payment, $gateways->callbackUrl($gateway->name()));
        } catch (PaymentGatewayException $exception) {
            $payment->update(['status' => 'failed', 'gateway_response' => $exception->getMessage()]);
            return response()->json(['message' => 'Payment provider could not initialize the checkout.'], 422);
        }

        $payment->update([
            'provider_reference' => $result['providerReference'] ?? $payment->reference,
            'authorization_url' => $result['authorizationUrl'] ?? null,
        ]);

        return response()->json(['data' => ['payment' => $payment->fresh(), 'authorizationUrl' => $payment->authorization_url]], 201);
    }

    public function settlePayment(Payment $payment): void
    {
        if ($payment->purpose !== 'coin_purchase' || $payment->status !== 'successful') return;

        DB::transaction(function () use ($payment): void {
            if (CoinPurchase::query()->where('payment_reference', $payment->reference)->lockForUpdate()->exists()) return;
            if (data_get($payment->metadata, 'catalog') === 'gift') {
                $coin = Gift::query()->find($payment->purpose_id);
                if (! $coin) return;
                $coinAmount = (int) $coin->coin_cost;
            } else {
                $package = CoinPackage::query()->find($payment->purpose_id);
                if (! $package) return;
                $coinAmount = (int) $package->coins + (int) $package->bonus_coins;
            }
            CoinPurchase::query()->create([
                'user_id' => $payment->user_id,
                'coin_package_id' => null,
                'coins' => $coinAmount,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => 'completed',
                'payment_provider' => $payment->provider,
                'payment_reference' => $payment->reference,
                'purchased_at' => $payment->paid_at ?? now(),
            ]);
        });
    }
}