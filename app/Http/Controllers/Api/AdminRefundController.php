<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RefundRequestResource;
use App\Models\MerchOrder;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\PaymentGatewayException;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRefundController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'rejected', 'processing', 'completed'];

    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $refunds = PaginatedJson::paginate(
            $this->applyFilters(
                RefundRequest::query()->with(['order', 'order.buyer', 'order.creator', 'user', 'reviewer', 'processor']),
                $request,
            )->latest('created_at'),
            $request,
            15,
            100,
        );

        return response()->json([
            'message' => __('messages.refunds.admin_retrieved'),
            'data' => ['refunds' => PaginatedJson::items($request, $refunds, RefundRequestResource::class)],
            'meta' => ['refunds' => PaginatedJson::meta($refunds), 'summary' => $this->summary()],
        ]);
    }

    public function show(Request $request, RefundRequest $refundRequest): JsonResponse
    {
        SupportedLocales::apply($request);

        $refundRequest->load(['order', 'order.buyer', 'order.creator', 'user', 'reviewer', 'processor']);

        return response()->json([
            'message' => __('messages.refunds.admin_retrieved'),
            'data' => ['refund' => new RefundRequestResource($refundRequest)],
        ]);
    }

    public function update(Request $request, RefundRequest $refundRequest): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'adminNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (in_array($refundRequest->status, ['completed', 'processing'], true)) {
            throw ValidationException::withMessages([
                'status' => [__('messages.refunds.not_reviewable')],
            ]);
        }

        $refundRequest = DB::transaction(function () use ($refundRequest, $data, $request): RefundRequest {
            $locked = RefundRequest::query()->lockForUpdate()->findOrFail($refundRequest->id);
            $locked->status = $data['status'];
            $locked->admin_notes = $data['adminNotes'] ?? $locked->admin_notes;
            $locked->reviewed_by = $request->user()?->id;
            $locked->reviewed_at = now();
            $locked->save();

            return $locked;
        });

        AuditLogger::record('admin.refund_request_reviewed', $refundRequest, $request->user()?->id, [
            'status' => $refundRequest->status,
            'adminNotes' => $refundRequest->admin_notes,
        ], $request->ip());

        $this->notifyUser($refundRequest, $request->user()?->id);

        return response()->json([
            'message' => __('messages.refunds.admin_updated'),
            'data' => ['refund' => new RefundRequestResource($refundRequest->fresh()->load(['order', 'order.buyer', 'order.creator', 'user', 'reviewer', 'processor']))],
        ]);
    }

    public function process(Request $request, PaymentGatewayContract $gateway, RefundRequest $refundRequest): JsonResponse
    {
        SupportedLocales::apply($request);

        if (! in_array($refundRequest->status, ['approved', 'processing'], true)) {
            throw ValidationException::withMessages([
                'status' => [__('messages.refunds.not_reviewable')],
            ]);
        }

        if (! $gateway->isConfigured()) {
            throw ValidationException::withMessages([
                'gateway' => ['The payment provider is not configured for refunds.'],
            ]);
        }

        $payment = $this->resolveOrderPayment($refundRequest);

        if ($payment === null) {
            throw ValidationException::withMessages([
                'payment' => ['No successful payment could be found for this refund request.'],
            ]);
        }

        try {
            $gateway->refund($payment->provider_reference ?: $payment->reference, $refundRequest->amount);
        } catch (PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'gateway' => ['The provider could not process this refund. '.$exception->getMessage()],
            ]);
        }

        $refundRequest = DB::transaction(function () use ($refundRequest, $payment, $request): RefundRequest {
            $lockedRefund = RefundRequest::query()->lockForUpdate()->findOrFail($refundRequest->id);
            $lockedRefund->status = 'completed';
            $lockedRefund->processed_by = $request->user()?->id;
            $lockedRefund->processed_at = now();
            $lockedRefund->payment_reference = $payment->provider_reference ?: $payment->reference;
            $lockedRefund->provider_response = [
                'provider' => $payment->provider,
                'reference' => $payment->provider_reference ?: $payment->reference,
                'amount' => $lockedRefund->amount,
            ];
            $lockedRefund->save();

            $order = MerchOrder::query()->lockForUpdate()->findOrFail($lockedRefund->order_id);
            if (in_array($order->status, ['paid', 'fulfilled'], true)) {
                $order->status = 'refunded';
                $order->cancelled_at = $order->cancelled_at ?? now();
                $order->save();
            }

            $lockedPayment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $lockedPayment->status = 'refunded';
            $lockedPayment->refunded_at = now();
            $lockedPayment->metadata = array_merge($lockedPayment->metadata ?? [], [
                'refundRequestId' => $lockedRefund->id,
                'refundReason' => $lockedRefund->reason,
                'refundAmount' => $lockedRefund->amount,
            ]);
            $lockedPayment->save();

            return $lockedRefund;
        });

        AuditLogger::record('admin.refund_request_processed', $refundRequest, $request->user()?->id, [
            'amount' => $refundRequest->amount,
            'orderId' => $refundRequest->order_id,
            'paymentReference' => $refundRequest->payment_reference,
        ], $request->ip());

        UserNotifier::sendTranslated(
            $refundRequest->user_id,
            $request->user()?->id ?? $refundRequest->user_id,
            'refund_request_processed',
            'messages.notifications.refund_updated_title',
            'messages.notifications.refund_approved_body',
            [
                'currency' => $refundRequest->currency,
                'amount' => $refundRequest->amount,
            ],
            ['refundRequestId' => $refundRequest->id, 'orderId' => $refundRequest->order_id],
        );

        return response()->json([
            'message' => __('messages.refunds.admin_updated'),
            'data' => ['refund' => new RefundRequestResource($refundRequest->fresh()->load(['order', 'order.buyer', 'order.creator', 'user', 'reviewer', 'processor']))],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        SupportedLocales::apply($request);

        $query = $this->applyFilters(
            RefundRequest::query()->with(['order', 'order.buyer', 'order.creator', 'user']),
            $request,
        )->latest('created_at');

        $filename = 'refund-requests-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Order ID', 'Buyer', 'Creator', 'Amount', 'Currency', 'Status', 'Requested At']);

            $query->chunk(200, function ($chunk) use ($handle): void {
                foreach ($chunk as $refund) {
                    fputcsv($handle, [
                        $refund->id,
                        $refund->order_id,
                        $refund->user?->username,
                        $refund->order?->creator?->username,
                        $refund->amount,
                        $refund->currency,
                        $refund->status,
                        $refund->created_at?->toISOString(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function applyFilters(Builder $builder, Request $request): Builder
    {
        $query = trim($request->string('q')->toString());
        $status = trim($request->string('status')->toString());

        return $builder
            ->when($query !== '', function (Builder $inner) use ($query): void {
                $inner->where(function (Builder $group) use ($query): void {
                    $group->whereHas('user', fn (Builder $userQuery) => $userQuery
                        ->where('name', 'like', '%'.$query.'%')
                        ->orWhere('username', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%'))
                        ->orWhereHas('order.buyer', fn (Builder $buyerQuery) => $buyerQuery
                            ->where('name', 'like', '%'.$query.'%')
                            ->orWhere('username', 'like', '%'.$query.'%'))
                        ->orWhereHas('order.creator', fn (Builder $creatorQuery) => $creatorQuery
                            ->where('name', 'like', '%'.$query.'%')
                            ->orWhere('username', 'like', '%'.$query.'%'))
                        ->orWhere('reason', 'like', '%'.$query.'%')
                        ->orWhere('id', 'like', '%'.$query.'%')
                        ->orWhere('order_id', 'like', '%'.$query.'%');
                });
            })
            ->when(in_array($status, self::STATUSES, true), fn (Builder $queryBuilder) => $queryBuilder->where('status', $status))
            ->when($request->filled('from'), fn (Builder $queryBuilder) => $queryBuilder->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $queryBuilder) => $queryBuilder->whereDate('created_at', '<=', $request->date('to')));
    }

    private function resolveOrderPayment(RefundRequest $refundRequest): ?Payment
    {
        $order = $refundRequest->order()->first();

        if ($order === null) {
            return null;
        }

        return $order->payments()->where('status', 'successful')->latest()->first();
    }

    private function summary(): array
    {
        return [
            'totalRefunds' => RefundRequest::query()->count(),
            'pending' => RefundRequest::query()->where('status', 'pending')->count(),
            'approved' => RefundRequest::query()->where('status', 'approved')->count(),
            'rejected' => RefundRequest::query()->where('status', 'rejected')->count(),
            'processing' => RefundRequest::query()->where('status', 'processing')->count(),
            'completed' => RefundRequest::query()->where('status', 'completed')->count(),
            'totalAmount' => (int) RefundRequest::query()->sum('amount'),
        ];
    }

    private function notifyUser(RefundRequest $refundRequest, ?int $actorId): void
    {
        if ($actorId === null || $refundRequest->user_id === null) {
            return;
        }

        $template = match ($refundRequest->status) {
            'approved' => ['messages.notifications.refund_updated_title', 'messages.notifications.refund_approved_body'],
            'rejected' => ['messages.notifications.refund_updated_title', 'messages.notifications.refund_rejected_body'],
            default => null,
        };

        if ($template === null) {
            return;
        }

        UserNotifier::sendTranslated(
            $refundRequest->user_id,
            $actorId,
            'refund_request_update',
            $template[0],
            $template[1],
            [
                'currency' => $refundRequest->currency,
                'amount' => $refundRequest->amount,
                'order' => $refundRequest->order_id,
            ],
            ['refundRequestId' => $refundRequest->id, 'orderId' => $refundRequest->order_id],
        );
    }
}
