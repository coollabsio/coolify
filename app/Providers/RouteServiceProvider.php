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
        RateLimiter::for('5', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('feedback', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->email.'|'.auth_rate_limit_ip($request));
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            $limits = [
                Limit::perMinutes(10, 3)->by('forgot-password:ip:'.sha1(auth_rate_limit_ip($request))),
            ];

            $emailIdentity = normalize_email_identity($request->input('email'));
            if ($emailIdentity !== null) {
                $limits[] = Limit::perHour(3)->by('forgot-password:email-identity:'.sha1($emailIdentity));
            }

            return $limits;
        });

        RateLimiter::for('magic-link', function (Request $request) {
            return Limit::perMinute(5)->by(hash('sha256', (string) $request->input('token').'|'.auth_rate_limit_ip($request)));
        });

        RateLimiter::for('force-password-reset', function (Request $request) {
            return Limit::perMinute(15)->by($request->user()->id);
        });
    }
}
