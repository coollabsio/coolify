<?php

namespace App\Providers;

use App\Jobs\CoolifyTask;
use App\Models\InstanceSettings;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        if ($this->app->environment('production')) {
            $settings = InstanceSettings::first();
            if (Str::startsWith($settings->fqdn, 'https')) {
                URL::forceScheme('https');
            }
        }
    }
}
