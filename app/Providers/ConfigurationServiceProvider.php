<?php

namespace App\Providers;

use App\Services\ConfigurationRepository;
use Illuminate\Support\ServiceProvider;

class ConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConfigurationRepository::class, function ($app) {
            return new ConfigurationRepository($app['config']);
        });
    }

    public function boot(): void
    {
        //
    }
}
