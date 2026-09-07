<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBlocked()) {
            $banned = $user->isBanned();

            return new JsonResponse([
                'message' => __($banned ? 'messages.auth.account_banned' : 'messages.auth.account_suspended'),
                'errors' => [
                    'account' => [__($banned ? 'messages.auth.account_banned_detail' : 'messages.auth.account_suspended_detail')],
                ],
            ], 403);
        }

        return $next($request);
    }
}