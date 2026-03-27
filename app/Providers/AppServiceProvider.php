<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Console\DumpCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

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
        $this->configureQueues();
        $this->configureRequestExceptions();
        $this->configureVite();
    }

    /**
     * Configure HTTPS for production.
     */
    private function configureHttps(): void
    {
        if (app()->isProduction() && config()->boolean('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Configure password validation for production.
     */
    private function configurePasswordValidation(): void
    {
        Password::defaults(fn () => app()->isProduction()
            ? Password::min(12)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null);
    }

    /**
     * Configure commands for production.
     */
    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands(app()->isProduction());
        DumpCommand::prohibit(app()->isProduction());
    }

    /**
     * Configure models.
     */
    private function configureModels(): void
    {
        Model::automaticallyEagerLoadRelationships();
        Model::preventLazyLoading(! app()->isProduction());
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
     * Configure queues.
     */
    private function configureQueues(): void
    {
        Queue::withoutInterruptionPolling();
    }

    /**
     * Configure request exceptions.
     */
    private function configureRequestExceptions(): void
    {
        if (! app()->isProduction()) {
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
