<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchOrderResource;
use App\Models\MerchOrder;
use App\Models\MerchProduct;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin merch order controller.
 *
 * Operator oversight of physical/digital merch orders: listing with search and
 * filters, order detail with payment and shipping status, status transitions,
 * cancellation with inventory restoration, refunds, and CSV export.
 *
 * An order is only ever promoted to `paid` when a provider-confirmed successful
 * payment is linked to it — never by admin action alone.
 *
 * Routes: under the admin prefix in routes/api.php (/admin/orders/*).
 * Frontend consumer: Admin/Pages/Order.jsx.
 * Related: MerchOrder, MerchProduct, Payment models, MerchController (creator side).
 */
class AdminOrderController extends Controller
{
    private const STATUSES = ['pending', 'paid', 'fulfilled', 'cancelled', 'refunded'];

    private const UPDATABLE_STATUSES = ['pending', 'paid', 'fulfilled'];

    private const SOLD_STATUSES = ['paid', 'fulfilled'];

    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $orders = PaginatedJson::paginate(
            $this->applyFilters($this->baseQuery($request), $request)
                ->latest('placed_at')
                ->latest('id'),
            $request,
            15,
            100
        );

        return response()->json([
            'message' => __('messages.admin.orders_retrieved'),
            'data' => ['orders' => PaginatedJson::items($request, $orders, MerchOrderResource::class)],
            'meta' => ['orders' => PaginatedJson::meta($orders), 'summary' => $this->summary()],
        ]);
    }

    public function show(Request $request, MerchOrder $merchOrder): JsonResponse
    {
        SupportedLocales::apply($request);

        return response()->json([
            'message' => __('messages.admin.order_retrieved'),
            'data' => ['order' => new MerchOrderResource($this->loadOrder($request, $merchOrder))],
        ]);
    }

    /**
     * Transition an order between fulfillment states. Promoting an order to `paid`
     * requires a provider-confirmed successful payment; terminal orders (cancelled
     * or refunded) cannot be transitioned here.
     */
    public function updateStatus(Request $request, MerchOrder $merchOrder): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::UPDATABLE_STATUSES)],
        ]);

        $this->assertNotTerminal($merchOrder);

        $target = $data['status'];

        if ($target === 'paid' && $merchOrder->status !== 'paid' && ! $this->hasConfirmedPayment($merchOrder)) {
            throw ValidationException::withMessages([
                'status' => [__('messages.admin.order_payment_unconfirmed')],
            ]);
        }

        $merchOrder->status = $target;

        if ($target === 'fulfilled' && $merchOrder->fulfilled_at === null) {
            $merchOrder->fulfilled_at = now();
        }

        if ($target === 'paid' && $merchOrder->placed_at === null) {
            $merchOrder->placed_at = now();
        }

        $merchOrder->save();

        AuditLogger::record('admin.merch_order_status_updated', $merchOrder, $request->user()?->id, [
            'status' => $merchOrder->status,
        ], $request->ip());

        return $this->respondWithOrder($request, $merchOrder, 'order_status_updated');
    }

    /**
     * Cancel an order, restoring the reserved inventory under a row lock. Orders
     * already cancelled or refunded cannot be cancelled again.
     */
    public function cancel(Request $request, MerchOrder $merchOrder): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (in_array($merchOrder->status, ['cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages([
                'status' => [__('messages.admin.order_not_cancellable')],
            ]);
        }

        $merchOrder = DB::transaction(function () use ($merchOrder): MerchOrder {
            $locked = MerchOrder::query()->lockForUpdate()->findOrFail($merchOrder->id);
            $this->restoreInventory($locked);
            $locked->status = 'cancelled';
            $locked->cancelled_at = now();
            $locked->save();

            return $locked;
        });

        AuditLogger::record('admin.merch_order_cancelled', $merchOrder, $request->user()?->id, [
            'reason' => $data['reason'] ?? null,
        ], $request->ip());

        return $this->respondWithOrder($request, $merchOrder, 'order_cancelled');
    }

    /**
     * Refund an order: refund the linked provider payment when one exists, restore
     * inventory, and mark the order refunded. Only paid or fulfilled orders can be
     * refunded.
     */
    public function refund(Request $request, PaymentGatewayManager $gateways, MerchOrder $merchOrder): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($merchOrder->status === 'refunded') {
            return response()->json([
                'message' => __('messages.admin.order_already_refunded'),
                'data' => ['order' => new MerchOrderResource($this->loadOrder($request, $merchOrder))],
            ]);
        }

        if (! in_array($merchOrder->status, self::SOLD_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => [__('messages.admin.order_not_refundable')],
            ]);
        }

        $payment = $merchOrder->payments()->where('status', 'successful')->latest()->first();

        if ($payment === null) {
            throw ValidationException::withMessages([
                'payment' => [__('messages.admin.order_payment_unconfirmed')],
            ]);
        }

        try {
            $gateways->for($payment)->refund($payment->provider_reference ?: $payment->reference, $data['amount'] ?? null);
        } catch (PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'gateway' => [__('messages.admin.order_refund_failed').' '.$exception->getMessage()],
            ]);
        }

        $merchOrder = DB::transaction(function () use ($merchOrder, $payment, $data): MerchOrder {
            $locked = MerchOrder::query()->lockForUpdate()->findOrFail($merchOrder->id);
            $this->restoreInventory($locked);
            $locked->status = 'refunded';
            $locked->cancelled_at = $locked->cancelled_at ?? now();
            $locked->save();

            if ($payment !== null) {
                $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                $lockedPayment->status = 'refunded';
                $lockedPayment->refunded_at = now();
                $lockedPayment->metadata = array_merge($lockedPayment->metadata ?? [], [
                    'refundReason' => $data['reason'] ?? null,
                    'refundAmount' => $data['amount'] ?? $lockedPayment->amount,
                    'refundedForOrderId' => $locked->id,
                ]);
                $lockedPayment->save();
            }

            return $locked;
        });

        AuditLogger::record('admin.merch_order_refunded', $merchOrder, $request->user()?->id, [
            'reason' => $data['reason'] ?? null,
            'amount' => $data['amount'] ?? $merchOrder->total_amount,
            'paymentRefunded' => $payment !== null,
        ], $request->ip());

        return $this->respondWithOrder($request, $merchOrder, 'order_refunded');
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        SupportedLocales::apply($request);

        $query = $this->applyFilters(
            MerchOrder::query()->with(['product', 'creator', 'buyer', 'payments' => fn ($q) => $q->latest()]),
            $request
        )->latest('placed_at')->latest('id');

        $filename = 'merch-orders-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Product', 'Creator', 'Buyer', 'Quantity', 'UnitPrice', 'Total', 'Currency', 'Status', 'PaymentStatus', 'PlacedAt', 'FulfilledAt', 'CancelledAt']);

            $query->chunk(200, function ($chunk) use ($handle): void {
                foreach ($chunk as $order) {
                    $payment = $order->payments->first();
                    fputcsv($handle, [
                        $order->id,
                        $order->product?->name,
                        $order->creator?->username,
                        $order->buyer?->username,
                        $order->quantity,
                        $order->unit_price_amount,
                        $order->total_amount,
                        $order->currency,
                        $order->status,
                        $payment?->status ?? $order->status,
                        optional($order->placed_at)->toIso8601String(),
                        optional($order->fulfilled_at)->toIso8601String(),
                        optional($order->cancelled_at)->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function baseQuery(Request $request): Builder
    {
        return MerchOrder::query()->with([
            'product',
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'buyer' => fn ($query) => $query->withProfileAggregates($request->user()),
            'payments' => fn ($query) => $query->latest(),
        ]);
    }

    /**
     * Apply the shared q / status / creator / buyer / date filters used by both the
     * paginated listing and the CSV export.
     */
    private function applyFilters(Builder $builder, Request $request): Builder
    {
        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());
        $creatorId = $request->integer('creatorId');
        $buyerId = $request->integer('buyerId');

        return $builder
            ->when($query !== '', function (Builder $inner) use ($query): void {
                $inner->where(function (Builder $group) use ($query): void {
                    $group->whereHas('buyer', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')
                        ->orWhere('username', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%'))
                        ->orWhereHas('creator', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')
                            ->orWhere('username', 'like', '%'.$query.'%'));

                    $orderId = preg_replace('/^ord[-_]?/i', '', $query);

                    if (is_string($orderId) && ctype_digit($orderId)) {
                        $group->orWhere('id', (int) $orderId);
                    }
                });
            })
            ->when(in_array($status, self::STATUSES, true), fn (Builder $b) => $b->where('status', $status))
            ->when($creatorId > 0, fn (Builder $b) => $b->where('creator_id', $creatorId))
            ->when($buyerId > 0, fn (Builder $b) => $b->where('buyer_id', $buyerId))
            ->when($request->query('from') || $request->query('to'), function (Builder $b) use ($request): void {
                [$from, $to] = $this->resolveRange($request);
                $b->whereBetween('placed_at', [$from, $to]);
            });
    }

    private function hasConfirmedPayment(MerchOrder $order): bool
    {
        return $order->payments()->where('status', 'successful')->exists();
    }

    private function assertNotTerminal(MerchOrder $order): void
    {
        if (in_array($order->status, ['cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages([
                'status' => [__('messages.admin.order_status_locked')],
            ]);
        }
    }

    private function restoreInventory(MerchOrder $order): void
    {
        $product = MerchProduct::query()->lockForUpdate()->find($order->merch_product_id);

        if ($product !== null) {
            $product->inventory_count += (int) $order->quantity;
            $product->save();
        }
    }

    private function loadOrder(Request $request, MerchOrder $order): MerchOrder
    {
        return $order->load([
            'product' => fn ($query) => $query->with([
                'creator' => fn ($creator) => $creator->withProfileAggregates($request->user()),
            ]),
            'creator' => fn ($query) => $query->withProfileAggregates($request->user()),
            'buyer' => fn ($query) => $query->withProfileAggregates($request->user()),
            'payments' => fn ($query) => $query->latest(),
        ]);
    }

    private function respondWithOrder(Request $request, MerchOrder $order, string $messageKey): JsonResponse
    {
        return response()->json([
            'message' => __('messages.admin.'.$messageKey),
            'data' => ['order' => new MerchOrderResource($this->loadOrder($request, $order))],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'totalOrders' => MerchOrder::query()->count(),
            'pendingOrders' => MerchOrder::query()->where('status', 'pending')->count(),
            'paidOrders' => MerchOrder::query()->where('status', 'paid')->count(),
            'fulfilledOrders' => MerchOrder::query()->where('status', 'fulfilled')->count(),
            'cancelledOrders' => MerchOrder::query()->where('status', 'cancelled')->count(),
            'refundedOrders' => MerchOrder::query()->where('status', 'refunded')->count(),
            'unitsSold' => (int) MerchOrder::query()->whereIn('status', self::SOLD_STATUSES)->sum('quantity'),
            'totalRevenue' => (int) MerchOrder::query()->whereIn('status', self::SOLD_STATUSES)->sum('total_amount'),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $to = $this->parseDate($request->query('to')) ?? Carbon::now();
        $from = $this->parseDate($request->query('from')) ?? $to->copy()->subDays(29);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
