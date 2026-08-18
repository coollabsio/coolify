<?php

namespace App\Services\ServerTransfer;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ServerTransferExporter
{
    /**
     * Settings that are instance-bound or runtime health state and must not transfer.
     *
     * @var list<string>
     */
    private const SKIP_SERVER_SETTINGS = [
        'id',
        'server_id',
        'created_at',
        'updated_at',
        'is_reachable',
        'is_usable',
        'force_disabled',
        'sentinel_token',
        'sentinel_custom_url',
        'is_sentinel_enabled',
        'is_sentinel_debug_enabled',
    ];

    /**
     * Application attributes that are relational FKs remapped on import.
     *
     * @var list<string>
     */
    private const SKIP_APPLICATION_ATTRS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'environment_id',
        'destination_id',
        'destination_type',
        'source_id',
        'source_type',
        'private_key_id',
        'additional_servers_count',
        'additional_networks_count',
        'server_status',
    ];

    /**
     * @return array<string, mixed>
     */
    public function export(Server $server, bool $includeSensitive = true): array
    {
        if ($server->id === 0) {
            throw new RuntimeException('The Coolify host (localhost) cannot be transferred between instances.');
        }

        $server->loadMissing(['settings', 'privateKey', 'standaloneDockers', 'swarmDockers', 'cloudProviderToken', 'sslCertificates']);

        $privateKey = $server->privateKey;
        if (! $privateKey instanceof PrivateKey) {
            throw new RuntimeException('Server has no private key to export.');
        }

        if (! $includeSensitive) {
            throw new RuntimeException('Server transfer export requires sensitive data access (read:sensitive).');
        }

        // applications() also includes multi-destination attachments on this server;
        // unique by id so a multi-dest app is only considered once for validation/export.
        $applications = $server->applications()->unique('id')->values();
        $databases = collect($server->databases())->unique(fn ($db) => $db::class.':'.$db->id)->values();
        $services = $server->services()->get()->unique('id')->values();

        $this->assertNoAdditionalDestinations($server, $applications);

        $projectIds = $this->collectProjectIds($applications, $databases, $services);
        $projects = Project::query()->whereIn('id', $projectIds)->orderBy('name')->get();

        $destinationUuidByKey = $this->destinationUuidMap($server);
        $dependencies = $this->collectDependencies($server, $applications, $databases, $services);

        $sourceInstanceUrl = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');
        $warnings = $this->buildWarnings($applications, $dependencies, $sourceInstanceUrl);

        $payload = ServerTransferBundle::wrap([
            'source_instance' => [
                'url' => $sourceInstanceUrl,
                'name' => config('app.name'),
            ],
            'warnings' => $warnings,
            // Back-compat: single server key
            'private_key' => $this->exportPrivateKey($privateKey),
            // All keys needed by server, apps, and git sources
            'private_keys' => $dependencies['private_keys']->map(fn (PrivateKey $key) => $this->exportPrivateKey($key))->values()->all(),
            'github_apps' => $dependencies['github_apps']->map(fn (GithubApp $app) => $this->exportGithubApp($app))->values()->all(),
            'gitlab_apps' => $dependencies['gitlab_apps']->map(fn (GitlabApp $app) => $this->exportGitlabApp($app))->values()->all(),
            's3_storages' => $dependencies['s3_storages']->map(fn (S3Storage $storage) => $this->exportS3Storage($storage))->values()->all(),
            'cloud_provider_tokens' => $dependencies['cloud_provider_tokens']->map(fn (CloudProviderToken $token) => $this->exportCloudProviderToken($token))->values()->all(),
            'ssl_certificates' => $this->exportSslCertificates($server),
            'volume_backups' => $this->exportVolumeBackups($applications, $databases, $services),
            'server' => $this->exportServer($server),
            'destinations' => $this->exportDestinations($server),
            'shared_environment_variables' => [
                'server' => $this->exportSharedEnvVars(
                    SharedEnvironmentVariable::query()
                        ->where('type', 'server')
                        ->where('server_id', $server->id)
                        ->get()
                ),
            ],
            'projects' => $projects->map(function (Project $project) use ($server, $destinationUuidByKey, $applications, $databases, $services) {
                return $this->exportProject($project, $server, $destinationUuidByKey, $applications, $databases, $services);
            })->values()->all(),
        ]);

        return $payload;
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, Model>  $databases
     * @param  Collection<int, Service>  $services
     * @return array{
     *     private_keys: Collection<int, PrivateKey>,
     *     github_apps: Collection<int, GithubApp>,
     *     gitlab_apps: Collection<int, GitlabApp>,
     *     s3_storages: Collection<int, S3Storage>,
     *     cloud_provider_tokens: Collection<int, CloudProviderToken>
     * }
     */
    private function collectDependencies(Server $server, Collection $applications, Collection $databases, Collection $services): array
    {
        $privateKeys = collect([$server->privateKey])->filter();
        $githubApps = collect();
        $gitlabApps = collect();
        $s3Ids = collect();

        foreach ($applications as $application) {
            if ($application->private_key_id) {
                $key = PrivateKey::find($application->private_key_id);
                if ($key) {
                    $privateKeys->push($key);
                }
            }

            if ($application->source_type === GithubApp::class && $application->source_id !== null) {
                $gh = GithubApp::find($application->source_id);
                // System-wide GitHub Apps are instance-owned; do not copy them.
                // Application export still records source.uuid so import can re-link
                // to an existing system-wide app on the target instance.
                if ($gh && ! $gh->is_system_wide) {
                    $githubApps->push($gh);
                    if ($gh->private_key_id) {
                        $pk = PrivateKey::find($gh->private_key_id);
                        if ($pk) {
                            $privateKeys->push($pk);
                        }
                    }
                }
            }

            if ($application->source_type === GitlabApp::class && $application->source_id !== null) {
                $gl = GitlabApp::find($application->source_id);
                // System-wide GitLab Apps are instance-owned; re-link by UUID on import.
                if ($gl && ! $gl->is_system_wide) {
                    $gitlabApps->push($gl);
                    if ($gl->private_key_id) {
                        $pk = PrivateKey::find($gl->private_key_id);
                        if ($pk) {
                            $privateKeys->push($pk);
                        }
                    }
                }
            }
        }

        $collectVolumeS3 = function ($resource) use (&$s3Ids): void {
            if (method_exists($resource, 'persistentStorages')) {
                foreach ($resource->persistentStorages as $volume) {
                    foreach ($volume->scheduledBackups as $vb) {
                        if ($vb->s3_storage_id) {
                            $s3Ids->push($vb->s3_storage_id);
                        }
                    }
                }
            }
            if (method_exists($resource, 'fileStorages')) {
                foreach ($resource->fileStorages as $file) {
                    foreach ($file->scheduledBackups as $vb) {
                        if ($vb->s3_storage_id) {
                            $s3Ids->push($vb->s3_storage_id);
                        }
                    }
                }
            }
        };

        foreach ($databases as $database) {
            if (method_exists($database, 'scheduledBackups')) {
                foreach ($database->scheduledBackups as $backup) {
                    if ($backup->s3_storage_id) {
                        $s3Ids->push($backup->s3_storage_id);
                    }
                }
            }
            $database->loadMissing(['persistentStorages.scheduledBackups', 'fileStorages.scheduledBackups']);
            $collectVolumeS3($database);
        }

        foreach ($applications as $application) {
            $application->loadMissing(['persistentStorages.scheduledBackups', 'fileStorages.scheduledBackups', 'previews.persistentStorages.scheduledBackups']);
            $collectVolumeS3($application);
            foreach ($application->previews as $preview) {
                $collectVolumeS3($preview);
            }
        }

        foreach ($services as $service) {
            $service->loadMissing([
                'applications.environment_variables',
                'applications.persistentStorages.scheduledBackups',
                'applications.fileStorages.scheduledBackups',
                'databases.persistentStorages.scheduledBackups',
                'databases.fileStorages.scheduledBackups',
                'databases.scheduledBackups',
            ]);
            foreach ($service->applications as $serviceApp) {
                $collectVolumeS3($serviceApp);
            }
            foreach ($service->databases as $serviceDb) {
                foreach ($serviceDb->scheduledBackups as $backup) {
                    if ($backup->s3_storage_id) {
                        $s3Ids->push($backup->s3_storage_id);
                    }
                }
                $collectVolumeS3($serviceDb);
            }
        }

        $cloudTokens = collect();
        if ($server->cloud_provider_token_id) {
            $token = CloudProviderToken::find($server->cloud_provider_token_id);
            if ($token) {
                $cloudTokens->push($token);
            }
        }

        return [
            'private_keys' => $privateKeys->unique('id')->values(),
            'github_apps' => $githubApps->unique('id')->values(),
            'gitlab_apps' => $gitlabApps->unique('id')->values(),
            's3_storages' => S3Storage::query()->whereIn('id', $s3Ids->unique()->filter()->all())->get(),
            'cloud_provider_tokens' => $cloudTokens->unique('id')->values(),
        ];
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  array<string, mixed>  $dependencies
     * @return list<string>
     */
    private function buildWarnings(Collection $applications, array $dependencies, string $sourceInstanceUrl): array
    {
        $warnings = [];

        if ($dependencies['github_apps']->isNotEmpty()) {
            $warnings[] = 'Team-scoped GitHub Apps were exported with credentials (system-wide apps are skipped). After import, update each GitHub App webhook URL (and setup/callback URL if used) to this Coolify instance so push/PR automations keep working. '
                ."Webhook path: {$sourceInstanceUrl}/webhooks/source/github/events "
                .'(replace host with the target instance FQDN after import). '
                .'Also reinstall/refresh the GitHub App installation if installation_id is invalid on the new host.';
        }

        $usesSystemWideGithub = $applications->contains(function (Application $app) {
            if ($app->source_type !== GithubApp::class || $app->source_id === null) {
                return false;
            }
            $gh = GithubApp::find($app->source_id);

            return $gh?->is_system_wide === true;
        });
        if ($usesSystemWideGithub) {
            $warnings[] = 'Some applications use a system-wide GitHub App, which is not exported. On import, Coolify will re-link to a matching system-wide GitHub App on the target instance (by UUID) if one exists.';
        }

        $usesSystemWideGitlab = $applications->contains(function (Application $app) {
            if ($app->source_type !== GitlabApp::class || $app->source_id === null) {
                return false;
            }
            $gl = GitlabApp::find($app->source_id);

            return $gl?->is_system_wide === true;
        });
        if ($usesSystemWideGitlab) {
            $warnings[] = 'Some applications use a system-wide GitLab App, which is not exported. On import, Coolify will re-link to a matching system-wide GitLab App on the target instance (by UUID) if one exists.';
        }

        if ($dependencies['gitlab_apps']->isNotEmpty()) {
            $warnings[] = 'Team-scoped GitLab Apps/OAuth sources were exported (system-wide apps are skipped). After import, update GitLab webhook URLs to the target Coolify instance '
                ."({$sourceInstanceUrl}/webhooks/source/gitlab/events — use the new FQDN) and refresh OAuth tokens if needed.";
        }

        if ($dependencies['s3_storages']->isNotEmpty()) {
            $warnings[] = 'S3 storage credentials were exported. Confirm endpoint access from the target instance and that bucket policies still apply.';
        }

        return $warnings;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportPrivateKey(PrivateKey $key): array
    {
        try {
            $material = $key->private_key;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Cannot decrypt private key \"{$key->name}\" (uuid={$key->uuid}). "
                .'This instance APP_KEY does not match the key that encrypted the data '
                .'(common after regenerating .dev-instances/*.env). '
                .'Restore the original APP_KEY or re-save the private key material. '
                .'Original error: '.$e->getMessage(),
                previous: $e
            );
        }

        return [
            'uuid' => $key->uuid,
            'name' => $key->name,
            'description' => $key->description,
            'private_key' => $material,
            'is_git_related' => (bool) $key->is_git_related,
            'fingerprint' => $key->fingerprint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportServer(Server $server): array
    {
        $settings = $server->settings;
        $settingsPayload = [];
        if ($settings) {
            foreach ($settings->getAttributes() as $key => $value) {
                if (in_array($key, self::SKIP_SERVER_SETTINGS, true)) {
                    continue;
                }
                // Read through cast so encrypted fields are plaintext for re-encryption on import.
                $settingsPayload[$key] = $settings->{$key};
            }
        }

        return [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'description' => $server->description,
            'ip' => (string) $server->ip,
            'port' => (int) $server->port,
            'user' => (string) $server->user,
            'proxy' => $server->proxy?->toArray() ?? [],
            'is_build_server' => (bool) $server->is_build_server,
            'cloud_provider_token_uuid' => $server->cloudProviderToken?->uuid,
            'settings' => $settingsPayload,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportDestinations(Server $server): array
    {
        $items = [];
        foreach ($server->standaloneDockers as $destination) {
            $items[] = [
                'uuid' => $destination->uuid,
                'name' => $destination->name,
                'network' => $destination->network,
                'type' => 'standalone',
            ];
        }
        foreach ($server->swarmDockers as $destination) {
            $items[] = [
                'uuid' => $destination->uuid,
                'name' => $destination->name,
                'network' => $destination->network,
                'type' => 'swarm',
            ];
        }

        return $items;
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, Model>  $databases
     * @param  Collection<int, Service>  $services
     * @param  array<string, string>  $destinationUuidByKey
     * @return array<string, mixed>
     */
    private function exportProject(
        Project $project,
        Server $server,
        array $destinationUuidByKey,
        Collection $applications,
        Collection $databases,
        Collection $services,
    ): array {
        $environments = $project->environments()->orderBy('name')->get();

        return [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'description' => $project->description,
            'shared_environment_variables' => $this->exportSharedEnvVars(
                SharedEnvironmentVariable::query()
                    ->where('type', 'project')
                    ->where('project_id', $project->id)
                    ->get()
            ),
            'environments' => $environments->map(function (Environment $environment) use ($destinationUuidByKey, $applications, $databases, $services) {
                $envApps = $applications->where('environment_id', $environment->id)->values();
                $envDbs = $databases->where('environment_id', $environment->id)->values();
                $envServices = $services->where('environment_id', $environment->id)->values();

                return [
                    'uuid' => $environment->uuid,
                    'name' => $environment->name,
                    'description' => $environment->description,
                    'shared_environment_variables' => $this->exportSharedEnvVars(
                        SharedEnvironmentVariable::query()
                            ->where('type', 'environment')
                            ->where('environment_id', $environment->id)
                            ->get()
                    ),
                    'applications' => $envApps->map(fn (Application $app) => $this->exportApplication($app, $destinationUuidByKey))->all(),
                    'databases' => $envDbs->map(fn (Model $db) => $this->exportDatabase($db, $destinationUuidByKey))->all(),
                    'services' => $envServices->map(fn (Service $service) => $this->exportService($service, $destinationUuidByKey))->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  array<string, string>  $destinationUuidByKey
     * @return array<string, mixed>
     */
    private function exportApplication(Application $application, array $destinationUuidByKey): array
    {
        $application->loadMissing([
            'settings',
            'environment_variables',
            'environment_variables_preview',
            'persistentStorages',
            'fileStorages',
            'scheduled_tasks',
            'tags',
            'previews.persistentStorages',
            'source',
            'private_key',
        ]);

        $columns = Schema::getColumnListing($application->getTable());
        $attrs = [];
        foreach ($application->getFillable() as $field) {
            if (in_array($field, self::SKIP_APPLICATION_ATTRS, true)) {
                continue;
            }
            if (! in_array($field, $columns, true)) {
                continue;
            }
            $attrs[$field] = $application->{$field};
        }

        // Ensure hidden encrypted fields are included as plaintext.
        foreach ([
            'http_basic_auth_password',
            'manual_webhook_secret_github',
            'manual_webhook_secret_gitlab',
            'manual_webhook_secret_bitbucket',
            'manual_webhook_secret_gitea',
            'dockerfile',
            'docker_compose',
            'docker_compose_raw',
            'custom_labels',
        ] as $hidden) {
            if (in_array($hidden, $columns, true)) {
                $attrs[$hidden] = $application->{$hidden};
            }
        }

        $settings = [];
        if ($application->settings) {
            foreach ($application->settings->getFillable() as $field) {
                if (in_array($field, ['application_id', 'id'], true)) {
                    continue;
                }
                $settings[$field] = $application->settings->{$field};
            }
        }

        $destinationKey = $application->destination_type.':'.$application->destination_id;

        $source = null;
        if ($application->source_type === GithubApp::class && $application->source) {
            $source = ['type' => 'github_app', 'uuid' => $application->source->uuid];
        } elseif ($application->source_type === GitlabApp::class && $application->source) {
            $source = ['type' => 'gitlab_app', 'uuid' => $application->source->uuid];
        }

        return [
            'type' => 'application',
            'uuid' => $application->uuid,
            'destination_uuid' => $destinationUuidByKey[$destinationKey] ?? null,
            'attributes' => $attrs,
            'settings' => $settings,
            'environment_variables' => $this->exportEnvVars($application->environment_variables()->get()),
            'environment_variables_preview' => $this->exportEnvVars($application->environment_variables_preview()->get()),
            'persistent_storages' => $this->exportPersistentStorages($application->persistentStorages()->get()),
            'file_storages' => $this->exportFileStorages($application->fileStorages()->get()),
            'scheduled_tasks' => $this->exportScheduledTasks($application->scheduled_tasks()->get()),
            'tags' => $this->exportTags($application->tags),
            'previews' => $this->exportPreviews($application->previews),
            'source' => $source,
            'private_key_uuid' => $application->private_key?->uuid,
            'had_git_source' => filled($application->source_id),
            'had_private_key' => filled($application->private_key_id),
        ];
    }

    /**
     * @param  array<string, string>  $destinationUuidByKey
     * @return array<string, mixed>
     */
    private function exportDatabase(Model $database, array $destinationUuidByKey): array
    {
        $with = ['environment_variables', 'persistentStorages', 'fileStorages'];
        if (method_exists($database, 'tags')) {
            $with[] = 'tags';
        }
        if (method_exists($database, 'scheduledBackups')) {
            $with[] = 'scheduledBackups.s3';
        }
        $database->loadMissing($with);

        $columns = Schema::getColumnListing($database->getTable());
        $attrs = [];
        foreach ($database->getFillable() as $field) {
            if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at', 'environment_id', 'destination_id', 'destination_type'], true)) {
                continue;
            }
            if (! in_array($field, $columns, true)) {
                continue;
            }
            $attrs[$field] = $database->{$field};
        }

        // Secrets are often $hidden and sometimes omitted from $fillable (e.g. redis_password).
        foreach ($columns as $column) {
            if (array_key_exists($column, $attrs)) {
                continue;
            }
            if (str_contains($column, 'password') || str_ends_with($column, '_password')) {
                $attrs[$column] = $database->{$column};
            }
        }

        $destinationKey = $database->destination_type.':'.$database->destination_id;

        return [
            'type' => class_basename($database),
            'model' => $database::class,
            'uuid' => $database->uuid,
            'destination_uuid' => $destinationUuidByKey[$destinationKey] ?? null,
            'attributes' => $attrs,
            'environment_variables' => method_exists($database, 'environment_variables')
                ? $this->exportEnvVars($database->environment_variables()->get())
                : [],
            'persistent_storages' => method_exists($database, 'persistentStorages')
                ? $this->exportPersistentStorages($database->persistentStorages()->get())
                : [],
            'file_storages' => method_exists($database, 'fileStorages')
                ? $this->exportFileStorages($database->fileStorages()->get())
                : [],
            'tags' => method_exists($database, 'tags')
                ? $this->exportTags($database->tags)
                : [],
            'scheduled_backups' => method_exists($database, 'scheduledBackups')
                ? $this->exportScheduledBackups($database->scheduledBackups)
                : [],
        ];
    }

    /**
     * @param  array<string, string>  $destinationUuidByKey
     * @return array<string, mixed>
     */
    private function exportService(Service $service, array $destinationUuidByKey): array
    {
        $service->loadMissing([
            'environment_variables',
            'applications.environment_variables',
            'applications.persistentStorages',
            'applications.fileStorages',
            'databases.persistentStorages',
            'databases.fileStorages',
            'databases.scheduledBackups.s3',
            'scheduled_tasks',
            'tags',
        ]);

        $columns = Schema::getColumnListing($service->getTable());
        $attrs = [];
        foreach ($service->getFillable() as $field) {
            if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at', 'environment_id', 'destination_id', 'destination_type', 'server_id'], true)) {
                continue;
            }
            if (! in_array($field, $columns, true)) {
                continue;
            }
            $attrs[$field] = $service->{$field};
        }

        // Hidden compose fields
        foreach (['docker_compose', 'docker_compose_raw'] as $hidden) {
            if (in_array($hidden, $columns, true)) {
                $attrs[$hidden] = $service->{$hidden};
            }
        }

        $destinationKey = $service->destination_type.':'.$service->destination_id;

        return [
            'type' => 'service',
            'uuid' => $service->uuid,
            'destination_uuid' => $destinationUuidByKey[$destinationKey] ?? null,
            'attributes' => $attrs,
            'environment_variables' => $this->exportEnvVars($service->environment_variables()->get()),
            'scheduled_tasks' => $this->exportScheduledTasks($service->scheduled_tasks()->get()),
            'tags' => $this->exportTags($service->tags),
            'applications' => $service->applications
                ->map(fn ($app) => $this->exportServiceApplication($app))
                ->values()
                ->all(),
            'databases' => $service->databases
                ->map(fn ($db) => $this->exportServiceDatabase($db))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportServiceApplication(Model $app): array
    {
        $columns = Schema::getColumnListing($app->getTable());
        $attrs = [];
        foreach ($app->getFillable() as $field) {
            if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at', 'service_id'], true)) {
                continue;
            }
            if (! in_array($field, $columns, true)) {
                continue;
            }
            $attrs[$field] = $app->{$field};
        }

        return [
            'uuid' => $app->uuid,
            'attributes' => $attrs,
            // Flat fields kept for older bundles / readability
            'name' => $app->name,
            'fqdn' => $app->fqdn,
            'image' => $app->image ?? null,
            'ports' => $app->ports ?? null,
            'exposes' => $app->exposes ?? null,
            'exclude_from_status' => (bool) ($app->exclude_from_status ?? false),
            'required_fqdn' => (bool) ($app->required_fqdn ?? false),
            'human_name' => $app->human_name ?? null,
            'description' => $app->description ?? null,
            'is_log_drain_enabled' => (bool) ($app->is_log_drain_enabled ?? false),
            'is_include_timestamps' => (bool) ($app->is_include_timestamps ?? false),
            'is_gzip_enabled' => (bool) ($app->is_gzip_enabled ?? true),
            'is_stripprefix_enabled' => (bool) ($app->is_stripprefix_enabled ?? true),
            'environment_variables' => method_exists($app, 'environment_variables')
                ? $this->exportEnvVars($app->environment_variables()->get())
                : [],
            'persistent_storages' => method_exists($app, 'persistentStorages')
                ? $this->exportPersistentStorages($app->persistentStorages)
                : [],
            'file_storages' => method_exists($app, 'fileStorages')
                ? $this->exportFileStorages($app->fileStorages)
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportServiceDatabase(Model $db): array
    {
        $columns = Schema::getColumnListing($db->getTable());
        $attrs = [];
        foreach ($db->getFillable() as $field) {
            if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at', 'service_id'], true)) {
                continue;
            }
            if (! in_array($field, $columns, true)) {
                continue;
            }
            $attrs[$field] = $db->{$field};
        }

        return [
            'uuid' => $db->uuid,
            'attributes' => $attrs,
            'name' => $db->name,
            'human_name' => $db->human_name ?? null,
            'description' => $db->description ?? null,
            'image' => $db->image ?? null,
            'ports' => $db->ports ?? null,
            'exposes' => $db->exposes ?? null,
            'exclude_from_status' => (bool) ($db->exclude_from_status ?? false),
            'is_log_drain_enabled' => (bool) ($db->is_log_drain_enabled ?? false),
            'is_include_timestamps' => (bool) ($db->is_include_timestamps ?? false),
            'is_gzip_enabled' => (bool) ($db->is_gzip_enabled ?? true),
            'is_stripprefix_enabled' => (bool) ($db->is_stripprefix_enabled ?? true),
            'public_port' => $db->public_port ?? null,
            'public_port_timeout' => $db->public_port_timeout ?? null,
            'is_public' => (bool) ($db->is_public ?? false),
            'custom_type' => $db->custom_type ?? null,
            'persistent_storages' => method_exists($db, 'persistentStorages')
                ? $this->exportPersistentStorages($db->persistentStorages)
                : [],
            'file_storages' => method_exists($db, 'fileStorages')
                ? $this->exportFileStorages($db->fileStorages)
                : [],
            'scheduled_backups' => method_exists($db, 'scheduledBackups')
                ? $this->exportScheduledBackups($db->scheduledBackups)
                : [],
        ];
    }

    /**
     * Multi-server / multi-destination applications cannot be transferred safely:
     * the bundle is per-server and additional destinations may live on other hosts.
     *
     * @param  Collection<int, Application>  $applications
     */
    private function assertNoAdditionalDestinations(Server $server, Collection $applications): void
    {
        $involvesThisServer = DB::table('additional_destinations')
            ->where('server_id', $server->id)
            ->exists();

        $appIds = $applications->pluck('id')->filter()->all();
        $involvesTheseApps = $appIds !== [] && DB::table('additional_destinations')
            ->whereIn('application_id', $appIds)
            ->exists();

        if ($involvesThisServer || $involvesTheseApps) {
            throw new RuntimeException(
                'This server cannot be transferred because one or more applications use additional destinations (multi-server / multi-destination deploy). '
                .'Remove all additional destinations from those applications, then retry the export.'
            );
        }
    }

    /**
     * @param  Collection<int, SharedEnvironmentVariable>|iterable<int, SharedEnvironmentVariable>  $variables
     * @return list<array<string, mixed>>
     */
    private function exportSharedEnvVars(iterable $variables): array
    {
        $out = [];
        foreach ($variables as $variable) {
            // Skip auto COOLIFY_SERVER_* — recreated on server create.
            if (in_array($variable->key, ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'], true)) {
                continue;
            }
            $out[] = [
                'key' => $variable->key,
                'value' => $variable->value,
                'is_multiline' => (bool) $variable->is_multiline,
                'is_literal' => (bool) $variable->is_literal,
                'is_shown_once' => (bool) $variable->is_shown_once,
                'comment' => $variable->comment,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, EnvironmentVariable>|iterable<int, EnvironmentVariable>  $variables
     * @return list<array<string, mixed>>
     */
    private function exportEnvVars(iterable $variables): array
    {
        $out = [];
        foreach ($variables as $variable) {
            $out[] = [
                'uuid' => $variable->uuid,
                'key' => $variable->key,
                'value' => $variable->value,
                'is_literal' => (bool) $variable->is_literal,
                'is_multiline' => (bool) $variable->is_multiline,
                'is_preview' => (bool) $variable->is_preview,
                'is_runtime' => (bool) $variable->is_runtime,
                'is_buildtime' => (bool) $variable->is_buildtime,
                'is_shown_once' => (bool) $variable->is_shown_once,
                'is_shared' => (bool) $variable->is_shared,
                'is_required' => (bool) ($variable->is_required ?? false),
                'comment' => $variable->comment,
                'order' => $variable->order,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, LocalPersistentVolume>|iterable<int, LocalPersistentVolume>  $volumes
     * @return list<array<string, mixed>>
     */
    private function exportPersistentStorages(iterable $volumes): array
    {
        $out = [];
        foreach ($volumes as $volume) {
            $out[] = [
                'uuid' => $volume->uuid,
                'name' => $volume->name,
                'mount_path' => $volume->mount_path,
                'host_path' => $volume->host_path,
                'is_preview_suffix_enabled' => (bool) ($volume->is_preview_suffix_enabled ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, LocalFileVolume>|iterable<int, LocalFileVolume>  $storages
     * @return list<array<string, mixed>>
     */
    private function exportFileStorages(iterable $storages): array
    {
        $out = [];
        foreach ($storages as $storage) {
            $out[] = [
                'uuid' => $storage->uuid,
                'fs_path' => $storage->fs_path,
                'mount_path' => $storage->mount_path,
                'content' => $storage->content,
                'is_directory' => (bool) $storage->is_directory,
                'is_host_file' => (bool) $storage->is_host_file,
                'chown' => $storage->chown,
                'chmod' => $storage->chmod,
                'is_based_on_git' => (bool) ($storage->is_based_on_git ?? false),
                'is_preview_suffix_enabled' => (bool) ($storage->is_preview_suffix_enabled ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, ScheduledTask>|iterable<int, ScheduledTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function exportScheduledTasks(iterable $tasks): array
    {
        $out = [];
        foreach ($tasks as $task) {
            $out[] = [
                'uuid' => $task->uuid,
                'name' => $task->name,
                'command' => $task->command,
                'frequency' => $task->frequency,
                'container' => $task->container,
                'timeout' => $task->timeout,
                'enabled' => (bool) ($task->enabled ?? true),
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Tag>|iterable<int, Tag>  $tags
     * @return list<array{uuid: string, name: string}>
     */
    private function exportTags(iterable $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $out[] = [
                'uuid' => $tag->uuid,
                'name' => $tag->name,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, ScheduledDatabaseBackup>|iterable<int, ScheduledDatabaseBackup>  $backups
     * @return list<array<string, mixed>>
     */
    private function exportScheduledBackups(iterable $backups): array
    {
        $out = [];
        foreach ($backups as $backup) {
            $out[] = [
                'uuid' => $backup->uuid,
                'description' => $backup->description,
                'enabled' => (bool) $backup->enabled,
                'save_s3' => (bool) $backup->save_s3,
                'frequency' => $backup->frequency,
                'databases_to_backup' => $backup->databases_to_backup,
                'dump_all' => (bool) ($backup->dump_all ?? false),
                'database_backup_retention_amount_locally' => $backup->database_backup_retention_amount_locally,
                'database_backup_retention_days_locally' => $backup->database_backup_retention_days_locally,
                'database_backup_retention_max_storage_locally' => $backup->database_backup_retention_max_storage_locally,
                'database_backup_retention_amount_s3' => $backup->database_backup_retention_amount_s3,
                'database_backup_retention_days_s3' => $backup->database_backup_retention_days_s3,
                'database_backup_retention_max_storage_s3' => $backup->database_backup_retention_max_storage_s3,
                'timeout' => $backup->timeout,
                'disable_local_backup' => (bool) ($backup->disable_local_backup ?? false),
                's3_storage_uuid' => $backup->s3?->uuid,
                'had_s3_storage' => filled($backup->s3_storage_id),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportGithubApp(GithubApp $app): array
    {
        return [
            'uuid' => $app->uuid,
            'name' => $app->name,
            'organization' => $app->organization,
            'api_url' => $app->api_url,
            'html_url' => $app->html_url,
            'custom_user' => $app->custom_user,
            'custom_port' => $app->custom_port,
            'app_id' => $app->app_id,
            'installation_id' => $app->installation_id,
            'client_id' => $app->client_id,
            'client_secret' => $app->client_secret,
            'webhook_secret' => $app->webhook_secret,
            'is_system_wide' => false,
            'is_public' => (bool) $app->is_public,
            'contents' => $app->contents,
            'metadata' => $app->metadata,
            'pull_requests' => $app->pull_requests,
            'administration' => $app->administration,
            'private_key_uuid' => $app->privateKey?->uuid,
            'webhook_url_hint' => '/webhooks/source/github/events',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportGitlabApp(GitlabApp $app): array
    {
        return [
            'uuid' => $app->uuid,
            'name' => $app->name,
            'organization' => $app->organization,
            'api_url' => $app->api_url,
            'html_url' => $app->html_url,
            'custom_port' => $app->custom_port,
            'custom_user' => $app->custom_user,
            'is_system_wide' => false,
            'is_public' => (bool) $app->is_public,
            'app_id' => $app->app_id,
            'app_secret' => $app->app_secret,
            'oauth_id' => $app->oauth_id,
            'client_id' => $app->client_id,
            'client_secret' => $app->client_secret,
            'access_token' => $app->access_token,
            'refresh_token' => $app->refresh_token,
            'expires_at' => $app->expires_at,
            'redirect_uri' => $app->redirect_uri,
            'group_name' => $app->group_name,
            'public_key' => $app->public_key,
            'webhook_token' => $app->webhook_token,
            'deploy_key_id' => $app->deploy_key_id,
            'private_key_uuid' => $app->privateKey?->uuid,
            'webhook_url_hint' => '/webhooks/source/gitlab/events',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportS3Storage(S3Storage $storage): array
    {
        return [
            'uuid' => $storage->uuid,
            'name' => $storage->name,
            'description' => $storage->description,
            'region' => $storage->region,
            'key' => $storage->key,
            'secret' => $storage->secret,
            'bucket' => $storage->bucket,
            'endpoint' => $storage->endpoint,
            'is_usable' => (bool) $storage->is_usable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportCloudProviderToken(CloudProviderToken $token): array
    {
        return [
            'uuid' => $token->uuid,
            'provider' => $token->provider,
            'token' => $token->token,
            'name' => $token->name,
            'description' => $token->description,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportSslCertificates(Server $server): array
    {
        $out = [];
        foreach ($server->sslCertificates as $cert) {
            $resourceUuid = null;
            $resourceKind = null;
            if ($cert->resource_type && $cert->resource_id) {
                $resource = $cert->resource_type::find($cert->resource_id);
                if ($resource && isset($resource->uuid)) {
                    $resourceUuid = $resource->uuid;
                    $resourceKind = match (true) {
                        $resource instanceof Application => 'application',
                        $resource instanceof Service => 'service',
                        default => class_basename($resource),
                    };
                }
            }

            $out[] = [
                'ssl_certificate' => $cert->ssl_certificate,
                'ssl_private_key' => $cert->ssl_private_key,
                'configuration_dir' => $cert->configuration_dir,
                'mount_path' => $cert->mount_path,
                'common_name' => $cert->common_name,
                'subject_alternative_names' => $cert->subject_alternative_names,
                'valid_until' => optional($cert->valid_until)?->toIso8601String(),
                'is_ca_certificate' => (bool) $cert->is_ca_certificate,
                'resource_kind' => $resourceKind,
                'resource_uuid' => $resourceUuid,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, ApplicationPreview>|iterable<int, ApplicationPreview>  $previews
     * @return list<array<string, mixed>>
     */
    private function exportPreviews(iterable $previews): array
    {
        $out = [];
        foreach ($previews as $preview) {
            $out[] = [
                'uuid' => $preview->uuid,
                'pull_request_id' => $preview->pull_request_id,
                'pull_request_html_url' => $preview->pull_request_html_url,
                'pull_request_issue_comment_id' => $preview->pull_request_issue_comment_id,
                'fqdn' => $preview->fqdn,
                'status' => $preview->status,
                'git_type' => $preview->git_type,
                'docker_compose_domains' => $preview->docker_compose_domains,
                'docker_registry_image_tag' => $preview->docker_registry_image_tag,
                'persistent_storages' => method_exists($preview, 'persistentStorages')
                    ? $this->exportPersistentStorages($preview->persistentStorages)
                    : [],
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, Model>  $databases
     * @param  Collection<int, Service>  $services
     * @return list<array<string, mixed>>
     */
    private function exportVolumeBackups(Collection $applications, Collection $databases, Collection $services): array
    {
        $out = [];
        $seen = [];

        $collectFrom = function ($resource) use (&$out, &$seen) {
            $storages = collect();
            if (method_exists($resource, 'persistentStorages')) {
                $storages = $storages->merge($resource->persistentStorages);
            }
            if (method_exists($resource, 'fileStorages')) {
                $storages = $storages->merge($resource->fileStorages);
            }
            foreach ($storages as $storage) {
                if (! method_exists($storage, 'scheduledBackups')) {
                    continue;
                }
                foreach ($storage->scheduledBackups as $backup) {
                    if (isset($seen[$backup->id])) {
                        continue;
                    }
                    $seen[$backup->id] = true;
                    $kind = $storage instanceof LocalFileVolume ? 'file_volume' : 'persistent_volume';
                    $out[] = [
                        'uuid' => $backup->uuid,
                        'backupable_kind' => $kind,
                        'backupable_uuid' => $storage->uuid,
                        'frequency' => $backup->frequency,
                        'enabled' => (bool) $backup->enabled,
                        'save_s3' => (bool) $backup->save_s3,
                        'disable_local_backup' => (bool) $backup->disable_local_backup,
                        'stop_during_backup' => (bool) ($backup->stop_during_backup ?? false),
                        'retention_amount_locally' => $backup->retention_amount_locally,
                        'retention_days_locally' => $backup->retention_days_locally,
                        'retention_max_storage_locally' => $backup->retention_max_storage_locally,
                        'retention_amount_s3' => $backup->retention_amount_s3,
                        'retention_days_s3' => $backup->retention_days_s3,
                        'retention_max_storage_s3' => $backup->retention_max_storage_s3,
                        'timeout' => $backup->timeout,
                        's3_storage_uuid' => $backup->s3?->uuid,
                        'had_s3_storage' => filled($backup->s3_storage_id),
                    ];
                }
            }
        };

        foreach ($applications as $application) {
            $application->loadMissing([
                'persistentStorages.scheduledBackups.s3',
                'fileStorages.scheduledBackups.s3',
                'previews.persistentStorages.scheduledBackups.s3',
            ]);
            $collectFrom($application);
            foreach ($application->previews as $preview) {
                $collectFrom($preview);
            }
        }
        foreach ($databases as $database) {
            if (method_exists($database, 'loadMissing')) {
                $database->loadMissing([
                    'persistentStorages.scheduledBackups.s3',
                    'fileStorages.scheduledBackups.s3',
                ]);
            }
            $collectFrom($database);
        }
        foreach ($services as $service) {
            $service->loadMissing([
                'applications.persistentStorages.scheduledBackups.s3',
                'applications.fileStorages.scheduledBackups.s3',
                'databases.persistentStorages.scheduledBackups.s3',
                'databases.fileStorages.scheduledBackups.s3',
            ]);
            foreach ($service->applications as $serviceApp) {
                $collectFrom($serviceApp);
            }
            foreach ($service->databases as $serviceDb) {
                $collectFrom($serviceDb);
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, Model>  $databases
     * @param  Collection<int, Service>  $services
     * @return list<int>
     */
    private function collectProjectIds(Collection $applications, Collection $databases, Collection $services): array
    {
        $environmentIds = $applications->pluck('environment_id')
            ->merge($databases->pluck('environment_id'))
            ->merge($services->pluck('environment_id'))
            ->filter()
            ->unique()
            ->values();

        if ($environmentIds->isEmpty()) {
            return [];
        }

        return Environment::query()
            ->whereIn('id', $environmentIds)
            ->pluck('project_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function destinationUuidMap(Server $server): array
    {
        $map = [];
        foreach ($server->standaloneDockers as $destination) {
            $map[StandaloneDocker::class.':'.$destination->id] = $destination->uuid;
        }
        foreach ($server->swarmDockers as $destination) {
            $map[SwarmDocker::class.':'.$destination->id] = $destination->uuid;
        }

        return $map;
    }
}
