<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($request->path() === 'api/health') {
                return Limit::perMinute(1000)->by($request->user()?->id ?: $request->ip());
            }

            return Limit::perMinute((int) config('api.rate_limit'))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('terminal-api-exec', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $teamId = data_get($token, 'team_id', 'unknown');
            $tokenId = $token?->getKey() ?: $request->user()?->id ?: $request->ip();

            return Limit::perMinute(10)
                ->by("terminal-api-exec:team:{$teamId}:token:{$tokenId}")
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many terminal command requests. Please retry in '.($headers['Retry-After'] ?? 60).' seconds.',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers));
        });
        RateLimiter::for('5', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('feedback', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });
    }
}
