<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchOrderResource;
use App\Http\Resources\MerchProductResource;
use App\Models\MerchOrder;
use App\Models\MerchProduct;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin merch product controller.
 *
 * Operator oversight of the creator merch marketplace: catalogue listing,
 * product CRUD, publish state, and inventory control across every creator.
 *
 * Routes: under the admin prefix in routes/api.php.
 * Frontend consumers: Admin/Pages/Product.jsx.
 * Related: MerchProduct, MerchOrder models, MerchController (creator side).
 * See PROJECT_OVERVIEW.md §3.19 for the full data-flow map.
 */
class AdminMerchProductController extends Controller
{
    private const STATUSES = ['draft', 'active', 'archived'];

    private const SOLD_STATUSES = ['paid', 'fulfilled'];

    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $stock = mb_strtolower(trim($request->string('stock')->toString()));
        $sort = trim($request->string('sort')->toString()) ?: 'latest';

        $products = PaginatedJson::paginate(
            $this->applyFilters($this->baseQuery($request), $request)
                ->when($stock === 'out', fn (Builder $b) => $b->where('inventory_count', '<=', 0))
                ->when($stock === 'in', fn (Builder $b) => $b->where('inventory_count', '>', 0))
                ->when($sort === 'oldest', fn (Builder $b) => $b->oldest())
                ->when($sort === 'price_high', fn (Builder $b) => $b->orderByDesc('price_amount'))
                ->when($sort === 'price_low', fn (Builder $b) => $b->orderBy('price_amount'))
                ->when(! in_array($sort, ['oldest', 'price_high', 'price_low'], true), fn (Builder $b) => $b->latest()),
            $request,
            12,
            50
        );

