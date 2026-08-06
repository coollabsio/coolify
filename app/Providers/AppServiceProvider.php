<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\V5\Application;
use App\Support\V5\V5Feature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StripeClient::class, fn () => new StripeClient(config('subscription.stripe_api_key')));
    }

    public function boot(): void
    {
        $this->configureCommands();

        if (V5Feature::enabled()) {
            $this->loadMigrationsFrom(database_path('migrations-v5'));
            $this->configureMorphMap();
        }

        $this->configureModels();
        $this->configurePasswords();
        $this->configureSanctumModel();
        $this->configureGitHubHttp();

    }

    private function configureCommands(): void
    {
        if (App::isProduction()) {
            DB::prohibitDestructiveCommands();
        }
    }

    /**
     * Map v5 models to stable morph aliases so polymorphic rows survive class
     * renames. Deliberately NOT enforced: v4 polymorphic relations store FQCNs
     * and must keep resolving them.
     */
    private function configureMorphMap(): void
    {
        Relation::morphMap([
            'v5.application' => Application::class,
        ]);
    }

    private function configureModels(): void
    {
        // Disabled because it's causing issues with the application
        // Model::shouldBeStrict();
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            return App::isProduction()
                ? Password::min(8)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : Password::min(8)->letters();
        });
    }

    private function configureSanctumModel(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    private function configureGitHubHttp(): void
    {
        Http::macro('GitHub', function (string $api_url, ?string $github_access_token = null) {
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

        Http::macro('GitLab', function (string $api_url, ?string $access_token = null) {
            $client = Http::withHeaders([
                'Accept' => 'application/json',
            ])->baseUrl($api_url);
            if ($access_token) {
                $client = $client->withToken($access_token);
            }

            return $client;
        });
    }
}
