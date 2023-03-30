<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
