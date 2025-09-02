<?php

namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class ContainerAccessMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $containerId = $request->route('containerId');
        if (! $containerId) {
            return response()->json(['message' => 'Container ID required'], 400);
        }

        // Rate limiting per user per container
        $rateLimitKey = "file-browser:{$user->id}:{$containerId}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 60)) {
            return response()->json([
                'message' => 'Too many requests. Please try again later.',
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60); // 60 requests per minute

        // Validate container access
        $application = Application::where('uuid', $containerId)->first();
        if (! $application) {
            return response()->json(['message' => 'Container not found'], 404);
        }

        if (! $user->can('view', $application)) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        return $next($request);
    }
}
