<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // Container environment variables must never select the development database.
        putenv('DB_CONNECTION=testing');
        $_ENV['DB_CONNECTION'] = 'testing';
        $_SERVER['DB_CONNECTION'] = 'testing';

        $app = require __DIR__.'/../bootstrap/app.php';

        if ($app->configurationIsCached()) {
            throw new \RuntimeException('Clear the configuration cache before running tests.');
        }

        $app->make(Kernel::class)->bootstrap();

        if ($app->make('config')->get('database.default') !== 'testing'
            || $app->make('config')->get('database.connections.testing.database') !== ':memory:') {
            throw new \RuntimeException('Tests require the in-memory testing database. Clear the configuration cache before running tests.');
        }

        return $app;
    }
}
