<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureHttps();
        $this->configurePasswordValidation();
        $this->configureCommands();
        $this->configureModels();
        $this->configureDates();
        $this->configureTests();
        $this->configureRequestExceptions();
        $this->configureVite();
    }

    /**
     * Configure HTTPS for production.
     */
    private function configureHttps(): void
    {
        if (App::isProduction() && (bool) config('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Configure password validation for production.
     */
    private function configurePasswordValidation(): void
    {
        if (App::isProduction()) {
            Password::defaults(fn () => Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised());
        }
    }

    /**
     * Configure commands for production.
     */
    private function configureCommands(): void
    {
        if (App::isProduction()) {
            DB::prohibitDestructiveCommands();
        }
    }

    /**
     * Configure models.
     */
    private function configureModels(): void
    {
        Model::automaticallyEagerLoadRelationships();

        if (! App::isProduction()) {
            Model::preventLazyLoading();
        }

        Model::preventSilentlyDiscardingAttributes();
        Model::preventAccessingMissingAttributes();

        Model::unguard();

        Relation::enforceMorphMap([
            // Add you polymorphic relations here. For example:
            // 'application' => \App\Models\Application::class,
        ]);
    }

    /**
     * Configure dates.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    /**
     * Configure tests.
     */
    private function configureTests(): void
    {
        if (App::runningUnitTests()) {
            Sleep::fake();
            Http::preventStrayRequests();
        }
    }

    /**
     * Configure request exceptions.
     */
    private function configureRequestExceptions(): void
    {
        if (! App::isProduction()) {
            RequestException::dontTruncate();
        }
    }

    /**
     * Configure Vite for better performance.
     */
    private function configureVite(): void
    {
        Vite::useAggressivePrefetching();
    }
}
