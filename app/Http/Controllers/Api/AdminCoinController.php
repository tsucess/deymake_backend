<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoinPackageResource;
use App\Http\Resources\CoinPurchaseResource;
use App\Http\Resources\ProfileResource;
use App\Models\CoinPackage;
use App\Models\CoinPurchase;
use App\Models\GiftTransaction;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin coin controller.
 *
 * Operator oversight of the virtual-currency economy: coin-purchase revenue,
 * coin package configuration, purchase records, refunds, and fraud review, plus
 * the aggregate overview + time series that power the Coins & Gifts console.
 *
 * Routes: under the admin prefix in routes/api.php (/admin/coins/*).
 * Frontend consumer: Admin/Pages/CoinsAndGifts.jsx.
 * Related: AdminGiftController, CoinPackage, CoinPurchase, GiftTransaction.
 */
class AdminCoinController extends Controller
{
    private const COMPLETED = 'completed';

    private const PURCHASE_STATUSES = ['pending', 'completed', 'refunded', 'failed'];

    public function overview(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        [$from, $to] = $this->resolveRange($request);
        $length = max(1, $from->diffInDays($to) + 1);
        $prevTo = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($length - 1)->startOfDay();

        $current = $this->windowMetrics($from, $to);
        $previous = $this->windowMetrics($prevFrom, $prevTo);

        return response()->json([
            'message' => __('messages.admin.coins_overview_retrieved'),
            'data' => [
                'summary' => $current,
                'deltas' => [
                    'coinsPurchased' => $this->delta($current['coinsPurchased'], $previous['coinsPurchased']),
                    'coinsConsumed' => $this->delta($current['coinsConsumed'], $previous['coinsConsumed']),
                    'giftsSent' => $this->delta($current['giftsSent'], $previous['giftsSent']),
                    'revenue' => $this->delta($current['revenue'], $previous['revenue']),
                    'avgRevenuePerUser' => $this->delta($current['avgRevenuePerUser'], $previous['avgRevenuePerUser']),
                ],
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ],
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function windowMetrics(Carbon $from, Carbon $to): array
    {
        $completedPurchases = CoinPurchase::query()
            ->where('status', self::COMPLETED)
            ->whereBetween('purchased_at', [$from, $to]);

        $coinsPurchased = (int) (clone $completedPurchases)->sum('coins');
        $revenue = (int) (clone $completedPurchases)->sum('amount');
        $buyers = (int) (clone $completedPurchases)->distinct()->count('user_id');

        $completedGifts = GiftTransaction::query()
            ->where('status', self::COMPLETED)
            ->whereBetween('sent_at', [$from, $to]);

        $coinsConsumed = (int) (clone $completedGifts)->sum('coin_amount');
        $giftsSent = (int) (clone $completedGifts)->sum('quantity');

        $refunded = (int) CoinPurchase::query()
            ->where('status', 'refunded')
            ->whereBetween('refunded_at', [$from, $to])
            ->sum('amount');

        return [
            'coinsPurchased' => $coinsPurchased,
            'coinsConsumed' => $coinsConsumed,
            'giftsSent' => $giftsSent,
            'revenue' => $revenue,
            'avgRevenuePerUser' => $buyers > 0 ? round($revenue / $buyers, 2) : 0,
            'activeBuyers' => $buyers,
            'refundedAmount' => $refunded,
            'flaggedPurchases' => CoinPurchase::query()->where('is_flagged', true)->count(),
            'flaggedGifts' => GiftTransaction::query()->where('is_flagged', true)->count(),
        ];
    }

    private function delta(int|float $current, int|float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
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

    public function timeseries(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        [$from, $to] = $this->resolveRange($request);
        $group = $this->resolveGroup($request);
        $buckets = $this->buildBuckets($from, $to, $group);

        $purchased = array_fill_keys(array_keys($buckets), 0);
        $consumed = array_fill_keys(array_keys($buckets), 0);
        $revenue = array_fill_keys(array_keys($buckets), 0);

        CoinPurchase::query()
            ->where('status', self::COMPLETED)
            ->whereBetween('purchased_at', [$from, $to])
            ->get(['coins', 'amount', 'purchased_at'])
            ->each(function (CoinPurchase $purchase) use (&$purchased, &$revenue, $group): void {
                $key = $this->bucketKey($purchase->purchased_at, $group);
                if (array_key_exists($key, $purchased)) {
                    $purchased[$key] += (int) $purchase->coins;
                    $revenue[$key] += (int) $purchase->amount;
                }
            });

        GiftTransaction::query()
            ->where('status', self::COMPLETED)
            ->whereBetween('sent_at', [$from, $to])
            ->get(['coin_amount', 'sent_at'])
            ->each(function (GiftTransaction $gift) use (&$consumed, $group): void {
                $key = $this->bucketKey($gift->sent_at, $group);
                if (array_key_exists($key, $consumed)) {
                    $consumed[$key] += (int) $gift->coin_amount;
                }
            });

        return response()->json([
            'message' => __('messages.admin.coins_timeseries_retrieved'),
            'data' => [
                'group' => $group,
                'labels' => array_values($buckets),
                'series' => [
                    ['key' => 'coinsPurchased', 'data' => array_values($purchased)],
                    ['key' => 'coinsConsumed', 'data' => array_values($consumed)],
                    ['key' => 'revenue', 'data' => array_values($revenue)],
                ],
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            ],
        ]);
    }

    public function topSenders(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        [$from, $to] = $this->resolveRange($request);
        $limit = min(max((int) $request->integer('limit', 5), 1), 50);

        $rows = GiftTransaction::query()
            ->select('sender_id', DB::raw('SUM(coin_amount) as coins_sent'), DB::raw('SUM(quantity) as gifts_sent'))
            ->where('status', self::COMPLETED)
            ->whereBetween('sent_at', [$from, $to])
            ->groupBy('sender_id')
            ->orderByDesc('coins_sent')
            ->limit($limit)
            ->get();

        $users = User::query()
            ->whereIn('id', $rows->pluck('sender_id'))
            ->withProfileAggregates($request->user())
            ->get()
            ->keyBy('id');

        $senders = $rows->map(fn ($row) => [
            'user' => ($user = $users->get($row->sender_id)) ? new ProfileResource($user) : null,
            'coinsSent' => (int) $row->coins_sent,
            'giftsSent' => (int) $row->gifts_sent,
        ])->values();

        return response()->json([
            'message' => __('messages.admin.coin_top_senders_retrieved'),
            'data' => ['senders' => $senders],
        ]);
    }

    public function packages(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $packages = CoinPackage::query()
            ->withCount(['purchases as sales_count' => fn (Builder $q) => $q->where('status', self::COMPLETED)])
            ->withSum(['purchases as revenue_amount' => fn (Builder $q) => $q->where('status', self::COMPLETED)], 'amount')
            ->withSum(['purchases as coins_sold' => fn (Builder $q) => $q->where('status', self::COMPLETED)], 'coins')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => __('messages.admin.coin_packages_retrieved'),
            'data' => ['packages' => CoinPackageResource::collection($packages)],
        ]);
    }

    public function storePackage(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'coins' => ['required', 'integer', 'min:1'],
            'bonusCoins' => ['nullable', 'integer', 'min:0'],
            'priceAmount' => ['required', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isActive' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
        ]);

        $package = CoinPackage::query()->create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug(CoinPackage::class, $data['name'], 'package'),
            'coins' => $data['coins'],
            'bonus_coins' => $data['bonusCoins'] ?? 0,
            'price_amount' => $data['priceAmount'],
            'currency' => strtoupper($data['currency'] ?? 'NGN'),
            'is_active' => $data['isActive'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
        ]);

        AuditLogger::record('admin.coin_package_created', $package, $request->user()?->id, ['name' => $package->name], $request->ip());

        return response()->json([
            'message' => __('messages.admin.coin_package_created'),
            'data' => ['package' => new CoinPackageResource($package)],
        ], 201);
    }

    public function updatePackage(Request $request, CoinPackage $coinPackage): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'coins' => ['sometimes', 'integer', 'min:1'],
            'bonusCoins' => ['sometimes', 'integer', 'min:0'],
            'priceAmount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ]);

        $coinPackage->fill(array_filter([
            'name' => $data['name'] ?? null,
            'coins' => $data['coins'] ?? null,
            'bonus_coins' => $data['bonusCoins'] ?? null,
            'price_amount' => $data['priceAmount'] ?? null,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : null,
            'sort_order' => $data['sortOrder'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('isActive', $data)) {
            $coinPackage->is_active = $data['isActive'];
        }

        $coinPackage->save();

        AuditLogger::record('admin.coin_package_updated', $coinPackage, $request->user()?->id, ['changed' => array_keys($data)], $request->ip());

        return response()->json([
            'message' => __('messages.admin.coin_package_updated'),
            'data' => ['package' => new CoinPackageResource($coinPackage)],
        ]);
    }

    public function destroyPackage(Request $request, CoinPackage $coinPackage): JsonResponse
    {
        SupportedLocales::apply($request);

        AuditLogger::record('admin.coin_package_deleted', $coinPackage, $request->user()?->id, ['name' => $coinPackage->name], $request->ip());
        $coinPackage->delete();

        return response()->json(['message' => __('messages.admin.coin_package_deleted')]);
    }

    public function togglePackage(Request $request, CoinPackage $coinPackage): JsonResponse
    {
        SupportedLocales::apply($request);

        $coinPackage->is_active = ! $coinPackage->is_active;
        $coinPackage->save();
        $action = $coinPackage->is_active ? 'activated' : 'deactivated';

        AuditLogger::record("admin.coin_package_{$action}", $coinPackage, $request->user()?->id, [], $request->ip());

        return response()->json([
            'message' => __("messages.admin.coin_package_{$action}"),
            'data' => ['package' => new CoinPackageResource($coinPackage)],
        ]);
    }

    public function purchases(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $purchases = PaginatedJson::paginate(
            $this->applyPurchaseFilters(
                CoinPurchase::query()->with(['user' => fn ($q) => $q->withProfileAggregates($request->user()), 'package']),
                $request
            )->latest('purchased_at'),
            $request,
            15,
            100
        );

        return response()->json([
            'message' => __('messages.admin.coin_purchases_retrieved'),
            'data' => ['purchases' => PaginatedJson::items($request, $purchases, CoinPurchaseResource::class)],
            'meta' => ['purchases' => PaginatedJson::meta($purchases), 'summary' => $this->purchaseSummary()],
        ]);
    }

    public function refundPurchase(Request $request, CoinPurchase $coinPurchase): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $coinPurchase = DB::transaction(function () use ($coinPurchase, $data): CoinPurchase {
            $locked = CoinPurchase::query()->lockForUpdate()->findOrFail($coinPurchase->id);

            if ($locked->status === 'refunded') {
                return $locked;
            }

            $locked->status = 'refunded';
            $locked->refunded_at = now();
            $locked->metadata = array_merge($locked->metadata ?? [], ['refundReason' => $data['reason'] ?? null]);
            $locked->save();

            return $locked;
        });

        AuditLogger::record('admin.coin_purchase_refunded', $coinPurchase, $request->user()?->id, [
            'amount' => $coinPurchase->amount,
            'reason' => $data['reason'] ?? null,
        ], $request->ip());

        return response()->json([
            'message' => __('messages.admin.coin_purchase_refunded'),
            'data' => ['purchase' => new CoinPurchaseResource(
                $coinPurchase->load(['user' => fn ($q) => $q->withProfileAggregates($request->user()), 'package'])
            )],
        ]);
    }

    public function reviewPurchase(Request $request, CoinPurchase $coinPurchase): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'action' => ['required', 'in:flag,clear'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $flag = $data['action'] === 'flag';
        $coinPurchase->is_flagged = $flag;
        $coinPurchase->flag_reason = $flag ? ($data['reason'] ?? null) : null;
        $coinPurchase->reviewed_by = $request->user()?->id;
        $coinPurchase->reviewed_at = now();
        $coinPurchase->save();

        AuditLogger::record('admin.coin_purchase_reviewed', $coinPurchase, $request->user()?->id, ['action' => $data['action']], $request->ip());

        return response()->json([
            'message' => __('messages.admin.coin_purchase_reviewed'),
            'data' => ['purchase' => new CoinPurchaseResource(
                $coinPurchase->load(['user' => fn ($q) => $q->withProfileAggregates($request->user()), 'package'])
            )],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $type = mb_strtolower(trim((string) $request->query('type', 'all')));
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $limit = min(max((int) $request->integer('limit', 20), 1), 100);

        $rows = collect();

        if ($type !== 'gift') {
            CoinPurchase::query()
                ->with(['user' => fn ($b) => $b->withProfileAggregates($request->user())])
                ->when($status !== '', fn (Builder $b) => $b->where('status', $status))
                ->when($query !== '', fn (Builder $b) => $b->whereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')->orWhere('username', 'like', '%'.$query.'%')))
                ->latest('purchased_at')
                ->limit($limit)
                ->get()
                ->each(fn (CoinPurchase $purchase) => $rows->push($this->normalizePurchaseRow($purchase)));
        }

        if ($type !== 'purchase') {
            GiftTransaction::query()
                ->with(['sender' => fn ($b) => $b->withProfileAggregates($request->user())])
                ->when($status !== '', fn (Builder $b) => $b->where('status', $status))
                ->when($query !== '', fn (Builder $b) => $b->whereHas('sender', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')->orWhere('username', 'like', '%'.$query.'%')))
                ->latest('sent_at')
                ->limit($limit)
                ->get()
                ->each(fn (GiftTransaction $gift) => $rows->push($this->normalizeGiftRow($gift)));
        }

        $transactions = $rows->sortByDesc('occurredAt')->take($limit)->values();

        return response()->json([
            'message' => __('messages.admin.coin_transactions_retrieved'),
            'data' => ['transactions' => $transactions],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        SupportedLocales::apply($request);

        $filename = 'coin-transactions-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Type', 'ID', 'User', 'Description', 'Coins', 'Amount', 'Currency', 'Status', 'OccurredAt']);

            CoinPurchase::query()->with('user')->latest('purchased_at')->chunk(200, function ($chunk) use ($handle): void {
                foreach ($chunk as $purchase) {
                    fputcsv($handle, [
                        'purchase', $purchase->id, $purchase->user?->username,
                        'Purchased '.$purchase->coins.' coins', $purchase->coins, $purchase->amount,
                        $purchase->currency, $purchase->status, optional($purchase->purchased_at)->toIso8601String(),
                    ]);
                }
            });

            GiftTransaction::query()->with('sender')->latest('sent_at')->chunk(200, function ($chunk) use ($handle): void {
                foreach ($chunk as $gift) {
                    fputcsv($handle, [
                        'gift', $gift->id, $gift->sender?->username,
                        'Sent '.$gift->gift_name, -1 * (int) $gift->coin_amount, $gift->creator_earnings,
                        $gift->currency, $gift->status, optional($gift->sent_at)->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function applyPurchaseFilters(Builder $builder, Request $request): Builder
    {
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $flagged = $request->query('flagged');
        [$from, $to] = $this->resolveRange($request);

        return $builder
            ->when($query !== '', fn (Builder $b) => $b->where(function (Builder $group) use ($query): void {
                $group->where('payment_reference', 'like', '%'.$query.'%')
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')
                        ->orWhere('username', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%'));
            }))
            ->when(in_array($status, self::PURCHASE_STATUSES, true), fn (Builder $b) => $b->where('status', $status))
            ->when($flagged !== null && $flagged !== '', fn (Builder $b) => $b->where('is_flagged', filter_var($flagged, FILTER_VALIDATE_BOOL)))
            ->when($request->query('from') || $request->query('to'), fn (Builder $b) => $b->whereBetween('purchased_at', [$from, $to]));
    }

    /**
     * @return array<string, int>
     */
    private function purchaseSummary(): array
    {
        return [
            'totalPurchases' => CoinPurchase::query()->count(),
            'completed' => CoinPurchase::query()->where('status', self::COMPLETED)->count(),
            'refunded' => CoinPurchase::query()->where('status', 'refunded')->count(),
            'flagged' => CoinPurchase::query()->where('is_flagged', true)->count(),
            'revenue' => (int) CoinPurchase::query()->where('status', self::COMPLETED)->sum('amount'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePurchaseRow(CoinPurchase $purchase): array
    {
        return [
            'id' => 'purchase-'.$purchase->id,
            'kind' => 'purchase',
            'userId' => $purchase->user_id,
            'user' => $purchase->relationLoaded('user') && $purchase->user ? new ProfileResource($purchase->user) : null,
            'description' => 'Purchased '.$purchase->coins.' coins',
            'coins' => (int) $purchase->coins,
            'direction' => 'credit',
            'amount' => (int) $purchase->amount,
            'currency' => $purchase->currency,
            'status' => $purchase->status,
            'occurredAt' => ($purchase->purchased_at ?? $purchase->created_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeGiftRow(GiftTransaction $gift): array
    {
        return [
            'id' => 'gift-'.$gift->id,
            'kind' => 'gift',
            'userId' => $gift->sender_id,
            'user' => $gift->relationLoaded('sender') && $gift->sender ? new ProfileResource($gift->sender) : null,
            'description' => 'Sent '.$gift->gift_name,
            'coins' => -1 * (int) $gift->coin_amount,
            'direction' => 'debit',
            'amount' => (int) $gift->creator_earnings,
            'currency' => $gift->currency,
            'status' => $gift->status,
            'occurredAt' => ($gift->sent_at ?? $gift->created_at)?->toISOString(),
        ];
    }

    private function resolveGroup(Request $request): string
    {
        $group = mb_strtolower(trim((string) $request->query('group', 'day')));

        return in_array($group, ['day', 'week', 'month'], true) ? $group : 'day';
    }

    /**
     * @return array<string, string>
     */
    private function buildBuckets(Carbon $from, Carbon $to, string $group): array
    {
        $buckets = [];
        $cursor = match ($group) {
            'week' => $from->copy()->startOfWeek(),
            'month' => $from->copy()->startOfMonth(),
            default => $from->copy()->startOfDay(),
        };
        $end = $to->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            $buckets[$this->bucketKey($cursor, $group)] = $this->bucketLabel($cursor, $group);
            $cursor = match ($group) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    private function bucketKey(Carbon $date, string $group): string
    {
        return match ($group) {
            'week' => $date->copy()->startOfWeek()->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function bucketLabel(Carbon $date, string $group): string
    {
        return match ($group) {
            'week' => $date->copy()->startOfWeek()->format('M j'),
            'month' => $date->format('M Y'),
            default => $date->format('M j'),
        };
    }

    private function uniqueSlug(string $modelClass, string $name, string $fallback): string
    {
        $base = Str::slug($name) ?: $fallback;
        $slug = $base;
        $suffix = 1;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
