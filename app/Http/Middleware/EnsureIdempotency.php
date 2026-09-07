<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency guard for financial write endpoints.
 *
 * When a client sends an `Idempotency-Key` header on a POST/PATCH/PUT/DELETE
 * request, the first successful response is persisted per (user, key) and
 * replayed on any later request carrying the same key. Reusing a key with a
 * different request body, or while the first request is still in-flight, is
 * rejected with 409 so a duplicate charge/refund/payout can never be created.
 *
 * The header is optional: requests without it pass straight through, keeping
 * existing clients working. Apply via the `idempotent` route middleware alias.
 */
class EnsureIdempotency
{
    private const WRITE_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $user = $request->user();

        if ($key === '' || $user === null || ! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            return $this->resolveExisting($existing, $hash);
        }

        try {
            $record = IdempotencyKey::query()->create([
                'user_id' => $user->id,
                'key' => $key,
                'method' => $request->method(),
                'path' => $request->path(),
                'request_hash' => $hash,
            ]);
        } catch (QueryException $exception) {
            $duplicate = IdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('key', $key)
                ->first();

            if ($duplicate === null) {
                throw $exception;
            }

            return $this->resolveExisting($duplicate, $hash);
        }

        $response = $next($request);

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $record->forceFill([
                'status_code' => $status,
                'response_body' => $response->getContent(),
            ])->save();
        } else {
            $record->delete();
        }

        return $response;
    }

    private function resolveExisting(IdempotencyKey $record, string $hash): Response
    {
        if ($record->request_hash !== $hash) {
            abort(409, __('messages.idempotency.key_reused'));
        }

        if ($record->status_code === null) {
            abort(409, __('messages.idempotency.in_progress'));
        }

        return response(
            $record->response_body ?? '',
            (int) $record->status_code,
        )->withHeaders([
            'Content-Type' => 'application/json',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
