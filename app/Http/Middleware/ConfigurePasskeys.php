<?php

namespace App\Http\Middleware;

use App\Support\PasskeyRelyingParty;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigurePasskeys
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passkey.*')) {
            return $next($request);
        }

        $relyingParty = instanceSettings()->is_passkey_enabled ? PasskeyRelyingParty::resolve($request) : null;
        abort_if($relyingParty === null, 404);

        config([
            'passkeys.relying_party_id' => $relyingParty['id'],
            'passkeys.allowed_origins' => $relyingParty['origins'],
        ]);

        return $next($request);
    }
}
