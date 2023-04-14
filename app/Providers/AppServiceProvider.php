<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // @TODO: Is this the best place to run the seeder?
        // if (env('APP_ENV') === 'production') {
        //     dump('Seed default data.');
        //     Process::run('php artisan db:seed --class=ProductionSeeder --force');
        // } else {
        //     dump('Not in production environment.');
        // }
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::after(function (JobProcessed $event) {
             // @TODO: Remove `coolify-builder` container after the remoteProcess job is finishged and remoteProcess->type == `deployment`.
            if ($event->job->resolveName() === 'App\Jobs\ExecuteRemoteProcess') {

            }
        });
    }
}
