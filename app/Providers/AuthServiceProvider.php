<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\Server::class => \App\Policies\ServerPolicy::class,
        \App\Models\PrivateKey::class => \App\Policies\PrivateKeyPolicy::class,
        \App\Models\StandaloneDocker::class => \App\Policies\StandaloneDockerPolicy::class,
        \App\Models\SwarmDocker::class => \App\Policies\SwarmDockerPolicy::class,
        \App\Models\Application::class => \App\Policies\ApplicationPolicy::class,
        \App\Models\ApplicationPreview::class => \App\Policies\ApplicationPreviewPolicy::class,
        \App\Models\ApplicationSetting::class => \App\Policies\ApplicationSettingPolicy::class,
        // Database policies - all use the shared DatabasePolicy
        \App\Models\StandalonePostgresql::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneMysql::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneMariadb::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneMongodb::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneRedis::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneKeydb::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneDragonfly::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneClickhouse::class => \App\Policies\DatabasePolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}
