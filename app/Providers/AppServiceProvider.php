<?php

namespace App\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Http::macro('github', function (string $api_url, string|null $github_access_token = null) {
            if ($github_access_token) {
                return Http::withHeaders([
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => "Bearer $github_access_token",
                ])->baseUrl($api_url);
            } else {
                return Http::withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                ])->baseUrl($api_url);
            }
        });
    }
}
