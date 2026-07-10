<?php

namespace App\Database;

use Illuminate\Support\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('database.connection', function ($app) {
            return new DatabaseConnection();
        });
    }

    public function boot()
    {
        $this->app->bind('database.connection', function ($app) {
            return new DatabaseConnection();
        });
    }
}
