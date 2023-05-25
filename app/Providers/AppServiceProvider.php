<?php

namespace App\Providers;

use App\Jobs\CoolifyTask;
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
        // if (config('app.env') === 'production' && Str::contains(config('version'), ['nightly'])) {
        //     Process::run('php artisan migrate:fresh --force --seed --seeder=ProductionSeeder');
        // }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::after(function (JobProcessed $event) {
            // @TODO: Remove `coolify-builder` container after the remote_process job is finishged and remote_process->type == `deployment`.
            if ($event->job->resolveName() === CoolifyTask::class) {
            }
        });
    }
}