        return response()->json([
            'message' => __('messages.admin.products_retrieved'),
            'data' => [
                'products' => PaginatedJson::items($request, $products, MerchProductResource::class),
            ],
            'meta' => [
                'products' => PaginatedJson::meta($products),
                'summary' => $this->summary(),
            ],
        ]);
    }

    public function show(Request $request, MerchProduct $merchProduct): JsonResponse
    {
        SupportedLocales::apply($request);

        $merchProduct->load(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __('messages.admin.product_retrieved'),
            'data' => [
                'product' => new MerchProductResource($merchProduct),
                'stats' => $this->productStats($merchProduct),
                'recentOrders' => MerchOrderResource::collection(
                    $merchProduct->orders()
                        ->with(['buyer' => fn ($query) => $query->withProfileAggregates($request->user())])
                        ->latest('placed_at')
                        ->limit(5)
                        ->get()
                ),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'creatorId' => ['required', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'priceAmount' => ['required', 'integer', 'min:0'],
            'discountAmount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'inventoryCount' => ['nullable', 'integer', 'min:0'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*' => ['string', 'max:2048'],
        ]);

        $this->assertDiscountWithinPrice(
            $data['priceAmount'],
            $data['discountAmount'] ?? 0
        );

        $product = MerchProduct::query()->create([
            'creator_id' => $data['creatorId'],
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'price_amount' => $data['priceAmount'],
            'discount_amount' => $data['discountAmount'] ?? 0,
            'currency' => strtoupper($data['currency'] ?? 'NGN'),
            'inventory_count' => $data['inventoryCount'] ?? 0,
            'images' => $data['images'] ?? [],
        ]);

        AuditLogger::record('admin.merch_product_created', $product, $request->user()?->id, [
            'name' => $product->name,
            'creatorId' => $product->creator_id,
        ], $request->ip());

        $product->load(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __('messages.admin.product_created'),
            'data' => ['product' => new MerchProductResource($product)],
        ], 201);
    }

    public function update(Request $request, MerchProduct $merchProduct): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'priceAmount' => ['sometimes', 'integer', 'min:0'],
            'discountAmount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'inventoryCount' => ['sometimes', 'integer', 'min:0'],
            'images' => ['sometimes', 'nullable', 'array', 'max:10'],
            'images.*' => ['string', 'max:2048'],
        ]);

        $price = array_key_exists('priceAmount', $data) ? $data['priceAmount'] : $merchProduct->price_amount;
        $discount = array_key_exists('discountAmount', $data) ? $data['discountAmount'] : $merchProduct->discount_amount;
        $this->assertDiscountWithinPrice($price, $discount);

        if (array_key_exists('name', $data)) {
            $merchProduct->name = $data['name'];
        }
        if (array_key_exists('sku', $data)) {
            $merchProduct->sku = $data['sku'];
        }
        if (array_key_exists('description', $data)) {
            $merchProduct->description = $data['description'];
        }
        if (array_key_exists('status', $data)) {
            $merchProduct->status = $data['status'];
        }
        if (array_key_exists('priceAmount', $data)) {
            $merchProduct->price_amount = $data['priceAmount'];
        }
        if (array_key_exists('discountAmount', $data)) {
            $merchProduct->discount_amount = $data['discountAmount'];
        }
        if (array_key_exists('currency', $data)) {
            $merchProduct->currency = strtoupper($data['currency']);
        }
        if (array_key_exists('inventoryCount', $data)) {
            $merchProduct->inventory_count = $data['inventoryCount'];
        }
        if (array_key_exists('images', $data)) {
            $merchProduct->images = $data['images'] ?? [];
        }
        $merchProduct->save();

        AuditLogger::record('admin.merch_product_updated', $merchProduct, $request->user()?->id, [
            'name' => $merchProduct->name,
        ], $request->ip());

        $merchProduct->load(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __('messages.admin.product_updated'),
            'data' => ['product' => new MerchProductResource($merchProduct)],
        ]);
    }

    public function destroy(Request $request, MerchProduct $merchProduct): JsonResponse
    {
        SupportedLocales::apply($request);

        AuditLogger::record('admin.merch_product_deleted', $merchProduct, $request->user()?->id, [
            'name' => $merchProduct->name,
        ], $request->ip());
        $merchProduct->delete();

        return response()->json(['message' => __('messages.admin.product_deleted')]);
    }

    /**
     * Toggle publish state between active and archived (unpublish).
     */
    public function publish(Request $request, MerchProduct $merchProduct): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'action' => ['required', Rule::in(['publish', 'unpublish'])],
        ]);

        $merchProduct->status = $data['action'] === 'publish' ? 'active' : 'archived';
        $merchProduct->save();

        AuditLogger::record("admin.merch_product_{$data['action']}ed", $merchProduct, $request->user()?->id, [
            'status' => $merchProduct->status,
        ], $request->ip());

        $merchProduct->load(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __("messages.admin.product_{$data['action']}ed"),
            'data' => ['product' => new MerchProductResource($merchProduct)],
        ]);
    }

    /**
     * Adjust inventory by a signed delta or set an absolute value, guarded by a
     * row lock inside a transaction so concurrent adjustments cannot corrupt the
     * stock count.
     */
    public function adjustInventory(Request $request, MerchProduct $merchProduct): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'delta' => ['required_without:set', 'integer'],
            'set' => ['required_without:delta', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $merchProduct = DB::transaction(function () use ($merchProduct, $data): MerchProduct {
            $locked = MerchProduct::query()->lockForUpdate()->findOrFail($merchProduct->id);

            if (array_key_exists('set', $data)) {
                $locked->inventory_count = (int) $data['set'];
            } else {
                $next = $locked->inventory_count + (int) $data['delta'];
                if ($next < 0) {
                    throw ValidationException::withMessages([
                        'delta' => [__('messages.admin.product_inventory_negative')],
                    ]);
                }
                $locked->inventory_count = $next;
            }

            $locked->save();

            return $locked;
        });

        AuditLogger::record('admin.merch_product_inventory_adjusted', $merchProduct, $request->user()?->id, [
            'inventoryCount' => $merchProduct->inventory_count,
            'delta' => $data['delta'] ?? null,
            'set' => $data['set'] ?? null,
            'reason' => $data['reason'] ?? null,
        ], $request->ip());

        $merchProduct->load(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __('messages.admin.product_inventory_adjusted'),
            'data' => ['product' => new MerchProductResource($merchProduct)],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        SupportedLocales::apply($request);

        $query = $this->applyFilters($this->baseQuery($request), $request)->latest();
        $filename = 'merch-products-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'SKU', 'Creator', 'Status', 'Price', 'Discount', 'Currency', 'Stock', 'CreatedAt']);

            $query->chunk(200, function ($chunk) use ($handle): void {
                foreach ($chunk as $product) {
                    fputcsv($handle, [
                        $product->id,
                        $product->name,
                        $product->sku,
                        $product->creator?->username,
                        $product->status,
                        $product->price_amount,
                        $product->discount_amount,
                        $product->currency,
                        $product->inventory_count,
                        optional($product->created_at)->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function assertDiscountWithinPrice(int $price, int $discount): void
    {
        if ($discount > $price) {
            throw ValidationException::withMessages([
                'discountAmount' => [__('messages.admin.product_discount_exceeds_price')],
            ]);
        }
    }

    private function baseQuery(Request $request): Builder
    {
        return MerchProduct::query()
            ->with(['creator' => fn ($query) => $query->withProfileAggregates($request->user())]);
    }

    /**
     * Apply the shared q / status / creator filters used by both the paginated
     * listing and the CSV export.
     */
    private function applyFilters(Builder $builder, Request $request): Builder
    {
        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $creatorId = $request->integer('creatorId');

        return $builder
            ->when($query !== '', function (Builder $inner) use ($query): void {
                $inner->where(function (Builder $group) use ($query): void {
                    $group->where('name', 'like', '%'.$query.'%')
                        ->orWhere('sku', 'like', '%'.$query.'%')
                        ->orWhere('slug', 'like', '%'.$query.'%')
                        ->orWhereHas('creator', function (Builder $creatorQuery) use ($query): void {
                            $creatorQuery->where('name', 'like', '%'.$query.'%')
                                ->orWhere('username', 'like', '%'.$query.'%');
                        });

                    if (ctype_digit($query)) {
                        $group->orWhere('id', (int) $query);
                    }
                });
            })
            ->when(in_array($status, self::STATUSES, true), fn (Builder $b) => $b->where('status', $status))
            ->when($creatorId > 0, fn (Builder $b) => $b->where('creator_id', $creatorId));
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'totalProducts' => MerchProduct::query()->count(),
            'activeProducts' => MerchProduct::query()->where('status', 'active')->count(),
            'draftProducts' => MerchProduct::query()->where('status', 'draft')->count(),
            'archivedProducts' => MerchProduct::query()->where('status', 'archived')->count(),
            'outOfStock' => MerchProduct::query()->where('inventory_count', '<=', 0)->count(),
            'totalInventory' => (int) MerchProduct::query()->sum('inventory_count'),
            'totalSales' => (int) MerchOrder::query()->whereIn('status', self::SOLD_STATUSES)->sum('quantity'),
            'totalRevenue' => (int) MerchOrder::query()->whereIn('status', self::SOLD_STATUSES)->sum('total_amount'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function productStats(MerchProduct $product): array
    {
        return [
            'ordersCount' => $product->orders()->count(),
            'unitsSold' => (int) $product->orders()->whereIn('status', self::SOLD_STATUSES)->sum('quantity'),
            'revenue' => (int) $product->orders()->whereIn('status', self::SOLD_STATUSES)->sum('total_amount'),
            'fulfilledOrders' => $product->orders()->where('status', 'fulfilled')->count(),
            'cancelledOrders' => $product->orders()->where('status', 'cancelled')->count(),
        ];
    }
}
