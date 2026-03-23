<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\EmailNotificationSettings;
use App\Models\Environment;
use App\Models\HostedEmailSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\App;
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
        $this->configureTests();
        $this->configureRequestExceptions();
        $this->configureVite();
    }

    /**
     * Configure HTTPS for production.
     */
    private function configureHttps(): void
    {
        if (App::isProduction() && config()->boolean('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Configure password validation for production.
     */
    private function configurePasswordValidation(): void
    {
        Password::defaults(fn () => App::isProduction()
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
        DB::prohibitDestructiveCommands(App::isProduction());
    }

    /**
     * Configure models.
     */
    private function configureModels(): void
    {
        Model::automaticallyEagerLoadRelationships();
        Model::preventLazyLoading(! App::isProduction());
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
