<?php

namespace App\Http\Middleware;

use App\Support\SupportedLocales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        SupportedLocales::apply($request);

        return $next($request);
    }
}