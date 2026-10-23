<?php

namespace App\Services\ServerTransfer;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\CloudProviderToken;
use App\Models\DnsProviderZone;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GitlabApp;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ManagedDnsRecord;
use App\Models\ManagedDnsRecordReference;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Tag;
use App\Services\ProjectIconStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ServerTransferExporter
{
    /**
     * Larger project icons are not put in the bundle. Icons that Coolify stores are small JPEG files.
     */
    public const MAX_PROJECT_ICON_BYTES = 2 * 1024 * 1024;

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
        // Exported as cloudflare_tunnel_integration_token_uuid.
        'cloudflare_tunnel_integration_token_id',
        // The last patch check of the source; the target runs its own.
        'patch_check_result',
        'patch_check_error',
        'patch_checked_at',
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
        if ((int) $privateKey->team_id !== (int) $server->team_id) {
            throw new RuntimeException('The server private key does not belong to the server team. Select a private key of this team first.');
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
        $projects = Project::query()->where('team_id', $server->team_id)->whereIn('id', $projectIds)->orderBy('name')->get();
        if ($projects->count() !== collect($projectIds)->unique()->count()) {
            throw new RuntimeException('Some resources on this server belong to a project of another team. Move them to a project of this team first.');
        }

        $destinationUuidByKey = $this->destinationUuidMap($server);
        $runnerConfig = $server->githubRunnerConfig()->with('githubApp')->first();
        $dependencies = $this->collectDependencies($server, $applications, $databases, $services, $runnerConfig);

        $sourceInstanceUrl = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');
        $managedDns = $this->collectManagedDnsRecords($server, $applications, $services);
        $integrationTokens = $this->collectIntegrationTokens($server, $applications->concat($databases)->concat($services), $managedDns['records']);

        $serverSharedEnvVars = $this->exportSharedEnvVars(
            SharedEnvironmentVariable::query()
                ->where('type', 'server')
                ->where('server_id', $server->id)
                ->get()
        );
        $projectWarnings = [];
        $leftOutLinks = 0;
        $projectPayloads = $projects->map(function (Project $project) use ($destinationUuidByKey, $applications, $databases, $services, &$projectWarnings, &$leftOutLinks) {
            return $this->exportProject($project, $destinationUuidByKey, $applications, $databases, $services, $projectWarnings, $leftOutLinks);
        })->values()->all();

        $warnings = $this->buildWarnings($applications, $dependencies);
        $warnings = array_merge($warnings, $managedDns['warnings'], $projectWarnings);
        if ($leftOutLinks > 0) {
            $warnings[] = $leftOutLinks.' '.Str::plural('link', $leftOutLinks).' between resources on the environment canvas '
                .($leftOutLinks === 1 ? 'is' : 'are').' not transferred, because one of the linked resources stays on this instance.';
        }

        $payload = ServerTransferBundle::wrap([
            'source_instance' => [
                'url' => $sourceInstanceUrl,
                'name' => config('app.name'),
            ],
            'warnings' => $warnings,
            // Manual steps after the transfer. The source replaces {target_url} with the URL of the target.
            'post_transfer_checklist' => $this->postTransferChecklist($dependencies, $runnerConfig, $applications),
            // Back-compat: single server key
            'private_key' => $this->exportPrivateKey($privateKey),
            // All keys needed by server, apps, and git sources
            'private_keys' => $dependencies['private_keys']->map(fn (PrivateKey $key) => $this->exportPrivateKey($key))->values()->all(),
            'github_apps' => $dependencies['github_apps']->map(fn (GithubApp $app) => $this->exportGithubApp($app))->values()->all(),
            'gitlab_apps' => $dependencies['gitlab_apps']->map(fn (GitlabApp $app) => $this->exportGitlabApp($app))->values()->all(),
            'gitea_apps' => $dependencies['gitea_apps']->map(fn (GiteaApp $app) => $this->exportGiteaApp($app))->values()->all(),
            's3_storages' => $dependencies['s3_storages']->map(fn (S3Storage $storage) => $this->exportS3Storage($storage))->values()->all(),
            'cloud_provider_tokens' => $dependencies['cloud_provider_tokens']->map(fn (CloudProviderToken $token) => $this->exportCloudProviderToken($token))->values()->all(),
            'integration_tokens' => $integrationTokens->map(fn (IntegrationToken $token) => $this->exportIntegrationToken($token))->values()->all(),
            'dns_zones' => $managedDns['records']->pluck('zone')->unique('id')->map(fn (DnsProviderZone $zone) => $this->exportDnsZone($zone))->values()->all(),
            'managed_dns_records' => $managedDns['records']->map(fn (ManagedDnsRecord $record) => $this->exportManagedDnsRecord($record, $managedDns['resource_keys']))->values()->all(),
            'ssl_certificates' => $this->exportSslCertificates($server),
            'volume_backups' => $this->exportVolumeBackups($applications, $databases, $services),
            'server' => $this->exportServer($server),
            'destinations' => $this->exportDestinations($server),
            'github_runner_config' => $runnerConfig ? $this->exportGithubRunnerConfig($runnerConfig) : null,
            'shared_environment_variables' => [
                'server' => $serverSharedEnvVars,
                'team' => $this->exportReferencedTeamSharedEnvVars($server->team_id, $serverSharedEnvVars, $projectPayloads),
            ],
            'projects' => $projectPayloads,
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
     *     gitea_apps: Collection<int, GiteaApp>,
     *     s3_storages: Collection<int, S3Storage>,
     *     cloud_provider_tokens: Collection<int, CloudProviderToken>
     * }
     */
    private function collectDependencies(Server $server, Collection $applications, Collection $databases, Collection $services, ?GithubRunnerConfig $runnerConfig): array
    {
        $teamId = $server->team_id;
        $privateKeys = collect([$server->privateKey])->filter();
        $githubApps = collect();
        $gitlabApps = collect();
        $giteaApps = collect();
        $s3Ids = collect();

        // System-wide GitHub Apps are instance-owned; do not copy them. The application source and the runner
        // config still record the App uuid, so the import can re-link to a system-wide App of the target.
        $collectGithubApp = function (int|string $id) use ($teamId, $githubApps, $privateKeys): void {
            $gh = $this->findGitSource(GithubApp::class, $id, $teamId);
            if ($gh && ! $gh->is_system_wide) {
                $githubApps->push($gh);
                if ($gh->private_key_id) {
                    $privateKeys->push($this->findTeamRecord(PrivateKey::class, $gh->private_key_id, $teamId, 'private key'));
                }
            }
        };

        if ($runnerConfig) {
            $collectGithubApp($runnerConfig->github_app_id);
        }

        foreach ($applications as $application) {
            if ($application->private_key_id) {
                $privateKeys->push($this->findTeamRecord(PrivateKey::class, $application->private_key_id, $teamId, 'private key'));
            }

            if ($application->source_type === GithubApp::class && $application->source_id !== null) {
                $collectGithubApp($application->source_id);
            }

            if ($application->source_type === GitlabApp::class && $application->source_id !== null) {
                $gl = $this->findGitSource(GitlabApp::class, $application->source_id, $teamId);
                // System-wide GitLab Apps are instance-owned; re-link by UUID on import.
                if ($gl && ! $gl->is_system_wide) {
                    $gitlabApps->push($gl);
                    if ($gl->private_key_id) {
                        $privateKeys->push($this->findTeamRecord(PrivateKey::class, $gl->private_key_id, $teamId, 'private key'));
                    }
                }
            }

            if ($application->source_type === GiteaApp::class && $application->source_id !== null) {
                $gt = $this->findGitSource(GiteaApp::class, $application->source_id, $teamId);
                // System-wide Gitea Apps are instance-owned; re-link by UUID on import.
                if ($gt && ! $gt->is_system_wide) {
                    $giteaApps->push($gt);
                    if ($gt->private_key_id) {
                        $privateKeys->push($this->findTeamRecord(PrivateKey::class, $gt->private_key_id, $teamId, 'private key'));
                    }
                }
            }
        }

        $collectBackupS3 = function (ScheduledDatabaseBackup|ScheduledVolumeBackup $backup) use (&$s3Ids): void {
            $s3Ids->push($backup->s3_storage_id, ...$backup->s3Storages()->pluck('s3_storages.id')->all());
        };

        $collectVolumeS3 = function ($resource) use ($collectBackupS3): void {
            if (method_exists($resource, 'persistentStorages')) {
                foreach ($resource->persistentStorages as $volume) {
                    foreach ($volume->scheduledBackups as $vb) {
                        $collectBackupS3($vb);
                    }
                }
            }
            if (method_exists($resource, 'fileStorages')) {
                foreach ($resource->fileStorages as $file) {
                    foreach ($file->scheduledBackups as $vb) {
                        $collectBackupS3($vb);
                    }
                }
            }
        };

        foreach ($databases as $database) {
            if (method_exists($database, 'scheduledBackups')) {
                foreach ($database->scheduledBackups as $backup) {
                    $collectBackupS3($backup);
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
                    $collectBackupS3($backup);
                }
                $collectVolumeS3($serviceDb);
            }
        }

        $cloudTokens = collect();
        if ($server->cloud_provider_token_id) {
            $cloudTokens->push($this->findTeamRecord(CloudProviderToken::class, $server->cloud_provider_token_id, $teamId, 'cloud provider token'));
        }

        return [
            'private_keys' => $privateKeys->unique('id')->values(),
            'github_apps' => $githubApps->unique('id')->values(),
            'gitlab_apps' => $gitlabApps->unique('id')->values(),
            'gitea_apps' => $giteaApps->unique('id')->values(),
            's3_storages' => $this->findTeamS3Storages($s3Ids->unique()->filter()->values()->all(), $teamId),
            'cloud_provider_tokens' => $cloudTokens->unique('id')->values(),
        ];
    }

    /**
     * Load a credential record that must belong to the server team. A record of another team is
     * never exported, so a broken reference stops the export instead of leaking its secrets.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @return T
     */
    private function findTeamRecord(string $modelClass, int|string $id, int $teamId, string $label): Model
    {
        $record = $modelClass::query()->where('team_id', $teamId)->find($id);
        if (! $record) {
            throw new RuntimeException("A {$label} used on this server does not belong to the server team. Select a {$label} of this team first.");
        }

        return $record;
    }

    /**
     * System-wide git sources are returned as they are (they are only linked by UUID, never exported).
     *
     * @param  class-string<GithubApp|GitlabApp|GiteaApp>  $modelClass
     */
    private function findGitSource(string $modelClass, int|string $id, int $teamId): GithubApp|GitlabApp|GiteaApp|null
    {
        $source = $modelClass::find($id);
        if ($source && ! $source->is_system_wide && (int) $source->team_id !== $teamId) {
            throw new RuntimeException('A git source used on this server does not belong to the server team. Select a git source of this team first.');
        }

        return $source;
    }

    /**
     * @param  list<int|string>  $ids
     * @return Collection<int, S3Storage>
     */
    private function findTeamS3Storages(array $ids, int $teamId): Collection
    {
        $storages = S3Storage::query()->where('team_id', $teamId)->whereIn('id', $ids)->get();
        if ($storages->count() !== count($ids)) {
            throw new RuntimeException('An S3 storage used by a backup on this server does not belong to the server team. Select an S3 storage of this team first.');
        }

        return $storages;
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  array<string, mixed>  $dependencies
     * @return list<string>
     */
    private function buildWarnings(Collection $applications, array $dependencies): array
    {
        $warnings = [];

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

        $usesSystemWideGitea = $applications->contains(function (Application $app) {
            if ($app->source_type !== GiteaApp::class || $app->source_id === null) {
                return false;
            }
            $gt = GiteaApp::find($app->source_id);

            return $gt?->is_system_wide === true;
        });
        if ($usesSystemWideGitea) {
            $warnings[] = 'Some applications use a system-wide Gitea App, which is not exported. On import, Coolify will re-link to a matching system-wide Gitea App on the target instance (by UUID) if one exists.';
        }

        if ($dependencies['s3_storages']->isNotEmpty()) {
            $warnings[] = 'S3 storage credentials were exported. Confirm endpoint access from the target instance and that bucket policies still apply.';
        }

        return $warnings;
    }

    /**
     * Managed DNS records that only exported resources use. A record that a resource staying on this instance
     * also uses is not exported: this instance keeps managing it.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, Service>  $services
     * @return array{
     *     records: Collection<int, ManagedDnsRecord>,
     *     resource_keys: array<string, array{kind: string, uuid: string}|null>,
     *     warnings: list<string>
     * }
     */
    private function collectManagedDnsRecords(Server $server, Collection $applications, Collection $services): array
    {
        // Keyed by "morph type:id". Soft-deleted previews are covered, but their references are not exported.
        // The server itself owns the DNS record of its Cloudflare tunnel.
        $resourceKeys = [$server->getMorphClass().':'.$server->id => ['kind' => 'server', 'uuid' => $server->uuid]];
        foreach ($applications as $application) {
            $resourceKeys[$application->getMorphClass().':'.$application->id] = ['kind' => 'application', 'uuid' => $application->uuid];
            foreach (ApplicationPreview::withTrashed()->where('application_id', $application->id)->get() as $preview) {
                $resourceKeys[$preview->getMorphClass().':'.$preview->id] = $preview->trashed() ? null : ['kind' => 'preview', 'uuid' => $preview->uuid];
            }
        }
        foreach ($services as $service) {
            foreach ($service->applications as $serviceApplication) {
                $resourceKeys[$serviceApplication->getMorphClass().':'.$serviceApplication->id] = ['kind' => 'service_application', 'uuid' => $serviceApplication->uuid];
            }
        }
        $records = ManagedDnsRecord::query()
            ->whereHas('references', function ($query) use ($resourceKeys) {
                $query->where(function ($query) use ($resourceKeys) {
                    foreach (array_keys($resourceKeys) as $key) {
                        [$type, $id] = explode(':', $key, 2);
                        $query->orWhere(fn ($query) => $query->where('resource_type', $type)->where('resource_id', $id));
                    }
                });
            })
            ->with(['references', 'zone.integrationToken', 'integrationToken'])
            ->orderBy('name')
            ->get();

        $exported = collect();
        $warnings = [];
        foreach ($records as $record) {
            $teamId = (int) $server->team_id;
            if ((int) $record->team_id !== $teamId
                || (int) $record->integrationToken?->team_id !== $teamId
                || (int) $record->zone?->integrationToken?->team_id !== $teamId) {
                $warnings[] = "DNS record {$record->name} is not transferred, because its DNS provider token belongs to another team. The target instance does not manage it.";

                continue;
            }
            $sharedWithOtherResources = $record->references
                ->contains(fn (ManagedDnsRecordReference $reference) => ! array_key_exists($reference->resource_type.':'.$reference->resource_id, $resourceKeys));
            if ($sharedWithOtherResources) {
                $warnings[] = "DNS record {$record->name} stays managed by this instance, because a resource that is not transferred also uses it. The target instance does not update or delete it.";

                continue;
            }
            $hasLiveReference = $record->references
                ->contains(fn (ManagedDnsRecordReference $reference) => $resourceKeys[$reference->resource_type.':'.$reference->resource_id] !== null);
            if ($hasLiveReference) {
                $exported->push($record);
            }
        }

        return ['records' => $exported, 'resource_keys' => $resourceKeys, 'warnings' => $warnings];
    }

    /**
     * Integration tokens that the exported resources need: secret manager links, managed DNS records, and the
     * Cloudflare tunnel of the server.
     *
     * @param  Collection<int, Model>  $resources
     * @param  Collection<int, ManagedDnsRecord>  $dnsRecords
     * @return Collection<int, IntegrationToken>
     */
    private function collectIntegrationTokens(Server $server, Collection $resources, Collection $dnsRecords): Collection
    {
        $tokenIds = collect([$server->settings?->cloudflare_tunnel_integration_token_id]);
        foreach ($resources as $resource) {
            if (method_exists($resource, 'secretManagerLink')) {
                $tokenIds->push($resource->secretManagerLink()->value('integration_token_id'));
            }
        }
        foreach ($dnsRecords as $record) {
            $tokenIds->push($record->integration_token_id, $record->zone->integration_token_id);
        }

        return $tokenIds->filter()->unique()->values()
            ->map(fn (int|string $id) => $this->findTeamRecord(IntegrationToken::class, $id, (int) $server->team_id, 'integration token'));
    }

    /**
     * Manual steps after the transfer. {target_url} stands for the URL of the target instance.
     *
     * @param  array{github_apps: Collection<int, GithubApp>, gitlab_apps: Collection<int, GitlabApp>, gitea_apps: Collection<int, GiteaApp>}  $dependencies
     * @param  Collection<int, Application>  $applications
     * @return list<string>
     */
    private function postTransferChecklist(array $dependencies, ?GithubRunnerConfig $runnerConfig, Collection $applications): array
    {
        $steps = [];
        // A manual webhook is set in the repository itself, so its URL still points to this instance. Every
        // application has the secrets, so list the git applications that do not deploy through a private App.
        $manualWebhookApps = $applications
            ->filter(fn (Application $application) => $application->build_pack !== 'dockerimage' && filled($application->git_repository)
                && (! $application->source || data_get($application->source, 'is_public') === true))
            ->pluck('name');
        if ($manualWebhookApps->isNotEmpty()) {
            $steps[] = 'If a repository of these applications has a manual webhook to Coolify, change its URL to the target: '
                .$manualWebhookApps->implode(', ').'. Use {target_url}/webhooks/source/github/events/manual, '
                .'{target_url}/webhooks/source/gitlab/events/manual, {target_url}/webhooks/source/bitbucket/events/manual, '
                .'or {target_url}/webhooks/source/gitea/events/manual.';
        }
        foreach ($dependencies['github_apps'] as $githubApp) {
            $steps[] = "On GitHub, open the settings of GitHub App {$githubApp->name}. Set the Webhook URL to {target_url}/webhooks/source/github/events, "
                .'the Setup URL to {target_url}/webhooks/source/github/install, and the Callback URL to {target_url}/login/github/app.';
        }
        foreach ($dependencies['gitlab_apps'] as $gitlabApp) {
            $steps[] = "In GitLab, open Settings > Webhooks of every project that uses GitLab App {$gitlabApp->name}, and set the webhook URL to {target_url}/webhooks/source/gitlab/events.";
        }
        foreach ($dependencies['gitea_apps'] as $giteaApp) {
            $steps[] = "In Gitea, open the OAuth2 application of Gitea App {$giteaApp->name} and set the redirect URI to {target_url}/webhooks/source/gitea/redirect. "
                .'Then set the webhook URL of every repository that uses it to {target_url}/webhooks/source/gitea/events.';
        }
        if ($runnerConfig) {
            $steps[] = 'After the GitHub App sends webhooks to {target_url}, open this server there, check the runner requirements, and enable GitHub runners. '
                .'Enabling them stops runners that another Coolify instance started on this server.';
        }
        $steps[] = 'This instance keeps a copy of the SSH key of the server. On {target_url}, add a new SSH key to the server and select it there, '
            .'then remove the old public key from ~/.ssh/authorized_keys on the server.';

        return $steps;
    }

    /**
     * Team variables are exported only when an exported variable references them as {{team.KEY}}.
     *
     * @param  list<array<string, mixed>>  $serverVariables
     * @param  list<array<string, mixed>>  $projects
     * @return list<array<string, mixed>>
     */
    private function exportReferencedTeamSharedEnvVars(int $teamId, array $serverVariables, array $projects): array
    {
        $values = collect($serverVariables)->pluck('value');
        foreach ([
            '*.shared_environment_variables.*.value',
            '*.environments.*.shared_environment_variables.*.value',
            '*.environments.*.applications.*.environment_variables.*.value',
            '*.environments.*.applications.*.environment_variables_preview.*.value',
            '*.environments.*.databases.*.environment_variables.*.value',
            '*.environments.*.services.*.environment_variables.*.value',
            '*.environments.*.services.*.applications.*.environment_variables.*.value',
        ] as $path) {
            $values = $values->merge(data_get($projects, $path, []));
        }

        $keys = $values
            ->filter(fn ($value) => is_string($value))
            ->flatMap(function (string $value) {
                preg_match_all('/{{\s*team\.(.+?)\s*}}/', $value, $matches);

                return $matches[1];
            })
            ->unique()
            ->values();
        if ($keys->isEmpty()) {
            return [];
        }

        return $this->exportSharedEnvVars(
            SharedEnvironmentVariable::query()
                ->where('type', 'team')
                ->where('team_id', $teamId)
                ->whereIn('key', $keys)
                ->get()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function exportPrivateKey(PrivateKey $key): array
    {
        try {
            $material = $key->private_key;
        } catch (Throwable $e) {
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
            $settingsPayload['cloudflare_tunnel_integration_token_uuid'] = $settings->cloudflare_tunnel_integration_token_id
                ? IntegrationToken::query()->whereKey($settings->cloudflare_tunnel_integration_token_id)->value('uuid')
                : null;
        }

        return [
            'uuid' => $server->uuid,
            'name' => $server->name,
            'description' => $server->description,
            'ip' => (string) $server->ip,
            'port' => $server->port ?: 22,
            'user' => (string) $server->user,
            'proxy' => $server->proxy?->toArray() ?? [],
            'server_role' => $server->settings->effectiveServerRole()->value,
            'is_build_server' => $server->isBuildServer(),
            'cloud_provider_token_uuid' => $server->cloudProviderToken?->uuid,
            'hetzner_server_id' => $server->hetzner_server_id,
            'vultr_instance_id' => $server->vultr_instance_id,
            'digitalocean_droplet_id' => $server->digitalocean_droplet_id,
            'hostinger_virtual_machine_id' => $server->hostinger_virtual_machine_id,
            'proxy_error_page' => $server->proxy_error_page,
            'swarm_cluster' => $server->swarm_cluster,
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
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function exportProject(
        Project $project,
        array $destinationUuidByKey,
        Collection $applications,
        Collection $databases,
        Collection $services,
        array &$warnings,
        int &$leftOutLinks,
    ): array {
        // Only environments with a resource of this server. The other environments stay on this instance.
        $environmentIds = $applications->pluck('environment_id')
            ->merge($databases->pluck('environment_id'))
            ->merge($services->pluck('environment_id'))
            ->unique();
        $environments = $project->environments()->whereIn('id', $environmentIds)->orderBy('name')->get();

        return [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'description' => $project->description,
            'icon' => $this->exportProjectIcon($project, $warnings),
            'shared_environment_variables' => $this->exportSharedEnvVars(
                SharedEnvironmentVariable::query()
                    ->where('type', 'project')
                    ->where('project_id', $project->id)
                    ->get()
            ),
            'environments' => $environments->map(function (Environment $environment) use ($destinationUuidByKey, $applications, $databases, $services, &$leftOutLinks) {
                $envApps = $applications->where('environment_id', $environment->id)->values();
                $envDbs = $databases->where('environment_id', $environment->id)->values();
                $envServices = $services->where('environment_id', $environment->id)->values();

                return [
                    'uuid' => $environment->uuid,
                    'name' => $environment->name,
                    'description' => $environment->description,
                    // Keyed by resource uuid; only the positions of exported resources.
                    'canvas_positions' => collect($environment->canvas_positions ?? [])
                        ->only($envApps->pluck('uuid')->merge($envDbs->pluck('uuid'))->merge($envServices->pluck('uuid')))
                        ->all(),
                    'shared_environment_variables' => $this->exportSharedEnvVars(
                        SharedEnvironmentVariable::query()
                            ->where('type', 'environment')
                            ->where('environment_id', $environment->id)
                            ->get()
                    ),
                    'applications' => $envApps->map(fn (Application $app) => $this->exportApplication($app, $destinationUuidByKey))->all(),
                    'databases' => $envDbs->map(fn (Model $db) => $this->exportDatabase($db, $destinationUuidByKey))->all(),
                    'services' => $envServices->map(fn (Service $service) => $this->exportService($service, $destinationUuidByKey))->all(),
                    'resource_links' => $this->exportResourceLinks($environment, $envApps, $envDbs, $envServices, $leftOutLinks),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The icon file travels in the bundle; the target stores it in its own icon storage.
     *
     * @param  list<string>  $warnings
     * @return array{content_base64: string, extension: string, mime: string}|null
     */
    private function exportProjectIcon(Project $project, array &$warnings): ?array
    {
        if (blank($project->icon_path)) {
            return null;
        }

        try {
            $contents = app(ProjectIconStorageService::class)->projectContents($project);
        } catch (Throwable) {
            $contents = null;
        }
        if (! is_string($contents) || $contents === '') {
            $warnings[] = "The icon of project {$project->name} cannot be read, so it is not transferred. Upload it again on the target instance.";

            return null;
        }
        if (strlen($contents) > self::MAX_PROJECT_ICON_BYTES) {
            $warnings[] = "The icon of project {$project->name} is larger than 2 MB, so it is not transferred. Upload it again on the target instance.";

            return null;
        }

        return [
            'content_base64' => base64_encode($contents),
            'extension' => strtolower(pathinfo($project->icon_path, PATHINFO_EXTENSION)) ?: 'jpg',
            'mime' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream',
        ];
    }

    /**
     * Canvas links whose two resources are both exported. Each end is the kind and uuid of a resource of the
     * environment, the same keys that the import uses for its resource maps.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, Model>  $databases
     * @param  Collection<int, Service>  $services
     * @return list<array{uuid: string, label: string|null, source: array{kind: string, uuid: string}, target: array{kind: string, uuid: string}}>
     */
    private function exportResourceLinks(Environment $environment, Collection $applications, Collection $databases, Collection $services, int &$leftOut): array
    {
        $ends = collect();
        foreach (['application' => $applications, 'database' => $databases, 'service' => $services] as $kind => $resources) {
            foreach ($resources as $resource) {
                $ends->put($resource->getMorphClass().':'.$resource->getKey(), ['kind' => $kind, 'uuid' => $resource->uuid]);
            }
        }

        $out = [];
        foreach ($environment->resourceLinks()->orderBy('id')->get() as $link) {
            $source = $ends->get($link->source_type.':'.$link->source_id);
            $target = $ends->get($link->target_type.':'.$link->target_id);
            if (! $source || ! $target) {
                $leftOut++;

                continue;
            }
            $out[] = ['uuid' => $link->uuid, 'label' => $link->label, 'source' => $source, 'target' => $target];
        }

        return $out;
    }

    /**
     * The target imports the config disabled. Enabling it there removes the runner containers of the source.
     *
     * @return array<string, mixed>
     */
    private function exportGithubRunnerConfig(GithubRunnerConfig $config): array
    {
        return [
            'uuid' => $config->uuid,
            'github_app_uuid' => $config->githubApp?->uuid,
            'labels' => $config->labels ?? [],
            'is_enabled' => (bool) $config->is_enabled,
            'is_dedicated' => (bool) $config->is_dedicated,
            'allow_pull_requests' => (bool) $config->allow_pull_requests,
            'max_runners' => $config->max_runners,
            'docker_mode' => $config->docker_mode?->value,
            'runner_image' => $config->runner_image,
            'cpu_limit' => $config->cpu_limit,
            'memory_limit' => $config->memory_limit,
            'capacity_wait_timeout' => $config->capacity_wait_timeout,
            'idle_timeout' => $config->idle_timeout,
            'job_timeout' => $config->job_timeout,
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
            'is_maintenance_enabled',
            'maintenance_page',
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
            $source = ['type' => 'github_app', 'uuid' => $application->source->uuid, 'system_wide' => (bool) $application->source->is_system_wide];
        } elseif ($application->source_type === GitlabApp::class && $application->source) {
            $source = ['type' => 'gitlab_app', 'uuid' => $application->source->uuid, 'system_wide' => (bool) $application->source->is_system_wide];
        } elseif ($application->source_type === GiteaApp::class && $application->source) {
            $source = ['type' => 'gitea_app', 'uuid' => $application->source->uuid, 'system_wide' => (bool) $application->source->is_system_wide];
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
            'secret_manager_link' => $this->exportSecretManagerLink($application),
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

        // Not fillable
        if (in_array('include_coolify_default_variables', $columns, true)) {
            $attrs['include_coolify_default_variables'] = $database->include_coolify_default_variables;
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
            'secret_manager_link' => $this->exportSecretManagerLink($database),
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

        // Hidden compose fields, maintenance mode and the Coolify default variables switch (not fillable)
        foreach (['docker_compose', 'docker_compose_raw', 'is_maintenance_enabled', 'maintenance_page', 'include_coolify_default_variables'] as $hidden) {
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
            'secret_manager_link' => $this->exportSecretManagerLink($service),
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
            'traefik_certificate_resolver' => $app->traefik_certificate_resolver ?? null,
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
                'uses_legacy_escaping' => (bool) $variable->uses_legacy_escaping,
                'preserve_compose_null' => (bool) $variable->preserve_compose_null,
                'comment' => $variable->comment,
                'order' => $variable->order,
                // The Coolify version that created the variable.
                'version' => $variable->version,
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
                'ignores_compose_driver_options' => (bool) $volume->ignores_compose_driver_options,
                'standalone_sqlite_uuid' => $volume->standaloneSqlite?->uuid,
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
                'missing_backup_notification_days' => $backup->missing_backup_notification_days,
                's3_storage_uuid' => $backup->s3?->uuid,
                's3_storage_uuids' => $backup->selectedS3Storages()->pluck('uuid')->values()->all(),
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
            'organization_self_hosted_runners' => $app->organization_self_hosted_runners,
            'actions' => $app->actions,
            'webhook_events' => $app->webhook_events,
            'runner_group_id' => $app->runner_group_id,
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
    private function exportGiteaApp(GiteaApp $app): array
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
            'client_id' => $app->client_id,
            'client_secret' => $app->client_secret,
            'access_token' => $app->access_token,
            'refresh_token' => $app->refresh_token,
            'expires_at' => $app->expires_at,
            'redirect_uri' => $app->redirect_uri,
            'webhook_secret' => $app->webhook_secret,
            'private_key_uuid' => $app->privateKey?->uuid,
            'webhook_url_hint' => '/webhooks/source/gitea/events',
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
     * The token is read through its cast, so the bundle has it in plaintext like the other credentials.
     *
     * @return array<string, mixed>
     */
    private function exportIntegrationToken(IntegrationToken $token): array
    {
        return [
            'uuid' => $token->uuid,
            'provider' => $token->provider,
            'name' => $token->name,
            'token' => $token->token,
            'capabilities' => $token->capabilities,
            'metadata' => $token->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportDnsZone(DnsProviderZone $zone): array
    {
        return [
            'uuid' => $zone->uuid,
            'integration_token_uuid' => $zone->integrationToken->uuid,
            'provider_zone_id' => $zone->provider_zone_id,
            'name' => $zone->name,
            'account_id' => $zone->account_id,
            'account_name' => $zone->account_name,
        ];
    }

    /**
     * The ownership comment proves at the provider that this instance created the record. The target compares it
     * with the remote comment before it takes ownership.
     *
     * @param  array<string, array{kind: string, uuid: string}|null>  $resourceKeys
     * @return array<string, mixed>
     */
    private function exportManagedDnsRecord(ManagedDnsRecord $record, array $resourceKeys): array
    {
        return [
            'uuid' => $record->uuid,
            'integration_token_uuid' => $record->integrationToken->uuid,
            'dns_provider_zone_uuid' => $record->zone->uuid,
            'provider_record_id' => $record->provider_record_id,
            'type' => $record->type,
            'name' => $record->name,
            'content' => $record->content,
            'owned' => (bool) $record->owned,
            'ownership_comment' => $record->ownershipComment(),
            'references' => $record->references
                ->map(fn (ManagedDnsRecordReference $reference) => $resourceKeys[$reference->resource_type.':'.$reference->resource_id] ?? null)
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{uuid: string, integration_token_uuid: string, settings: array<string, mixed>|null}|null
     */
    private function exportSecretManagerLink(Model $resource): ?array
    {
        if (! method_exists($resource, 'secretManagerLink')) {
            return null;
        }
        $link = $resource->secretManagerLink()->with('integrationToken')->first();
        if (! $link) {
            return null;
        }

        return [
            'uuid' => $link->uuid,
            'integration_token_uuid' => $link->integrationToken->uuid,
            'settings' => $link->settings,
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
                'domain_port_overrides' => $preview->domain_port_overrides,
                'max_restart_count' => $preview->max_restart_count,
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
                        'missing_backup_notification_days' => $backup->missing_backup_notification_days,
                        's3_storage_uuid' => $backup->s3?->uuid,
                        's3_storage_uuids' => $backup->selectedS3Storages()->pluck('uuid')->values()->all(),
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
