<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigurePasskeyRelyingParty
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($host !== '') {
            configurePasskeyRelyingParty($host, passkeyAllowedOrigins($request));
        }

        return $next($request);
    }
}
