<?php

namespace App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Http::macro('github', function (string $api_url) {
            return Http::withHeaders([
                'Accept' => 'application/vnd.github.v3+json'
            ])->baseUrl($api_url);
        });
    }
}
