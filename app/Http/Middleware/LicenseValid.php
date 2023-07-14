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
        if (isCloud()) {
            $value = Cache::get('license_key');
            if (isDev()) {
                $value = true;
            }
            if (!$value) {
                if ($request->path() !== 'license' && $request->path() !== 'livewire/message/license') {
                    return redirect('license');
                }
            } else {
                if ($request->path() === 'license' || $request->path() === 'livewire/message/license') {
                    return redirect('home');
                }
            }
        }
        return $next($request);
    }
}
