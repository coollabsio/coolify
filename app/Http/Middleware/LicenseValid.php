<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class LicenseValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('coolify.self_hosted')) {
            $value = Cache::get('license_key');
            if (!$value) {
                ray($request->path());
                if ($request->path() !== 'license' && $request->path() !== 'livewire/message/license') {
                    return redirect('license');
                }
            }
        }
        return $next($request);
    }
}
