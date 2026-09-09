<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use App\Models\MerchOrder;
use App\Models\MerchProduct;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\SupportedLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DiscountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $discounts = Discount::query()
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => __('messages.admin.discounts_retrieved'),
            'data' => ['discounts' => DiscountResource::collection($discounts)],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'code' => ['required', 'string', 'max:50', 'unique:discounts,code'],
            'type' => ['required', Rule::in(['percentage', 'fixed'])],
            'value' => ['required', 'integer', 'min:1'],
            'isActive' => ['nullable', 'boolean'],
            'usageLimit' => ['nullable', 'integer', 'min:1'],
            'perUserLimit' => ['nullable', 'integer', 'min:1'],
            'minOrderAmount' => ['nullable', 'integer', 'min:0'],
            'expiresAt' => ['nullable', 'date'],
            'productIds' => ['nullable', 'array'],
            'productIds.*' => ['integer', 'exists:merch_products,id'],
            'creatorIds' => ['nullable', 'array'],
            'creatorIds.*' => ['integer', 'exists:users,id'],
        ]);

        $discount = Discount::query()->create([
            'name' => $data['name'],
            'code' => $data['code'],
            'type' => $data['type'],
            'value' => $data['value'],
            'is_active' => $data['isActive'] ?? true,
            'usage_limit' => $data['usageLimit'] ?? null,
            'per_user_limit' => $data['perUserLimit'] ?? null,
            'min_order_amount' => $data['minOrderAmount'] ?? 0,
            'expires_at' => isset($data['expiresAt']) ? $data['expiresAt'] : null,
            'product_ids' => $data['productIds'] ?? [],
            'creator_ids' => $data['creatorIds'] ?? [],
            'used_user_ids' => [],
        ]);

        AuditLogger::record('admin.discount_created', $discount, $request->user()?->id, ['name' => $discount->name], $request->ip());

        return response()->json([
            'message' => __('messages.admin.discount_created'),
            'data' => ['discount' => new DiscountResource($discount)],
        ], 201);
    }

    public function update(Request $request, Discount $discount): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('discounts', 'code')->ignore($discount->id)],
            'type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'value' => ['sometimes', 'integer', 'min:1'],
            'isActive' => ['sometimes', 'boolean'],
            'usageLimit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'perUserLimit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'minOrderAmount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'expiresAt' => ['sometimes', 'nullable', 'date'],
            'productIds' => ['sometimes', 'nullable', 'array'],
            'productIds.*' => ['integer', 'exists:merch_products,id'],
            'creatorIds' => ['sometimes', 'nullable', 'array'],
            'creatorIds.*' => ['integer', 'exists:users,id'],
        ]);

        foreach ($data as $key => $value) {
            $attribute = match ($key) {
                'isActive' => 'is_active',
                'usageLimit' => 'usage_limit',
                'perUserLimit' => 'per_user_limit',
                'minOrderAmount' => 'min_order_amount',
                'expiresAt' => 'expires_at',
                'productIds' => 'product_ids',
                'creatorIds' => 'creator_ids',
                default => $key,
            };

            $discount->{$attribute} = $value;
        }

        $discount->save();

        AuditLogger::record('admin.discount_updated', $discount, $request->user()?->id, ['changed' => array_keys($data)], $request->ip());

        return response()->json([
            'message' => __('messages.admin.discount_updated'),
            'data' => ['discount' => new DiscountResource($discount)],
        ]);
    }

    public function destroy(Request $request, Discount $discount): JsonResponse
    {
        SupportedLocales::apply($request);

        AuditLogger::record('admin.discount_deleted', $discount, $request->user()?->id, ['name' => $discount->name], $request->ip());
        $discount->delete();

        return response()->json(['message' => __('messages.admin.discount_deleted')]);
    }

    public function toggle(Request $request, Discount $discount): JsonResponse
    {
        SupportedLocales::apply($request);

        $discount->is_active = ! $discount->is_active;
        $discount->save();

        $action = $discount->is_active ? 'activated' : 'deactivated';

        AuditLogger::record("admin.discount_{$action}", $discount, $request->user()?->id, [], $request->ip());

        return response()->json([
            'message' => __('messages.admin.discount_'.$action),
            'data' => ['discount' => new DiscountResource($discount)],
        ]);
    }

    public function validateCode(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'productId' => ['nullable', 'integer', 'exists:merch_products,id'],
        ]);

        $discount = Discount::query()->where('code', strtoupper(trim($validated['code'])))->first();

        if (! $discount) {
            return response()->json([
                'message' => __('messages.merch.discount_not_found'),
                'errors' => ['code' => [__('messages.merch.discount_not_found')]],
            ], 422);
        }

        $result = $this->validateDiscountForOrder($request->user(), $discount, $validated['productId'] ?? null);

        if ($result !== true) {
            return response()->json([
                'message' => $result,
                'errors' => ['code' => [$result]],
            ], 422);
        }

        return response()->json([
            'message' => __('messages.merch.discount_valid'),
            'data' => ['discount' => new DiscountResource($discount)],
        ]);
    }

    public function validateDiscountForOrder(?User $user, Discount $discount, ?int $productId = null): string|bool
    {
        if (! $discount->is_active) {
            return __('messages.merch.discount_inactive');
        }

        if ($discount->expires_at && $discount->expires_at->isPast()) {
            return __('messages.merch.discount_expired');
        }

        if ($discount->usage_limit !== null && $discount->usage_count >= $discount->usage_limit) {
            return __('messages.merch.discount_usage_limit_reached');
        }

        if ($user && $discount->per_user_limit !== null) {
            $used = is_array($discount->used_user_ids) ? array_values(array_filter($discount->used_user_ids, fn ($value) => (int) $value === $user->id)) : [];
            if (count($used) >= $discount->per_user_limit) {
                return __('messages.merch.discount_user_limit_reached');
            }
        }

        if ($productId !== null) {
            $product = MerchProduct::query()->findOrFail($productId);
            $productIds = $discount->product_ids ?? [];
            if ($productIds !== [] && ! in_array($product->id, $productIds, true)) {
                return __('messages.merch.discount_product_restricted');
            }
        }

        $creatorIds = $discount->creator_ids ?? [];
        if ($creatorIds !== [] && $productId !== null) {
            $product = MerchProduct::query()->findOrFail($productId);
            if (! in_array($product->creator_id, $creatorIds, true)) {
                return __('messages.merch.discount_creator_restricted');
            }
        }

        return true;
    }

    public function applyDiscountToOrder(MerchOrder $order): void
    {
        if (! ($discountCode = $order->discount_code ?? null)) {
            return;
        }

        $discount = Discount::query()->where('code', strtoupper($discountCode))->first();

        if (! $discount) {
            return;
        }

        $result = $this->validateDiscountForOrder($order->buyer, $discount, $order->merch_product_id);

        if ($result !== true) {
            return;
        }

        $discountAmount = $discount->type === 'fixed'
            ? min((int) $discount->value, (int) $order->total_amount)
            : (int) floor(($order->total_amount * (int) $discount->value) / 100);

        $order->discount_code = $discount->code;
        $order->discount_amount = $discountAmount;
        $order->total_amount = max(0, $order->total_amount - $discountAmount);
        $order->save();

        $discount->usage_count = (int) $discount->usage_count + 1;
        $usedUserIds = is_array($discount->used_user_ids) ? $discount->used_user_ids : [];
        $usedUserIds[] = (int) $order->buyer_id;
        $discount->used_user_ids = array_values(array_unique($usedUserIds));
        $discount->save();
    }
}
