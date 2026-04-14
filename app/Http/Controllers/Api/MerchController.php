<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchOrderResource;
use App\Http\Resources\MerchProductResource;
use App\Models\MerchOrder;
use App\Models\MerchProduct;
use App\Models\User;
use App\Services\WalletLedgerService;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MerchController extends Controller
{
    public function creatorProducts(Request $request, User $user): JsonResponse
    {
        SupportedLocales::apply($request);

        $products = MerchProduct::query()
            ->where('creator_id', $user->id)
            ->where('status', 'active')
            ->with(['creator' => fn ($query) => $query->withProfileAggregates(auth('sanctum')->user() ?? $request->user())])
            ->latest()
            ->get();

        return response()->json([
            'message' => __('messages.merch.products_retrieved'),
            'data' => [
                'products' => MerchProductResource::collection($products),
            ],
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $this->validateProduct($request);
        $product = MerchProduct::query()->create([
            'creator_id' => $request->user()->id,
            'name' => $validated['name'],
            'status' => $validated['status'] ?? 'active',
            'sku' => $validated['sku'] ?? null,
            'description' => $validated['description'] ?? null,
            'price_amount' => (int) $validated['priceAmount'],
            'currency' => strtoupper((string) ($validated['currency'] ?? 'NGN')),
            'inventory_count' => (int) ($validated['inventoryCount'] ?? 0),
            'images' => $validated['images'] ?? [],
        ]);

        $product->load(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __('messages.merch.product_created'),
            'data' => [
                'product' => new MerchProductResource($product),
            ],
        ], 201);
    }

    public function updateProduct(Request $request, MerchProduct $merchProduct): JsonResponse
    {
        SupportedLocales::apply($request);
        abort_if($merchProduct->creator_id !== $request->user()->id, 403);

        $validated = $this->validateProduct($request, true);
        $merchProduct->fill([
            'name' => $validated['name'] ?? $merchProduct->name,
            'status' => $validated['status'] ?? $merchProduct->status,
            'sku' => array_key_exists('sku', $validated) ? $validated['sku'] : $merchProduct->sku,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $merchProduct->description,
            'price_amount' => $validated['priceAmount'] ?? $merchProduct->price_amount,
            'currency' => isset($validated['currency']) ? strtoupper((string) $validated['currency']) : $merchProduct->currency,
            'inventory_count' => $validated['inventoryCount'] ?? $merchProduct->inventory_count,
            'images' => $validated['images'] ?? $merchProduct->images,
        ])->save();

        $merchProduct->load(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __('messages.merch.product_updated'),
            'data' => [
                'product' => new MerchProductResource($merchProduct),
            ],
        ]);
    }

    public function storeOrder(Request $request, MerchProduct $merchProduct, WalletLedgerService $walletLedgerService): JsonResponse
    {
        SupportedLocales::apply($request);
        abort_if($merchProduct->status !== 'active', 422, __('messages.merch.product_not_active'));

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'shippingAddress' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $quantity = (int) $validated['quantity'];
        abort_if($merchProduct->inventory_count < $quantity, 422, __('messages.merch.insufficient_inventory'));

        $total = $quantity * (int) $merchProduct->price_amount;
        $order = MerchOrder::query()->create([
            'merch_product_id' => $merchProduct->id,
            'creator_id' => $merchProduct->creator_id,
            'buyer_id' => $request->user()->id,
            'quantity' => $quantity,
            'unit_price_amount' => (int) $merchProduct->price_amount,
            'total_amount' => $total,
            'currency' => $merchProduct->currency,
            'status' => 'paid',
            'shipping_address' => $validated['shippingAddress'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'placed_at' => now(),
        ]);

        $merchProduct->decrement('inventory_count', $quantity);
        $walletLedgerService->recordCredit(
            $merchProduct->creator_id,
            'merch_sale_credit',
            $total,
            $merchProduct->currency,
            'Merch order paid.',
            ['merchOrderId' => $order->id, 'buyerId' => $request->user()->id, 'productId' => $merchProduct->id],
            $order->placed_at,
        );

        UserNotifier::sendTranslated(
            $merchProduct->creator_id,
            $request->user()->id,
            'merch_order_created',
            'messages.notifications.merch_order_title',
            'messages.notifications.merch_order_body',
            ['name' => $request->user()->name, 'product' => $merchProduct->name],
            ['merchOrderId' => $order->id],
        );

        $creator = User::query()->findOrFail($merchProduct->creator_id);
        DeveloperWebhookDispatcher::dispatch($creator, 'merch.order.created', [
            'type' => 'merch.order.created',
            'orderId' => $order->id,
            'buyerId' => $request->user()->id,
            'totalAmount' => $total,
            'currency' => $merchProduct->currency,
        ]);

        $order->load([
            'product.creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'buyer' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        return response()->json([
            'message' => __('messages.merch.order_created'),
            'data' => [
                'order' => new MerchOrderResource($order),
            ],
        ], 201);
    }

    public function myOrders(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $orders = MerchOrder::query()
            ->where('buyer_id', $request->user()->id)
            ->with([
                'product.creator' => fn ($query) => $query->withProfileAggregates($request->user()),
                'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
                'buyer' => fn ($query) => $query->withProfileAggregates($request->user()),
            ])
            ->latest('placed_at')
            ->get();

        return response()->json([
            'message' => __('messages.merch.orders_retrieved'),
            'data' => [
                'orders' => MerchOrderResource::collection($orders),
            ],
        ]);
    }

    public function receivedOrders(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $orders = MerchOrder::query()
            ->where('creator_id', $request->user()->id)
            ->with([
                'product.creator' => fn ($query) => $query->withProfileAggregates($request->user()),
                'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
                'buyer' => fn ($query) => $query->withProfileAggregates($request->user()),
            ])
            ->latest('placed_at')
            ->get();

        return response()->json([
            'message' => __('messages.merch.received_orders_retrieved'),
            'data' => [
                'orders' => MerchOrderResource::collection($orders),
            ],
        ]);
    }

    public function updateOrder(Request $request, MerchOrder $merchOrder): JsonResponse
    {
        SupportedLocales::apply($request);
        abort_if($merchOrder->creator_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'action' => ['required', Rule::in(['fulfill', 'cancel'])],
        ]);

        if ($validated['action'] === 'fulfill') {
            $merchOrder->forceFill(['status' => 'fulfilled', 'fulfilled_at' => now()])->save();
        } else {
            if ($merchOrder->status !== 'cancelled') {
                $merchOrder->product()->increment('inventory_count', (int) $merchOrder->quantity);
            }

            $merchOrder->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        }

        UserNotifier::sendTranslated(
            $merchOrder->buyer_id,
            $request->user()->id,
            'merch_order_updated',
            'messages.notifications.merch_order_title',
            'messages.notifications.merch_order_updated_body',
            ['status' => $merchOrder->status],
            ['merchOrderId' => $merchOrder->id, 'status' => $merchOrder->status],
        );

        $buyer = User::query()->findOrFail($merchOrder->buyer_id);
        DeveloperWebhookDispatcher::dispatch($buyer, 'merch.order.updated', [
            'type' => 'merch.order.updated',
            'orderId' => $merchOrder->id,
            'status' => $merchOrder->status,
            'creatorId' => $request->user()->id,
        ]);

        $merchOrder->load([
            'product.creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'buyer' => fn ($query) => $query->withProfileAggregates($request->user()),
        ]);

        return response()->json([
            'message' => __('messages.merch.order_updated'),
            'data' => [
                'order' => new MerchOrderResource($merchOrder),
            ],
        ]);
    }

    private function validateProduct(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'name' => [...$required, 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'sku' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'priceAmount' => [$partial ? 'sometimes' : 'required', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'inventoryCount' => ['sometimes', 'integer', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
        ]);
    }
}