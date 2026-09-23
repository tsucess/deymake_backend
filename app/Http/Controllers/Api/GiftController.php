<?php

namespace App\Http\Controllers\Api;

use App\Events\LiveEngagementCreated;
use App\Http\Controllers\Controller;
use App\Http\Resources\GiftResource;
use App\Http\Resources\GiftTransactionResource;
use App\Models\Gift;
use App\Models\GiftTransaction;
use App\Models\User;
use App\Models\Video;
use App\Services\WalletLedgerService;
use App\Support\UserNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftController extends Controller
{
    public function catalog(): JsonResponse
    {
        $gifts = Gift::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => ['gifts' => GiftResource::collection($gifts)]]);
    }

    public function send(Request $request, Gift $gift, WalletLedgerService $ledger): JsonResponse
    {
        // Older live-room clients send the public video ID from the URL. Keep
        // the validated payload integer-based while accepting that identifier.
        if ($request->filled('videoId') && ! is_numeric($request->input('videoId'))) {
            $numericVideoId = Video::query()
                ->where('public_id', $request->input('videoId'))
                ->value('id');

            if ($numericVideoId !== null) {
                $request->merge(['videoId' => $numericVideoId]);
            }
        }

        $data = $request->validate([
            'recipientId' => ['required', 'integer', 'exists:users,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'videoId' => ['nullable', 'integer', 'exists:videos,id'],
            'message' => ['nullable', 'string', 'max:280'],
            'isPrivate' => ['sometimes', 'boolean'],
        ]);
        abort_if(! $gift->is_active, 422, 'This gift is no longer available.');
        abort_if((int) $data['recipientId'] === (int) $request->user()->id, 422, 'You cannot gift yourself.');

        $recipient = User::query()->findOrFail($data['recipientId']);
        $video = isset($data['videoId']) ? Video::query()->findOrFail($data['videoId']) : null;
        if ($video) {
            abort_if(! $video->is_live, 409, 'This live session is no longer active.');
            abort_if((int) $video->user_id !== (int) $recipient->id, 422, 'The recipient is not hosting this live session.');
            abort_if(! $video->allow_gifts, 422, 'Gifts are disabled for this live session.');
        }

        $quantity = (int) ($data['quantity'] ?? 1);
        $coinAmount = (int) $gift->coin_cost * $quantity;
        $transaction = DB::transaction(function () use ($request, $recipient, $gift, $video, $quantity, $coinAmount, $data, $ledger): GiftTransaction {
            $purchasedCoins = (int) DB::table('coin_purchases')->where('user_id', $request->user()->id)->where('status', 'completed')->lockForUpdate()->sum('coins');
            $sentCoins = (int) GiftTransaction::query()->where('sender_id', $request->user()->id)->where('status', 'completed')->lockForUpdate()->sum('coin_amount');
            abort_if($purchasedCoins - $sentCoins < $coinAmount, 422, 'Insufficient coin balance.');

            $created = GiftTransaction::query()->create([
                'gift_id' => $gift->id,
                'gift_name' => $gift->name,
                'sender_id' => $request->user()->id,
                'recipient_id' => $recipient->id,
                'video_id' => $video?->id,
                'quantity' => $quantity,
                'coin_amount' => $coinAmount,
                'creator_earnings' => (int) $gift->price_amount * $quantity,
                'currency' => strtoupper($gift->currency),
                'status' => 'completed',
                'metadata' => array_filter(['message' => $data['message'] ?? null, 'isPrivate' => $data['isPrivate'] ?? false]),
                'sent_at' => now(),
            ]);

            $ledger->recordCredit($recipient, 'gift_credit', $created->creator_earnings, $created->currency, 'Gift received.', ['giftTransactionId' => $created->id, 'senderId' => $request->user()->id, 'videoId' => $video?->id], $created->sent_at);

            return $created;
        });

        $transaction->load(['sender', 'recipient', 'gift', 'video']);
        UserNotifier::send(
            $transaction->recipient_id,
            $transaction->sender_id,
            'gift',
            'You received a gift',
            $transaction->sender?->name.' sent '.$transaction->gift_name.' x'.$transaction->quantity.'.',
            ['actorId' => $transaction->sender_id, 'giftTransactionId' => $transaction->id, 'videoId' => $transaction->video_id],
        );
        if ($video) {
            LiveEngagementCreated::dispatch($video->id, [
                'id' => 'gift-'.$transaction->id,
                'type' => 'gift',
                'body' => data_get($transaction->metadata, 'message'),
                'createdAt' => $transaction->sent_at?->toISOString(),
                'actor' => data_get($transaction->metadata, 'isPrivate') ? ['id' => null, 'fullName' => 'Anonymous fan'] : ['id' => $transaction->sender_id],
                'metadata' => ['giftName' => $transaction->gift_name, 'giftCount' => $transaction->quantity, 'amount' => $transaction->coin_amount, 'currency' => 'COIN'],
            ]);
        }

        return response()->json(['message' => 'Gift sent successfully.', 'data' => ['transaction' => new GiftTransactionResource($transaction)]], 201);
    }

    public function sent(Request $request): JsonResponse
    {
        return $this->history($request, 'sender_id');
    }

    public function received(Request $request): JsonResponse
    {
        return $this->history($request, 'recipient_id');
    }

    private function history(Request $request, string $column): JsonResponse
    {
        $transactions = GiftTransaction::query()->where($column, $request->user()->id)->with(['sender', 'recipient', 'gift', 'video'])->latest('sent_at')->paginate(20);

        return response()->json(['data' => ['transactions' => GiftTransactionResource::collection($transactions->items())], 'meta' => ['currentPage' => $transactions->currentPage(), 'lastPage' => $transactions->lastPage(), 'total' => $transactions->total()]]);
    }
}