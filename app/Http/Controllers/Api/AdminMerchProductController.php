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
