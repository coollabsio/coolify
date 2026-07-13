<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ApplicationSetting;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\DiscordNotificationSettings;
use App\Models\EmailNotificationSettings;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\PushoverNotificationSettings;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\SlackNotificationSettings;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\TelegramNotificationSettings;
use App\Models\WebhookNotificationSettings;
use App\Policies\ApiTokenPolicy;
use App\Policies\ApplicationPolicy;
use App\Policies\ApplicationPreviewPolicy;
use App\Policies\ApplicationSettingPolicy;
use App\Policies\CloudInitScriptPolicy;
use App\Policies\CloudProviderTokenPolicy;
use App\Policies\DatabasePolicy;
use App\Policies\EnvironmentPolicy;
use App\Policies\EnvironmentVariablePolicy;
use App\Policies\GithubAppPolicy;
use App\Policies\InstanceSettingsPolicy;
use App\Policies\NotificationPolicy;
use App\Policies\PrivateKeyPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ResourceCreatePolicy;
use App\Policies\S3StoragePolicy;
use App\Policies\ServerPolicy;
use App\Policies\ServiceApplicationPolicy;
use App\Policies\ServiceDatabasePolicy;
use App\Policies\ServicePolicy;
use App\Policies\SharedEnvironmentVariablePolicy;
use App\Policies\StandaloneDockerPolicy;
use App\Policies\SwarmDockerPolicy;
use App\Policies\TeamPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Server::class => ServerPolicy::class,
        PrivateKey::class => PrivateKeyPolicy::class,
        StandaloneDocker::class => StandaloneDockerPolicy::class,
        SwarmDocker::class => SwarmDockerPolicy::class,
        Application::class => ApplicationPolicy::class,
        ApplicationPreview::class => ApplicationPreviewPolicy::class,
        ApplicationSetting::class => ApplicationSettingPolicy::class,
        Service::class => ServicePolicy::class,
        ServiceApplication::class => ServiceApplicationPolicy::class,
        ServiceDatabase::class => ServiceDatabasePolicy::class,
        Project::class => ProjectPolicy::class,
        Environment::class => EnvironmentPolicy::class,
        EnvironmentVariable::class => EnvironmentVariablePolicy::class,
        SharedEnvironmentVariable::class => SharedEnvironmentVariablePolicy::class,
        // Database policies - all use the shared DatabasePolicy
        StandalonePostgresql::class => DatabasePolicy::class,
        StandaloneMysql::class => DatabasePolicy::class,
        StandaloneMariadb::class => DatabasePolicy::class,
        StandaloneMongodb::class => DatabasePolicy::class,
        StandaloneRedis::class => DatabasePolicy::class,
        StandaloneKeydb::class => DatabasePolicy::class,
        StandaloneDragonfly::class => DatabasePolicy::class,
        StandaloneClickhouse::class => DatabasePolicy::class,

        // Notification policies - all use the shared NotificationPolicy
        EmailNotificationSettings::class => NotificationPolicy::class,
        DiscordNotificationSettings::class => NotificationPolicy::class,
        TelegramNotificationSettings::class => NotificationPolicy::class,
        SlackNotificationSettings::class => NotificationPolicy::class,
        PushoverNotificationSettings::class => NotificationPolicy::class,
        WebhookNotificationSettings::class => NotificationPolicy::class,

        // API Token policy
        PersonalAccessToken::class => ApiTokenPolicy::class,

        // Instance settings policy
        InstanceSettings::class => InstanceSettingsPolicy::class,

        // S3 storage policy
        S3Storage::class => S3StoragePolicy::class,

        // Team policy
        Team::class => TeamPolicy::class,

        // Git source policies
        GithubApp::class => GithubAppPolicy::class,
        CloudProviderToken::class => CloudProviderTokenPolicy::class,
        CloudInitScript::class => CloudInitScriptPolicy::class,

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
