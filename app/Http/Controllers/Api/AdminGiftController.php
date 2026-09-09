<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GiftResource;
use App\Http\Resources\GiftTransactionResource;
use App\Http\Resources\ProfileResource;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\User;
use App\Services\WalletLedgerService;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin gift controller.
 *
 * Operator oversight of the virtual-gift economy: gift-catalog CRUD, activation,
 * pricing, gift-transaction records, refunds, fraud review, and creator gift
 * earnings for the Coins & Gifts console.
 *
 * Routes: under the admin prefix in routes/api.php (/admin/gifts/*).
 * Frontend consumer: Admin/Pages/CoinsAndGifts.jsx.
 * Related: AdminCoinController, Gift, GiftTransaction, WalletLedgerService.
 */
class AdminGiftController extends Controller
{
    private const COMPLETED = 'completed';

    public function gifts(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $gifts = Gift::query()
            ->withCount(['transactions as sent_count' => fn (Builder $q) => $q->where('status', self::COMPLETED)])
            ->withSum(['transactions as coins_collected' => fn (Builder $q) => $q->where('status', self::COMPLETED)], 'coin_amount')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => __('messages.admin.gifts_retrieved'),
            'data' => ['gifts' => GiftResource::collection($gifts)],
            'meta' => ['summary' => [
                'totalGifts' => Gift::query()->count(),
                'activeGifts' => Gift::query()->where('is_active', true)->count(),
                'inactiveGifts' => Gift::query()->where('is_active', false)->count(),
                'totalSent' => (int) GiftTransaction::query()->where('status', self::COMPLETED)->sum('quantity'),
            ]],
        ]);
    }

    public function storeGift(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:255'],
            'imageUrl' => ['nullable', 'string', 'max:2048'],
            'coinCost' => ['required', 'integer', 'min:1'],
            'priceAmount' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'isActive' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
        ]);

        $gift = Gift::query()->create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'icon' => $data['icon'] ?? null,
            'image_url' => $data['imageUrl'] ?? null,
            'coin_cost' => $data['coinCost'],
            'price_amount' => $data['priceAmount'] ?? 0,
            'currency' => strtoupper($data['currency'] ?? 'NGN'),
            'is_active' => $data['isActive'] ?? true,
            'sort_order' => $data['sortOrder'] ?? 0,
        ]);

        AuditLogger::record('admin.gift_created', $gift, $request->user()?->id, ['name' => $gift->name], $request->ip());

        return response()->json([
            'message' => __('messages.admin.gift_created'),
            'data' => ['gift' => new GiftResource($gift)],
        ], 201);
    }

    public function updateGift(Request $request, Gift $gift): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imageUrl' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'coinCost' => ['sometimes', 'integer', 'min:1'],
            'priceAmount' => ['sometimes', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (array_key_exists('name', $data)) {
            $gift->name = $data['name'];
        }
        if (array_key_exists('icon', $data)) {
            $gift->icon = $data['icon'];
        }
        if (array_key_exists('imageUrl', $data)) {
            $gift->image_url = $data['imageUrl'];
        }
        if (array_key_exists('coinCost', $data)) {
            $gift->coin_cost = $data['coinCost'];
        }
        if (array_key_exists('priceAmount', $data)) {
            $gift->price_amount = $data['priceAmount'];
        }
        if (array_key_exists('currency', $data)) {
            $gift->currency = strtoupper($data['currency']);
        }
        if (array_key_exists('isActive', $data)) {
            $gift->is_active = $data['isActive'];
        }
        if (array_key_exists('sortOrder', $data)) {
            $gift->sort_order = $data['sortOrder'];
        }
        $gift->save();

        AuditLogger::record('admin.gift_updated', $gift, $request->user()?->id, ['changed' => array_keys($data)], $request->ip());

        return response()->json([
            'message' => __('messages.admin.gift_updated'),
            'data' => ['gift' => new GiftResource($gift)],
        ]);
    }

    public function destroyGift(Request $request, Gift $gift): JsonResponse
    {
        SupportedLocales::apply($request);

        AuditLogger::record('admin.gift_deleted', $gift, $request->user()?->id, ['name' => $gift->name], $request->ip());
        $gift->delete();

        return response()->json(['message' => __('messages.admin.gift_deleted')]);
    }

    public function toggleGift(Request $request, Gift $gift): JsonResponse
    {
        SupportedLocales::apply($request);

        $gift->is_active = ! $gift->is_active;
        $gift->save();
        $action = $gift->is_active ? 'activated' : 'deactivated';

        AuditLogger::record("admin.gift_{$action}", $gift, $request->user()?->id, [], $request->ip());

        return response()->json([
            'message' => __("messages.admin.gift_{$action}"),
            'data' => ['gift' => new GiftResource($gift)],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $transactions = PaginatedJson::paginate(
            $this->applyTransactionFilters(
                GiftTransaction::query()->with([
                    'sender' => fn ($q) => $q->withProfileAggregates($request->user()),
                    'recipient' => fn ($q) => $q->withProfileAggregates($request->user()),
                ]),
                $request
            )->latest('sent_at'),
            $request,
            15,
            100
        );

        return response()->json([
            'message' => __('messages.admin.gift_transactions_retrieved'),
            'data' => ['transactions' => PaginatedJson::items($request, $transactions, GiftTransactionResource::class)],
            'meta' => ['transactions' => PaginatedJson::meta($transactions), 'summary' => $this->transactionSummary()],
        ]);
    }

    public function refundTransaction(Request $request, GiftTransaction $giftTransaction, WalletLedgerService $ledger): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $actorId = $request->user()?->id;

        $giftTransaction = DB::transaction(function () use ($giftTransaction, $data, $actorId, $ledger): GiftTransaction {
            $locked = GiftTransaction::query()->lockForUpdate()->findOrFail($giftTransaction->id);

            if ($locked->status === 'refunded') {
                return $locked;
            }

            $locked->status = 'refunded';
            $locked->refunded_at = now();
            $locked->refunded_by = $actorId;
            $locked->metadata = array_merge($locked->metadata ?? [], ['refundReason' => $data['reason'] ?? null]);
            $locked->save();

            if ($locked->creator_earnings > 0) {
                $ledger->recordDebit(
                    $locked->recipient_id,
                    'gift_refund',
                    (int) $locked->creator_earnings,
                    $locked->currency,
                    'posted',
                    'Gift refund reversal.',
                    ['giftTransactionId' => $locked->id, 'giftName' => $locked->gift_name],
                );
            }

            return $locked;
        });

        AuditLogger::record('admin.gift_transaction_refunded', $giftTransaction, $actorId, [
            'creatorEarnings' => $giftTransaction->creator_earnings,
            'reason' => $data['reason'] ?? null,
        ], $request->ip());

        return response()->json([
            'message' => __('messages.admin.gift_transaction_refunded'),
            'data' => ['transaction' => new GiftTransactionResource($giftTransaction->load([
                'sender' => fn ($q) => $q->withProfileAggregates($request->user()),
                'recipient' => fn ($q) => $q->withProfileAggregates($request->user()),
            ]))],
        ]);
    }

    public function reviewTransaction(Request $request, GiftTransaction $giftTransaction): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'action' => ['required', 'in:flag,clear'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $flag = $data['action'] === 'flag';
        $giftTransaction->is_flagged = $flag;
        $giftTransaction->flag_reason = $flag ? ($data['reason'] ?? null) : null;
        $giftTransaction->reviewed_by = $request->user()?->id;
        $giftTransaction->reviewed_at = now();
        $giftTransaction->save();

        AuditLogger::record('admin.gift_transaction_reviewed', $giftTransaction, $request->user()?->id, ['action' => $data['action']], $request->ip());

        return response()->json([
            'message' => __('messages.admin.gift_transaction_reviewed'),
            'data' => ['transaction' => new GiftTransactionResource($giftTransaction->load([
                'sender' => fn ($q) => $q->withProfileAggregates($request->user()),
                'recipient' => fn ($q) => $q->withProfileAggregates($request->user()),
            ]))],
        ]);
    }

    public function creatorEarnings(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $sub = GiftTransaction::query()
            ->select('recipient_id')
            ->selectRaw('SUM(creator_earnings) as earnings')
            ->selectRaw('SUM(quantity) as gifts_received')
            ->selectRaw('COUNT(*) as gift_txns')
            ->where('status', self::COMPLETED)
            ->groupBy('recipient_id');

        $creators = PaginatedJson::paginate(
            User::query()
                ->joinSub($sub, 'ge', fn ($join) => $join->on('users.id', '=', 'ge.recipient_id'))
                ->withProfileAggregates($request->user())
                ->addSelect(['ge.earnings', 'ge.gifts_received', 'ge.gift_txns'])
                ->orderByDesc('ge.earnings'),
            $request,
            15,
            100
        );

        $items = $creators->getCollection()->map(fn (User $user) => [
            'user' => new ProfileResource($user),
            'earnings' => (int) $user->earnings,
            'giftsReceived' => (int) $user->gifts_received,
            'transactions' => (int) $user->gift_txns,
        ])->values();

        return response()->json([
            'message' => __('messages.admin.gift_creator_earnings_retrieved'),
            'data' => ['creators' => $items],
            'meta' => [
                'creators' => PaginatedJson::meta($creators),
                'summary' => [
                    'totalEarnings' => (int) GiftTransaction::query()->where('status', self::COMPLETED)->sum('creator_earnings'),
                    'earningCreators' => (int) GiftTransaction::query()->where('status', self::COMPLETED)->distinct()->count('recipient_id'),
                ],
            ],
        ]);
    }

    private function applyTransactionFilters(Builder $builder, Request $request): Builder
    {
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $flagged = $request->query('flagged');
        $giftId = $request->integer('giftId');

        return $builder
            ->when($query !== '', fn (Builder $b) => $b->where(function (Builder $group) use ($query): void {
                $group->where('gift_name', 'like', '%'.$query.'%')
                    ->orWhereHas('sender', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')->orWhere('username', 'like', '%'.$query.'%'))
                    ->orWhereHas('recipient', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')->orWhere('username', 'like', '%'.$query.'%'));
            }))
            ->when(in_array($status, ['completed', 'refunded'], true), fn (Builder $b) => $b->where('status', $status))
            ->when($flagged !== null && $flagged !== '', fn (Builder $b) => $b->where('is_flagged', filter_var($flagged, FILTER_VALIDATE_BOOL)))
            ->when($giftId > 0, fn (Builder $b) => $b->where('gift_id', $giftId));
    }

    /**
     * @return array<string, int>
     */
    private function transactionSummary(): array
    {
        return [
            'totalTransactions' => GiftTransaction::query()->count(),
            'completed' => GiftTransaction::query()->where('status', self::COMPLETED)->count(),
            'refunded' => GiftTransaction::query()->where('status', 'refunded')->count(),
            'flagged' => GiftTransaction::query()->where('is_flagged', true)->count(),
            'coinsCollected' => (int) GiftTransaction::query()->where('status', self::COMPLETED)->sum('coin_amount'),
            'creatorEarnings' => (int) GiftTransaction::query()->where('status', self::COMPLETED)->sum('creator_earnings'),
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'gift';
        $slug = $base;
        $suffix = 1;

        while (Gift::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
