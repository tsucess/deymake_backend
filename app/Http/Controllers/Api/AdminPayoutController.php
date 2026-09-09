<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePayoutRequestStatusRequest;
use App\Http\Resources\PayoutRequestResource;
use App\Models\PayoutRequest;
use App\Services\WalletLedgerService;
use App\Support\AuditLogger;
use App\Support\DeveloperWebhookDispatcher;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use App\Support\UserNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin payout controller.
 *
 * Review, approve, reject, retry, and track creator payout requests.
 */
class AdminPayoutController extends Controller
{
    private const STATUSES = ['requested', 'processing', 'paid', 'rejected', 'failed'];

    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $payouts = PaginatedJson::paginate(
            $this->applyFilters(PayoutRequest::query()->with(['payoutAccount', 'user', 'reviewer']), $request)
                ->latest('requested_at')
                ->latest('id'),
            $request,
            15,
            100,
        );

        return response()->json([
            'message' => __('messages.monetization.admin_payouts_retrieved'),
            'data' => [
                'payouts' => PaginatedJson::items($request, $payouts, PayoutRequestResource::class),
            ],
            'meta' => [
                'payouts' => PaginatedJson::meta($payouts),
                'summary' => $this->summary(),
            ],
        ]);
    }

    public function show(Request $request, PayoutRequest $payoutRequest): JsonResponse
    {
        SupportedLocales::apply($request);

        $payoutRequest->load(['payoutAccount', 'user', 'reviewer']);

        return response()->json([
            'message' => __('messages.monetization.admin_payouts_retrieved'),
            'data' => [
                'payout' => new PayoutRequestResource($payoutRequest),
            ],
        ]);
    }

    public function update(
        UpdatePayoutRequestStatusRequest $request,
        PayoutRequest $payoutRequest,
        WalletLedgerService $walletLedgerService,
    ): JsonResponse {
        SupportedLocales::apply($request);

        $this->assertAllowedTransition($payoutRequest, $request->validated('status'));

        $payoutRequest = DB::transaction(function () use ($payoutRequest, $request, $walletLedgerService): PayoutRequest {
            $locked = PayoutRequest::query()->lockForUpdate()->findOrFail($payoutRequest->id);
            $nextStatus = $request->validated('status');

            $locked->forceFill([
                'status' => $nextStatus,
                'notes' => $request->validated('notes') ?? $locked->notes,
                'rejection_reason' => $request->validated('rejectionReason') ?? ($nextStatus === 'rejected' ? $locked->rejection_reason : null),
                'external_reference' => $request->validated('externalReference') ?? $locked->external_reference,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'processed_at' => in_array($nextStatus, ['paid', 'failed'], true) ? now() : null,
            ])->save();

            $walletLedgerService->syncPayoutTransaction($locked);

            return $locked->fresh()->load(['payoutAccount', 'user', 'reviewer']);
        });

        $this->notifyCreator($payoutRequest, $request->user()->id);

        AuditLogger::record('admin.payout_request_updated', $payoutRequest, $request->user()?->id, [
            'status' => $payoutRequest->status,
            'notes' => $payoutRequest->notes,
            'externalReference' => $payoutRequest->external_reference,
        ], $request->ip());

        DeveloperWebhookDispatcher::dispatch($payoutRequest->user, 'payout.request.updated', [
            'type' => 'payout.request.updated',
            'payoutRequestId' => $payoutRequest->id,
            'amount' => $payoutRequest->amount,
            'currency' => $payoutRequest->currency,
            'status' => $payoutRequest->status,
            'reviewedBy' => $request->user()->id,
        ]);

        return response()->json([
            'message' => __('messages.monetization.admin_payout_updated'),
            'data' => [
                'payout' => new PayoutRequestResource($payoutRequest),
            ],
        ]);
    }

    public function retry(Request $request, PayoutRequest $payoutRequest, WalletLedgerService $walletLedgerService): JsonResponse
    {
        SupportedLocales::apply($request);

        if ($payoutRequest->status !== 'failed') {
            throw ValidationException::withMessages([
                'status' => [__('messages.monetization.admin_payout_updated')],
            ]);
        }

        $payoutRequest = DB::transaction(function () use ($payoutRequest, $request, $walletLedgerService): PayoutRequest {
            $locked = PayoutRequest::query()->lockForUpdate()->findOrFail($payoutRequest->id);
            $locked->status = 'processing';
            $locked->notes = $request->input('notes', $locked->notes);
            $locked->rejection_reason = null;
            $locked->reviewed_by = $request->user()?->id;
            $locked->reviewed_at = now();
            $locked->processed_at = null;
            $locked->save();

            $walletLedgerService->syncPayoutTransaction($locked);

            return $locked->fresh()->load(['payoutAccount', 'user', 'reviewer']);
        });

        AuditLogger::record('admin.payout_request_retried', $payoutRequest, $request->user()?->id, [
            'status' => $payoutRequest->status,
        ], $request->ip());

        $this->notifyCreator($payoutRequest, $request->user()->id);

        return response()->json([
            'message' => __('messages.monetization.admin_payout_updated'),
            'data' => ['payout' => new PayoutRequestResource($payoutRequest)],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        SupportedLocales::apply($request);

        $query = $this->applyFilters(PayoutRequest::query()->with(['payoutAccount', 'user']), $request)->latest('requested_at');

        $filename = 'payout-requests-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Creator', 'Email', 'Amount', 'Currency', 'Status', 'Requested At', 'Reviewed By']);

            $query->chunk(200, function ($chunk) use ($handle): void {
                foreach ($chunk as $payout) {
                    fputcsv($handle, [
                        $payout->id,
                        $payout->user?->name ?? $payout->user?->username,
                        $payout->user?->email,
                        $payout->amount,
                        $payout->currency,
                        $payout->status,
                        $payout->requested_at?->toISOString(),
                        $payout->reviewer?->name ?? $payout->reviewer?->username,
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
                        ->orWhere('id', 'like', '%'.$query.'%')
                        ->orWhere('external_reference', 'like', '%'.$query.'%');
                });
            })
            ->when(in_array($status, self::STATUSES, true), fn (Builder $queryBuilder) => $queryBuilder->where('status', $status))
            ->when($request->filled('from'), fn (Builder $queryBuilder) => $queryBuilder->whereDate('requested_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $queryBuilder) => $queryBuilder->whereDate('requested_at', '<=', $request->date('to')));
    }

    private function assertAllowedTransition(PayoutRequest $payoutRequest, string $nextStatus): void
    {
        $current = $payoutRequest->status;

        $allowed = match ($current) {
            'requested' => ['processing', 'paid', 'rejected', 'failed'],
            'processing' => ['paid', 'rejected', 'failed'],
            'failed' => ['processing', 'paid', 'rejected'],
            'rejected' => ['processing', 'paid'],
            'paid' => [],
            default => [],
        };

        if (! in_array($nextStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => [__('messages.monetization.admin_payout_updated')],
            ]);
        }
    }

    private function summary(): array
    {
        return [
            'totalPayouts' => PayoutRequest::query()->count(),
            'requested' => PayoutRequest::query()->where('status', 'requested')->count(),
            'processing' => PayoutRequest::query()->where('status', 'processing')->count(),
            'paid' => PayoutRequest::query()->where('status', 'paid')->count(),
            'rejected' => PayoutRequest::query()->where('status', 'rejected')->count(),
            'failed' => PayoutRequest::query()->where('status', 'failed')->count(),
            'totalAmount' => (int) PayoutRequest::query()->sum('amount'),
        ];
    }

    private function notifyCreator(PayoutRequest $payoutRequest, int $actorId): void
    {
        match ($payoutRequest->status) {
            'processing' => UserNotifier::sendTranslated(
                $payoutRequest->user_id,
                $actorId,
                'payout_request_processing',
                'messages.notifications.payout_processing_title',
                'messages.notifications.payout_processing_body',
                ['amount' => $payoutRequest->amount, 'currency' => $payoutRequest->currency],
                ['payoutRequestId' => $payoutRequest->id]
            ),
            'paid' => UserNotifier::sendTranslated(
                $payoutRequest->user_id,
                $actorId,
                'payout_request_paid',
                'messages.notifications.payout_paid_title',
                'messages.notifications.payout_paid_body',
                ['amount' => $payoutRequest->amount, 'currency' => $payoutRequest->currency],
                ['payoutRequestId' => $payoutRequest->id]
            ),
            'rejected' => UserNotifier::sendTranslated(
                $payoutRequest->user_id,
                $actorId,
                'payout_request_rejected',
                'messages.notifications.payout_rejected_title',
                'messages.notifications.payout_rejected_body',
                ['amount' => $payoutRequest->amount, 'currency' => $payoutRequest->currency],
                ['payoutRequestId' => $payoutRequest->id]
            ),
            'failed' => UserNotifier::sendTranslated(
                $payoutRequest->user_id,
                $actorId,
                'payout_request_failed',
                'messages.notifications.payout_failed_title',
                'messages.notifications.payout_failed_body',
                ['amount' => $payoutRequest->amount, 'currency' => $payoutRequest->currency],
                ['payoutRequestId' => $payoutRequest->id]
            ),
            default => null,
        };
    }
}