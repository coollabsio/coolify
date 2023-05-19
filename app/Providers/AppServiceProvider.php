<?php

namespace App\Providers;

use App\Jobs\CoolifyTask;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

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
        if (! $this->app->environment('production')) {
            \Illuminate\Support\Facades\Mail::alwaysTo('noone@example.com');
        }

        Queue::after(function (JobProcessed $event) {
            // @TODO: Remove `coolify-builder` container after the remoteProcess job is finishged and remoteProcess->type == `deployment`.
            if ($event->job->resolveName() === CoolifyTask::class) {
            }
        });
    }
}
