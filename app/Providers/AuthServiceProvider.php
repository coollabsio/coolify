<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Policies\ResourceCreatePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

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
        \App\Models\Service::class => \App\Policies\ServicePolicy::class,
        \App\Models\ServiceApplication::class => \App\Policies\ServiceApplicationPolicy::class,
        \App\Models\ServiceDatabase::class => \App\Policies\ServiceDatabasePolicy::class,
        \App\Models\Project::class => \App\Policies\ProjectPolicy::class,
        \App\Models\Environment::class => \App\Policies\EnvironmentPolicy::class,
        \App\Models\EnvironmentVariable::class => \App\Policies\EnvironmentVariablePolicy::class,
        \App\Models\SharedEnvironmentVariable::class => \App\Policies\SharedEnvironmentVariablePolicy::class,
        // Database policies - all use the shared DatabasePolicy
        \App\Models\StandalonePostgresql::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneMysql::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneMariadb::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneMongodb::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneRedis::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneKeydb::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneDragonfly::class => \App\Policies\DatabasePolicy::class,
        \App\Models\StandaloneClickhouse::class => \App\Policies\DatabasePolicy::class,

        // Notification policies - all use the shared NotificationPolicy
        \App\Models\EmailNotificationSettings::class => \App\Policies\NotificationPolicy::class,
        \App\Models\DiscordNotificationSettings::class => \App\Policies\NotificationPolicy::class,
        \App\Models\TelegramNotificationSettings::class => \App\Policies\NotificationPolicy::class,
        \App\Models\SlackNotificationSettings::class => \App\Policies\NotificationPolicy::class,
        \App\Models\PushoverNotificationSettings::class => \App\Policies\NotificationPolicy::class,
        \App\Models\WebhookNotificationSettings::class => \App\Policies\NotificationPolicy::class,

        // API Token policy
        \Laravel\Sanctum\PersonalAccessToken::class => \App\Policies\ApiTokenPolicy::class,

        // Instance settings policy
        \App\Models\InstanceSettings::class => \App\Policies\InstanceSettingsPolicy::class,

        // Team policy
        \App\Models\Team::class => \App\Policies\TeamPolicy::class,

        // Git source policies
        \App\Models\GithubApp::class => \App\Policies\GithubAppPolicy::class,

    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Register gates for resource creation policy
        Gate::define('createAnyResource', [ResourceCreatePolicy::class, 'createAny']);

        // Register gate for terminal access
        Gate::define('canAccessTerminal', function ($user) {
            return $user->isAdmin() || $user->isOwner();
        });
    }
}
