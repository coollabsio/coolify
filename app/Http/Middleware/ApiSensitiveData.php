<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiSensitiveData
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()->currentAccessToken();

        // Allow access to sensitive data if token has root or read:sensitive permission
        $request->attributes->add([
            'can_read_sensitive' => $token->can('root') || $token->can('read:sensitive'),
        ]);

        return $next($request);
    }
}
