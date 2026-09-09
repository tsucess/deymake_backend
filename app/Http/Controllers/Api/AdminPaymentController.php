<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\Payments\PaymentGatewayContract;
use App\Services\Payments\PaymentGatewayException;
use App\Support\AuditLogger;
use App\Support\PaginatedJson;
use App\Support\SupportedLocales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin payment controller.
 *
 * Operator oversight of provider-processed payments: listing with search and
 * filters, transaction detail, provider-authoritative verification and
 * reconciliation, refunds, fraud review, and CSV export.
 *
 * A payment is only ever promoted to `successful` on provider confirmation
 * (verified webhook or a verify call) — never by admin action alone.
 *
 * Routes: under the admin prefix in routes/api.php (/admin/payments/*).
 * Frontend consumer: Admin/Pages/Payment.jsx.
 * Related: PaymentController (checkout), PaymentWebhookController (webhooks).
 */
class AdminPaymentController extends Controller
{
    private const STATUSES = ['pending', 'processing', 'successful', 'failed', 'abandoned', 'refunded'];

    public function index(Request $request): JsonResponse
    {
        SupportedLocales::apply($request);

        $payments = PaginatedJson::paginate(
            $this->applyFilters(
                Payment::query()->with(['user' => fn ($q) => $q->withProfileAggregates($request->user())]),
                $request
            )->latest('created_at'),
            $request,
            15,
            100
        );

        return response()->json([
            'message' => __('messages.admin.payments_retrieved'),
            'data' => ['payments' => PaginatedJson::items($request, $payments, PaymentResource::class)],
            'meta' => ['payments' => PaginatedJson::meta($payments), 'summary' => $this->summary()],
        ]);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        SupportedLocales::apply($request);

        $payment->load(['user' => fn ($q) => $q->withProfileAggregates($request->user())]);

        return response()->json([
            'message' => __('messages.admin.payment_retrieved'),
            'data' => [
                'payment' => new PaymentResource($payment),
                'webhookEvents' => $payment->webhookEvents()
                    ->latest('created_at')
                    ->limit(20)
                    ->get(['id', 'event_type', 'reference', 'signature_valid', 'processed_at', 'created_at'])
                    ->map(fn ($event) => [
                        'id' => $event->id,
                        'eventType' => $event->event_type,
                        'reference' => $event->reference,
                        'signatureValid' => (bool) $event->signature_valid,
                        'processedAt' => $event->processed_at?->toISOString(),
                        'createdAt' => $event->created_at?->toISOString(),
                    ]),
            ],
        ]);
    }

    /**
     * Re-verify a payment against the provider and reconcile local status to the
     * provider's authoritative result. This is the only admin path that can
     * promote a payment to successful, and only when the provider confirms it.
     */
    public function verify(Request $request, PaymentGatewayContract $gateway, Payment $payment): JsonResponse
    {
        SupportedLocales::apply($request);

        $result = $this->verifyWithProvider($gateway, $payment);

        $payment = DB::transaction(function () use ($payment, $result): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->applyProviderResult($locked, $result);
            $locked->save();

            return $locked;
        });

        AuditLogger::record('admin.payment_verified', $payment, $request->user()?->id, [
            'status' => $payment->status,
            'providerReference' => $payment->provider_reference,
        ], $request->ip());

        return response()->json([
            'message' => __('messages.admin.payment_verified'),
            'data' => ['payment' => new PaymentResource(
                $payment->load(['user' => fn ($q) => $q->withProfileAggregates($request->user())])
            )],
        ]);
    }

    /**
     * Reconcile a payment: fetch the provider's authoritative status, correct the
     * local record if it drifted, and stamp the reconciliation with an outcome note.
     */
    public function reconcile(Request $request, PaymentGatewayContract $gateway, Payment $payment): JsonResponse
    {
        SupportedLocales::apply($request);

        $before = $payment->status;
        $result = $this->verifyWithProvider($gateway, $payment);
        $matched = $result['status'] === $before;

        $payment = DB::transaction(function () use ($payment, $result, $matched): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->applyProviderResult($locked, $result);
            $locked->reconciled_at = now();
            $locked->reconciliation_note = $matched
                ? 'In sync with provider ('.$result['status'].').'
                : 'Corrected to provider status ('.$result['status'].').';
            $locked->save();

            return $locked;
        });

        AuditLogger::record('admin.payment_reconciled', $payment, $request->user()?->id, [
            'before' => $before,
            'after' => $payment->status,
            'matched' => $matched,
        ], $request->ip());

        return response()->json([
            'message' => __('messages.admin.payment_reconciled'),
            'data' => ['payment' => new PaymentResource(
                $payment->load(['user' => fn ($q) => $q->withProfileAggregates($request->user())])
            )],
        ]);
    }

    public function refund(Request $request, PaymentGatewayContract $gateway, Payment $payment): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($payment->status === 'refunded') {
            return response()->json([
                'message' => __('messages.admin.payment_already_refunded'),
                'data' => ['payment' => new PaymentResource($payment)],
            ]);
        }

        if ($payment->status !== 'successful') {
            throw ValidationException::withMessages([
                'status' => [__('messages.admin.payment_not_refundable')],
            ]);
        }

        try {
            $gateway->refund($payment->provider_reference ?: $payment->reference, $data['amount'] ?? null);
        } catch (PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'gateway' => [__('messages.admin.payment_refund_failed').' '.$exception->getMessage()],
            ]);
        }

        $payment = DB::transaction(function () use ($payment, $data): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $locked->status = 'refunded';
            $locked->refunded_at = now();
            $locked->metadata = array_merge($locked->metadata ?? [], [
                'refundReason' => $data['reason'] ?? null,
                'refundAmount' => $data['amount'] ?? $locked->amount,
            ]);
            $locked->save();

            return $locked;
        });

        AuditLogger::record('admin.payment_refunded', $payment, $request->user()?->id, [
            'amount' => $data['amount'] ?? $payment->amount,
            'reason' => $data['reason'] ?? null,
        ], $request->ip());

        return response()->json([
            'message' => __('messages.admin.payment_refunded'),
            'data' => ['payment' => new PaymentResource(
                $payment->load(['user' => fn ($q) => $q->withProfileAggregates($request->user())])
            )],
        ]);
    }

    public function review(Request $request, Payment $payment): JsonResponse
    {
        SupportedLocales::apply($request);

        $data = $request->validate([
            'action' => ['required', 'in:flag,clear'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $flag = $data['action'] === 'flag';
        $payment->is_flagged = $flag;
        $payment->flag_reason = $flag ? ($data['reason'] ?? null) : null;
        $payment->reviewed_by = $request->user()?->id;
        $payment->reviewed_at = now();
        $payment->save();

        AuditLogger::record('admin.payment_reviewed', $payment, $request->user()?->id, [
            'action' => $data['action'],
        ], $request->ip());

        return response()->json([
            'message' => __('messages.admin.payment_reviewed'),
            'data' => ['payment' => new PaymentResource(
                $payment->load(['user' => fn ($q) => $q->withProfileAggregates($request->user())])
            )],
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        SupportedLocales::apply($request);

        $query = $this->applyFilters(Payment::query()->with('user'), $request)->latest('created_at');
        $filename = 'payments-'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Reference', 'ProviderReference', 'Provider', 'User', 'Email', 'Purpose', 'Amount', 'Fees', 'Currency', 'Status', 'Channel', 'PaidAt', 'CreatedAt']);

            $query->chunk(200, function ($chunk) use ($handle): void {
                foreach ($chunk as $payment) {
                    fputcsv($handle, [
                        $payment->id,
                        $payment->reference,
                        $payment->provider_reference,
                        $payment->provider,
                        $payment->user?->username,
                        $payment->email,
                        $payment->purpose,
                        $payment->amount,
                        $payment->fees,
                        $payment->currency,
                        $payment->status,
                        $payment->channel,
                        optional($payment->paid_at)->toIso8601String(),
                        optional($payment->created_at)->toIso8601String(),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyWithProvider(PaymentGatewayContract $gateway, Payment $payment): array
    {
        try {
            return $gateway->verify($payment->provider_reference ?: $payment->reference);
        } catch (PaymentGatewayException $exception) {
            throw ValidationException::withMessages([
                'gateway' => [__('messages.admin.payment_verification_failed').' '.$exception->getMessage()],
            ]);
        }
    }

    /**
     * Apply an authoritative provider result to a payment record. Success is the
     * only status that stamps paid_at, and it is applied solely from provider data.
     *
     * @param  array<string, mixed>  $result
     */
    private function applyProviderResult(Payment $payment, array $result): void
    {
        $payment->status = $result['status'];
        $payment->provider_reference = $result['providerReference'] ?? $payment->provider_reference;
        $payment->channel = $result['channel'] ?? $payment->channel;
        $payment->gateway_response = $result['gatewayResponse'] ?? $payment->gateway_response;

        if (isset($result['fees'])) {
            $payment->fees = (int) $result['fees'];
        }

        if ($result['status'] === 'successful') {
            $payment->paid_at = $payment->paid_at
                ?? ($result['paidAt'] ? Carbon::parse($result['paidAt']) : now());
        }
    }

    private function applyFilters(Builder $builder, Request $request): Builder
    {
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $provider = trim((string) $request->query('provider', ''));
        $userId = $request->integer('userId');
        [$from, $to] = $this->resolveRange($request);

        return $builder
            ->when($query !== '', fn (Builder $b) => $b->where(function (Builder $group) use ($query): void {
                $group->where('reference', 'like', '%'.$query.'%')
                    ->orWhere('provider_reference', 'like', '%'.$query.'%')
                    ->orWhere('email', 'like', '%'.$query.'%')
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.$query.'%')
                        ->orWhere('username', 'like', '%'.$query.'%')
                        ->orWhere('email', 'like', '%'.$query.'%'));
            }))
            ->when(in_array($status, self::STATUSES, true), fn (Builder $b) => $b->where('status', $status))
            ->when($provider !== '', fn (Builder $b) => $b->where('provider', $provider))
            ->when($userId > 0, fn (Builder $b) => $b->where('user_id', $userId))
            ->when($request->query('from') || $request->query('to'), fn (Builder $b) => $b->whereBetween('created_at', [$from, $to]));
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'totalPayments' => Payment::query()->count(),
            'successful' => Payment::query()->where('status', 'successful')->count(),
            'failed' => Payment::query()->where('status', 'failed')->count(),
            'pending' => Payment::query()->whereIn('status', ['pending', 'processing'])->count(),
            'refunded' => Payment::query()->where('status', 'refunded')->count(),
            'flagged' => Payment::query()->where('is_flagged', true)->count(),
            'totalRevenue' => (int) Payment::query()->where('status', 'successful')->sum('amount'),
            'totalFees' => (int) Payment::query()->where('status', 'successful')->sum('fees'),
            'refundedAmount' => (int) Payment::query()->where('status', 'refunded')->sum('amount'),
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
