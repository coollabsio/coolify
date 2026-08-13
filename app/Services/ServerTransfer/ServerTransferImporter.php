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
use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\SslCertificate;
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
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ServerTransferImporter
{
    /**
     * @var array<string, class-string>
     */
    private const DATABASE_MODELS = [
        'StandalonePostgresql' => StandalonePostgresql::class,
        'StandaloneMysql' => StandaloneMysql::class,
        'StandaloneMariadb' => StandaloneMariadb::class,
        'StandaloneMongodb' => StandaloneMongodb::class,
        'StandaloneRedis' => StandaloneRedis::class,
        'StandaloneKeydb' => StandaloneKeydb::class,
        'StandaloneDragonfly' => StandaloneDragonfly::class,
        'StandaloneClickhouse' => StandaloneClickhouse::class,
    ];

    /** @var array<string, PrivateKey> */
    private array $privateKeyMap = [];

    /** @var array<string, GithubApp> */
    private array $githubAppMap = [];

    /** @var array<string, GitlabApp> */
    private array $gitlabAppMap = [];

    /** @var array<string, S3Storage> */
    private array $s3StorageMap = [];

    /** @var array<string, CloudProviderToken> */
    private array $cloudTokenMap = [];

    /** @var array<string, LocalPersistentVolume|LocalFileVolume> */
    private array $volumeMap = [];

    /** @var array<string, Application> */
    private array $applicationMap = [];

    /** @var array<string, Service> */
    private array $serviceMap = [];

    /** @var array<string, Model> */
    private array $databaseMap = [];

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{
     *     dry_run: bool,
     *     warnings: list<string>,
     *     server_uuid: string|null,
     *     private_key_uuid: string|null,
     *     created: array<string, int>,
     *     preserved_uuids: bool,
     *     export_id: string|null,
     *     claimed: bool,
     *     claim: array<string, mixed>|null
     * }
     */
    public function import(
        array $bundle,
        int $teamId,
        bool $dryRun = false,
        bool $preserveUuids = true,
        bool $adoptMode = true,
        bool $claim = true,
        bool $writeRemote = false,
        bool $rebindSentinel = true,
    ): array {
        ServerTransferBundle::assertValid($bundle);

        $validation = ServerTransferBundle::validate($bundle);
        $warnings = $validation['warnings'];
        if (is_array(data_get($bundle, 'warnings'))) {
            $warnings = array_values(array_unique(array_merge($warnings, $bundle['warnings'])));
        }

        $serverUuid = (string) data_get($bundle, 'server.uuid');
        $serverIp = (string) data_get($bundle, 'server.ip');

        $uuidConflict = Server::withTrashed()->where('uuid', $serverUuid)->exists() && $preserveUuids;
        $existingIp = Server::where('ip', $serverIp)->first();
        $ipConflict = $existingIp !== null;

        if (! $dryRun && $uuidConflict) {
            throw ValidationException::withMessages([
                'server.uuid' => ["A server with UUID {$serverUuid} already exists on this instance. Delete/transfer it first, or import with preserve_uuids=false."],
            ]);
        }

        if (! $dryRun && $ipConflict) {
            throw ValidationException::withMessages([
                'server.ip' => ["A server with IP/domain {$serverIp} already exists (uuid={$existingIp->uuid}). Complete handoff on the source instance first."],
            ]);
        }

        if ($uuidConflict) {
            $warnings[] = "A server with UUID {$serverUuid} already exists on this instance.";
        }
        if ($ipConflict) {
            $warnings[] = "A server with IP/domain {$serverIp} already exists (uuid={$existingIp->uuid}).";
        }

        $created = [
            'projects' => 0,
            'environments' => 0,
            'applications' => 0,
            'databases' => 0,
            'services' => 0,
            'destinations' => 0,
            'private_keys' => 0,
            'github_apps' => 0,
            'gitlab_apps' => 0,
            's3_storages' => 0,
            'cloud_provider_tokens' => 0,
            'ssl_certificates' => 0,
            'volume_backups' => 0,
            'previews' => 0,
        ];

        if ($dryRun) {
            foreach (data_get($bundle, 'projects', []) as $project) {
                $created['projects']++;
                foreach (data_get($project, 'environments', []) as $environment) {
                    $created['environments']++;
                    $created['applications'] += count(data_get($environment, 'applications', []));
                    $created['databases'] += count(data_get($environment, 'databases', []));
                    $created['services'] += count(data_get($environment, 'services', []));
                    foreach (data_get($environment, 'applications', []) as $app) {
                        $created['previews'] += count(data_get($app, 'previews', []));
                    }
                }
            }
            $created['destinations'] = count(data_get($bundle, 'destinations', []));
            $created['private_keys'] = max(
                count(data_get($bundle, 'private_keys', [])),
                data_get($bundle, 'private_key') ? 1 : 0
            );
            $created['github_apps'] = count(data_get($bundle, 'github_apps', []));
            $created['gitlab_apps'] = count(data_get($bundle, 'gitlab_apps', []));
            $created['s3_storages'] = count(data_get($bundle, 's3_storages', []));
            $created['cloud_provider_tokens'] = count(data_get($bundle, 'cloud_provider_tokens', []));
            $created['ssl_certificates'] = count(data_get($bundle, 'ssl_certificates', []));
            $created['volume_backups'] = count(data_get($bundle, 'volume_backups', []));

            // Append transfer hints for the target instance FQDN.
            $targetUrl = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');
            if (count(data_get($bundle, 'github_apps', [])) > 0) {
                $warnings[] = "After import, set GitHub App webhook URL to {$targetUrl}/webhooks/source/github/events (and update setup/callback URLs in GitHub if needed) so automations work on this instance.";
            }
            if (count(data_get($bundle, 'gitlab_apps', [])) > 0) {
                $warnings[] = "After import, set GitLab webhook URL to {$targetUrl}/webhooks/source/gitlab/events so automations work on this instance.";
            }

            return [
                'dry_run' => true,
                'warnings' => $warnings,
                'server_uuid' => $preserveUuids ? $serverUuid : null,
                'private_key_uuid' => data_get($bundle, 'private_key.uuid'),
                'created' => $created,
                'preserved_uuids' => $preserveUuids,
                'export_id' => data_get($bundle, 'export_id'),
                'claimed' => false,
                'claim' => null,
            ];
        }

        $result = DB::transaction(function () use ($bundle, $teamId, $preserveUuids, $adoptMode, $warnings, &$created) {
            $this->privateKeyMap = [];
            $this->githubAppMap = [];
            $this->gitlabAppMap = [];
            $this->s3StorageMap = [];
            $this->cloudTokenMap = [];
            $this->volumeMap = [];
            $this->applicationMap = [];
            $this->serviceMap = [];
            $this->databaseMap = [];

            // Import shared dependencies first
            $keyPayloads = data_get($bundle, 'private_keys', []);
            if ($keyPayloads === [] && data_get($bundle, 'private_key')) {
                $keyPayloads = [data_get($bundle, 'private_key')];
            }
            foreach ($keyPayloads as $keyPayload) {
                $key = $this->importPrivateKey($keyPayload, $teamId, $preserveUuids);
                $this->privateKeyMap[(string) data_get($keyPayload, 'uuid', $key->uuid)] = $key;
                $this->privateKeyMap[$key->uuid] = $key;
                $created['private_keys']++;
            }

            foreach (data_get($bundle, 'github_apps', []) as $ghPayload) {
                // Defensive: never import system-wide GitHub Apps from a bundle.
                if ((bool) data_get($ghPayload, 'is_system_wide', false)) {
                    continue;
                }
                $gh = $this->importGithubApp($ghPayload, $teamId, $preserveUuids);
                $this->githubAppMap[(string) data_get($ghPayload, 'uuid', $gh->uuid)] = $gh;
                $this->githubAppMap[$gh->uuid] = $gh;
                $created['github_apps']++;
            }

            foreach (data_get($bundle, 'gitlab_apps', []) as $glPayload) {
                // Defensive: never import system-wide GitLab Apps from a bundle.
                if ((bool) data_get($glPayload, 'is_system_wide', false)) {
                    continue;
                }
                $gl = $this->importGitlabApp($glPayload, $teamId, $preserveUuids);
                $this->gitlabAppMap[(string) data_get($glPayload, 'uuid', $gl->uuid)] = $gl;
                $this->gitlabAppMap[$gl->uuid] = $gl;
                $created['gitlab_apps']++;
            }

            foreach (data_get($bundle, 's3_storages', []) as $s3Payload) {
                $s3 = $this->importS3Storage($s3Payload, $teamId, $preserveUuids);
                $this->s3StorageMap[(string) data_get($s3Payload, 'uuid', $s3->uuid)] = $s3;
                $this->s3StorageMap[$s3->uuid] = $s3;
                $created['s3_storages']++;
            }

            foreach (data_get($bundle, 'cloud_provider_tokens', []) as $tokenPayload) {
                $token = $this->importCloudProviderToken($tokenPayload, $teamId, $preserveUuids);
                $this->cloudTokenMap[(string) data_get($tokenPayload, 'uuid', $token->uuid)] = $token;
                $this->cloudTokenMap[$token->uuid] = $token;
                $created['cloud_provider_tokens']++;
            }

            $serverKeyUuid = (string) data_get($bundle, 'private_key.uuid', '');
            $privateKey = $this->privateKeyMap[$serverKeyUuid]
                ?? $this->importPrivateKey(data_get($bundle, 'private_key', []), $teamId, $preserveUuids);

            $server = $this->importServer(data_get($bundle, 'server', []), $privateKey, $teamId, $preserveUuids, $adoptMode);
            $destinationMap = $this->importDestinations(data_get($bundle, 'destinations', []), $server, $preserveUuids);
            $created['destinations'] = count($destinationMap);

            $this->importServerSharedEnvVars(data_get($bundle, 'shared_environment_variables.server', []), $server, $teamId);

            foreach (data_get($bundle, 'projects', []) as $projectPayload) {
                $project = $this->importProject($projectPayload, $teamId, $preserveUuids, $created);
                foreach (data_get($projectPayload, 'environments', []) as $environmentPayload) {
                    $environment = $this->importEnvironment($environmentPayload, $project, $preserveUuids, $created);

                    foreach (data_get($environmentPayload, 'applications', []) as $appPayload) {
                        $app = $this->importApplication($appPayload, $environment, $destinationMap, $preserveUuids, $adoptMode, $teamId);
                        $this->applicationMap[(string) data_get($appPayload, 'uuid', $app->uuid)] = $app;
                        $created['applications']++;
                        $created['previews'] += count(data_get($appPayload, 'previews', []));
                    }

                    foreach (data_get($environmentPayload, 'databases', []) as $dbPayload) {
                        $db = $this->importDatabase($dbPayload, $environment, $destinationMap, $preserveUuids, $adoptMode, $teamId);
                        if ($db) {
                            $this->databaseMap[(string) data_get($dbPayload, 'uuid', $db->uuid)] = $db;
                        }
                        $created['databases']++;
                    }

                    foreach (data_get($environmentPayload, 'services', []) as $servicePayload) {
                        $service = $this->importService($servicePayload, $environment, $server, $destinationMap, $preserveUuids, $adoptMode, $teamId);
                        $this->serviceMap[(string) data_get($servicePayload, 'uuid', $service->uuid)] = $service;
                        $created['services']++;
                    }
                }
            }

            $created['ssl_certificates'] = $this->importSslCertificates(data_get($bundle, 'ssl_certificates', []), $server);
            $created['volume_backups'] = $this->importVolumeBackups(data_get($bundle, 'volume_backups', []), $teamId);

            $targetUrl = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');
            if ($created['github_apps'] > 0) {
                $warnings[] = "GitHub Apps imported. Update webhook URL to {$targetUrl}/webhooks/source/github/events (and setup/callback URLs in GitHub) so automations work on this instance.";
            }
            if ($created['gitlab_apps'] > 0) {
                $warnings[] = "GitLab sources imported. Update webhook URL to {$targetUrl}/webhooks/source/gitlab/events so automations work on this instance.";
            }

            $metadata = $server->server_metadata ?? [];
            $metadata['transfer'] = [
                'status' => 'imported',
                'export_id' => data_get($bundle, 'export_id'),
                'source_instance_url' => data_get($bundle, 'source_instance.url'),
                'imported_at' => now()->toIso8601String(),
                'adopt_mode' => $adoptMode,
            ];
            $server->server_metadata = $metadata;
            $server->save();

            return [
                'dry_run' => false,
                'warnings' => array_values(array_unique($warnings)),
                'server_uuid' => $server->uuid,
                'private_key_uuid' => $privateKey->uuid,
                'created' => $created,
                'preserved_uuids' => $preserveUuids,
                'export_id' => data_get($bundle, 'export_id'),
                'claimed' => false,
                'claim' => null,
            ];
        });

        // Claim after the import transaction commits so host/SSH work cannot roll back DB rows.
        if ($claim && filled(data_get($result, 'server_uuid'))) {
            $server = Server::where('uuid', $result['server_uuid'])->where('team_id', $teamId)->first();
            if ($server) {
                try {
                    $claimResult = app(ServerTransferClaimer::class)->claim(
                        $server,
                        writeRemote: $writeRemote,
                        rebindSentinel: $rebindSentinel,
                    );
                    $result['claimed'] = true;
                    $result['claim'] = $claimResult;
                    if (! data_get($claimResult, 'claim_written') && $writeRemote) {
                        $result['warnings'][] = 'Server imported and claimed in Coolify, but the remote ownership file was not written (SSH unavailable).';
                    }
                } catch (Throwable $e) {
                    $result['claimed'] = false;
                    $result['claim'] = null;
                    $result['warnings'][] = 'Server imported, but automatic claim failed: '.$e->getMessage();
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importPrivateKey(array $payload, int $teamId, bool $preserveUuids): PrivateKey
    {
        $material = (string) data_get($payload, 'private_key');
        if ($material === '') {
            throw new RuntimeException('Private key material is required.');
        }

        $fingerprint = PrivateKey::generateFingerprint($material)
            ?? data_get($payload, 'fingerprint');

        if ($fingerprint) {
            $existing = PrivateKey::query()->where('fingerprint', $fingerprint)->first();
            if ($existing) {
                if ((int) $existing->team_id !== $teamId) {
                    throw ValidationException::withMessages([
                        'private_key' => ['This SSH private key already exists on another team on this instance.'],
                    ]);
                }

                return $existing;
            }
        }

        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        if (PrivateKey::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $key = new PrivateKey([
            'name' => data_get($payload, 'name') ?: 'Imported transfer key',
            'description' => data_get($payload, 'description'),
            'private_key' => $material,
            'team_id' => $teamId,
            'is_git_related' => (bool) data_get($payload, 'is_git_related', false),
        ]);
        $key->uuid = $uuid;

        try {
            $key->save();
        } catch (Throwable $e) {
            // Retry once without forcing uuid if uniqueness/validation conflicts.
            $key->uuid = new_public_id();
            $key->save();
        }

        return $key->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importServer(array $payload, PrivateKey $privateKey, int $teamId, bool $preserveUuids, bool $adoptMode): Server
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        if (Server::withTrashed()->where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $cloudTokenId = null;
        $cloudTokenUuid = data_get($payload, 'cloud_provider_token_uuid');
        if ($cloudTokenUuid && isset($this->cloudTokenMap[$cloudTokenUuid])) {
            $cloudTokenId = $this->cloudTokenMap[$cloudTokenUuid]->id;
        }

        $server = new Server;
        $server->forceFill([
            'name' => data_get($payload, 'name') ?: generate_random_name(),
            'description' => data_get($payload, 'description'),
            'ip' => data_get($payload, 'ip'),
            'port' => (int) data_get($payload, 'port', 22),
            'user' => data_get($payload, 'user') ?: 'root',
            'private_key_id' => $privateKey->id,
            'team_id' => $teamId,
            'cloud_provider_token_id' => $cloudTokenId,
        ]);
        $server->uuid = $uuid;
        $server->save();

        if ($server->settings && data_get($payload, 'is_build_server')) {
            $server->settings->is_build_server = true;
            $server->settings->save();
        }

        $proxy = data_get($payload, 'proxy', []);
        if (is_array($proxy) && $proxy !== []) {
            foreach ($proxy as $key => $value) {
                $server->proxy->set($key, $value);
            }
            $server->save();
        }

        $settingsPayload = data_get($payload, 'settings', []);
        if (is_array($settingsPayload) && $server->settings) {
            $safe = collect($settingsPayload)
                ->except([
                    'id', 'server_id', 'created_at', 'updated_at',
                    'is_reachable', 'is_usable', 'force_disabled',
                    'sentinel_token', 'sentinel_custom_url',
                ])
                ->all();
            $server->settings->fill($safe);
            // Imported servers start not validated; claim will rebind sentinel.
            $server->settings->is_reachable = false;
            $server->settings->is_usable = false;
            $server->settings->force_disabled = false;
            $server->settings->is_sentinel_enabled = false;
            $server->settings->save();
        }

        if ($adoptMode) {
            // Do not trigger validation/install automatically; operator claims next.
            $server->settings->is_reachable = false;
            $server->settings->is_usable = false;
            $server->settings->save();
        }

        return $server->fresh(['settings', 'standaloneDockers', 'swarmDockers']);
    }

    /**
     * @param  list<array<string, mixed>>  $destinations
     * @return array<string, StandaloneDocker|SwarmDocker> keyed by original uuid
     */
    private function importDestinations(array $destinations, Server $server, bool $preserveUuids): array
    {
        $map = [];
        $existingStandalone = $server->standaloneDockers->values();
        $existingSwarm = $server->swarmDockers->values();
        $standaloneIndex = 0;
        $swarmIndex = 0;

        if ($destinations === []) {
            $first = $existingStandalone->first() ?? $existingSwarm->first();
            if ($first) {
                $map[$first->uuid] = $first;
            }

            return $map;
        }

        foreach ($destinations as $destination) {
            $type = data_get($destination, 'type', 'standalone');
            $originalUuid = (string) data_get($destination, 'uuid');
            $uuid = $preserveUuids && $originalUuid !== '' ? $originalUuid : new_public_id();

            if ($type === 'swarm') {
                $model = $existingSwarm->get($swarmIndex);
                $swarmIndex++;
                if ($model) {
                    $model->forceFill([
                        'uuid' => SwarmDocker::where('uuid', $uuid)->where('id', '!=', $model->id)->exists() ? $model->uuid : $uuid,
                        'name' => data_get($destination, 'name') ?: $model->name,
                        'network' => data_get($destination, 'network') ?: $model->network,
                    ])->saveQuietly();
                } else {
                    $model = new SwarmDocker;
                    $model->forceFill([
                        'uuid' => SwarmDocker::where('uuid', $uuid)->exists() ? new_public_id() : $uuid,
                        'name' => data_get($destination, 'name') ?: 'coolify-overlay',
                        'network' => data_get($destination, 'network') ?: 'coolify-overlay',
                        'server_id' => $server->id,
                    ])->saveQuietly();
                }
            } else {
                $model = $existingStandalone->get($standaloneIndex);
                $standaloneIndex++;
                if ($model) {
                    $targetUuid = StandaloneDocker::where('uuid', $uuid)->where('id', '!=', $model->id)->exists()
                        ? $model->uuid
                        : $uuid;
                    $model->forceFill([
                        'uuid' => $targetUuid,
                        'name' => data_get($destination, 'name') ?: $model->name,
                        'network' => data_get($destination, 'network') ?: $model->network,
                    ])->saveQuietly();
                } else {
                    $model = new StandaloneDocker;
                    $model->forceFill([
                        'uuid' => StandaloneDocker::where('uuid', $uuid)->exists() ? new_public_id() : $uuid,
                        'name' => data_get($destination, 'name') ?: 'coolify',
                        'network' => data_get($destination, 'network') ?: 'coolify',
                        'server_id' => $server->id,
                    ])->saveQuietly();
                }
            }

            $map[$originalUuid !== '' ? $originalUuid : $model->uuid] = $model->fresh();
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $variables
     */
    private function importServerSharedEnvVars(array $variables, Server $server, int $teamId): void
    {
        foreach ($variables as $variable) {
            SharedEnvironmentVariable::create([
                'key' => data_get($variable, 'key'),
                'value' => data_get($variable, 'value'),
                'is_multiline' => (bool) data_get($variable, 'is_multiline', false),
                'is_literal' => (bool) data_get($variable, 'is_literal', false),
                'is_shown_once' => (bool) data_get($variable, 'is_shown_once', false),
                'comment' => data_get($variable, 'comment'),
                'type' => 'server',
                'server_id' => $server->id,
                'team_id' => $teamId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $created
     */
    private function importProject(array $payload, int $teamId, bool $preserveUuids, array &$created): Project
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $existing = Project::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            $this->importProjectSharedEnvVars(data_get($payload, 'shared_environment_variables', []), $existing, $teamId);

            return $existing;
        }

        if (Project::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $project = Project::create([
            'uuid' => $uuid,
            'name' => data_get($payload, 'name') ?: generate_random_name(),
            'description' => data_get($payload, 'description'),
            'team_id' => $teamId,
        ]);
        $created['projects']++;

        $this->importProjectSharedEnvVars(data_get($payload, 'shared_environment_variables', []), $project, $teamId);

        return $project->fresh(['environments']);
    }

    /**
     * @param  list<array<string, mixed>>  $variables
     */
    private function importProjectSharedEnvVars(array $variables, Project $project, int $teamId): void
    {
        foreach ($variables as $variable) {
            $exists = SharedEnvironmentVariable::query()
                ->where('type', 'project')
                ->where('project_id', $project->id)
                ->where('key', data_get($variable, 'key'))
                ->exists();
            if ($exists) {
                continue;
            }
            SharedEnvironmentVariable::create([
                'key' => data_get($variable, 'key'),
                'value' => data_get($variable, 'value'),
                'is_multiline' => (bool) data_get($variable, 'is_multiline', false),
                'is_literal' => (bool) data_get($variable, 'is_literal', false),
                'is_shown_once' => (bool) data_get($variable, 'is_shown_once', false),
                'comment' => data_get($variable, 'comment'),
                'type' => 'project',
                'project_id' => $project->id,
                'team_id' => $teamId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $created
     */
    private function importEnvironment(array $payload, Project $project, bool $preserveUuids, array &$created): Environment
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $byUuid = Environment::where('uuid', $uuid)->where('project_id', $project->id)->first();
        if ($byUuid) {
            $this->importEnvironmentSharedEnvVars(data_get($payload, 'shared_environment_variables', []), $byUuid, $project->team_id);

            return $byUuid;
        }

        $name = (string) data_get($payload, 'name', 'production');
        $byName = $project->environments()->where('name', $name)->first();
        if ($byName) {
            if ($preserveUuids && $uuid !== '' && ! Environment::where('uuid', $uuid)->exists()) {
                $byName->uuid = $uuid;
                $byName->save();
            }
            if (filled(data_get($payload, 'description'))) {
                $byName->description = data_get($payload, 'description');
                $byName->save();
            }
            $this->importEnvironmentSharedEnvVars(data_get($payload, 'shared_environment_variables', []), $byName, $project->team_id);

            return $byName->fresh();
        }

        if (Environment::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $environment = Environment::create([
            'uuid' => $uuid,
            'name' => $name,
            'description' => data_get($payload, 'description'),
            'project_id' => $project->id,
        ]);
        $created['environments']++;

        $this->importEnvironmentSharedEnvVars(data_get($payload, 'shared_environment_variables', []), $environment, $project->team_id);

        return $environment;
    }

    /**
     * @param  list<array<string, mixed>>  $variables
     */
    private function importEnvironmentSharedEnvVars(array $variables, Environment $environment, int $teamId): void
    {
        foreach ($variables as $variable) {
            $exists = SharedEnvironmentVariable::query()
                ->where('type', 'environment')
                ->where('environment_id', $environment->id)
                ->where('key', data_get($variable, 'key'))
                ->exists();
            if ($exists) {
                continue;
            }
            SharedEnvironmentVariable::create([
                'key' => data_get($variable, 'key'),
                'value' => data_get($variable, 'value'),
                'is_multiline' => (bool) data_get($variable, 'is_multiline', false),
                'is_literal' => (bool) data_get($variable, 'is_literal', false),
                'is_shown_once' => (bool) data_get($variable, 'is_shown_once', false),
                'comment' => data_get($variable, 'comment'),
                'type' => 'environment',
                'environment_id' => $environment->id,
                'team_id' => $teamId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, StandaloneDocker|SwarmDocker>  $destinationMap
     */
    private function importApplication(
        array $payload,
        Environment $environment,
        array $destinationMap,
        bool $preserveUuids,
        bool $adoptMode,
        int $teamId,
    ): Application {
        $destination = $this->resolveDestination(data_get($payload, 'destination_uuid'), $destinationMap);
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        if (Application::withTrashed()->where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $attributes = (array) data_get($payload, 'attributes', []);
        unset($attributes['id'], $attributes['environment_id'], $attributes['destination_id'], $attributes['destination_type'], $attributes['source_id'], $attributes['source_type'], $attributes['private_key_id']);
        $attributes = $this->onlyExistingColumns(Application::class, $attributes);

        $attributes['uuid'] = $uuid;
        $attributes['environment_id'] = $environment->id;
        $attributes['destination_id'] = $destination->id;
        $attributes['destination_type'] = $destination->getMorphClass();

        // Re-link git source
        $source = data_get($payload, 'source');
        if (is_array($source) && filled(data_get($source, 'uuid'))) {
            $sourceUuid = (string) data_get($source, 'uuid');
            $sourceType = (string) data_get($source, 'type');
            if ($sourceType === 'github_app') {
                $gh = $this->githubAppMap[$sourceUuid]
                    ?? GithubApp::query()->where('uuid', $sourceUuid)->where('is_system_wide', true)->first()
                    ?? GithubApp::query()->where('uuid', $sourceUuid)->where('team_id', $teamId)->first();
                if ($gh) {
                    $attributes['source_type'] = GithubApp::class;
                    $attributes['source_id'] = $gh->id;
                }
            } elseif ($sourceType === 'gitlab_app') {
                $gl = $this->gitlabAppMap[$sourceUuid]
                    ?? GitlabApp::query()->where('uuid', $sourceUuid)->where('is_system_wide', true)->first()
                    ?? GitlabApp::query()->where('uuid', $sourceUuid)->where('team_id', $teamId)->first();
                if ($gl) {
                    $attributes['source_type'] = GitlabApp::class;
                    $attributes['source_id'] = $gl->id;
                }
            }
        }

        // Re-link deploy key
        $appKeyUuid = data_get($payload, 'private_key_uuid');
        if ($appKeyUuid && isset($this->privateKeyMap[$appKeyUuid])) {
            $attributes['private_key_id'] = $this->privateKeyMap[$appKeyUuid]->id;
        }

        if ($adoptMode) {
            // Keep runtime status if provided so inventory can match, but default exited if empty.
            $attributes['status'] = data_get($attributes, 'status') ?: 'exited';
        } else {
            $attributes['status'] = 'exited';
        }

        $application = Application::create($attributes);

        $settings = (array) data_get($payload, 'settings', []);
        if ($settings !== [] && $application->settings) {
            $application->settings->fill(collect($settings)->except(['id', 'application_id'])->all());
            $application->settings->save();
        }

        $this->importEnvVars(data_get($payload, 'environment_variables', []), $application, false);
        $this->importEnvVars(data_get($payload, 'environment_variables_preview', []), $application, true);
        $this->importPersistentStorages(data_get($payload, 'persistent_storages', []), $application);
        $this->importFileStorages(data_get($payload, 'file_storages', []), $application);
        $this->importScheduledTasks(data_get($payload, 'scheduled_tasks', []), $application, $teamId);
        $this->importTags(data_get($payload, 'tags', []), $application, $teamId);
        $this->importPreviews(data_get($payload, 'previews', []), $application, $preserveUuids, $adoptMode);

        return $application;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, StandaloneDocker|SwarmDocker>  $destinationMap
     */
    private function importDatabase(
        array $payload,
        Environment $environment,
        array $destinationMap,
        bool $preserveUuids,
        bool $adoptMode,
        int $teamId = 0,
    ): ?Model {
        $type = (string) data_get($payload, 'type', 'StandalonePostgresql');
        $modelClass = self::DATABASE_MODELS[$type]
            ?? (is_string(data_get($payload, 'model')) && class_exists((string) data_get($payload, 'model'))
                ? (string) data_get($payload, 'model')
                : null);

        if (! $modelClass || ! class_exists($modelClass)) {
            throw new RuntimeException("Unsupported database type: {$type}");
        }

        $destination = $this->resolveDestination(data_get($payload, 'destination_uuid'), $destinationMap);
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        if ($modelClass::withTrashed()->where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $attributes = (array) data_get($payload, 'attributes', []);
        unset($attributes['id'], $attributes['environment_id'], $attributes['destination_id'], $attributes['destination_type']);
        $attributes = $this->onlyExistingColumns($modelClass, $attributes);
        $attributes['uuid'] = $uuid;
        $attributes['environment_id'] = $environment->id;
        $attributes['destination_id'] = $destination->id;
        $attributes['destination_type'] = $destination->getMorphClass();
        $attributes['status'] = $adoptMode
            ? (data_get($attributes, 'status') ?: 'exited')
            : 'exited';

        // Avoid auto-created default volumes; we restore exported ones.
        $database = $modelClass::withoutEvents(function () use ($modelClass, $attributes, $uuid) {
            $database = new $modelClass;
            $database->forceFill(collect($attributes)->except(['uuid'])->all());
            $database->uuid = $uuid;
            $database->save();

            return $database;
        });

        $this->importEnvVars(data_get($payload, 'environment_variables', []), $database, false);
        $this->importPersistentStorages(data_get($payload, 'persistent_storages', []), $database);
        $this->importFileStorages(data_get($payload, 'file_storages', []), $database);
        if (method_exists($database, 'tags')) {
            $this->importTags(data_get($payload, 'tags', []), $database, $teamId);
        }
        $this->importScheduledBackups(data_get($payload, 'scheduled_backups', []), $database, $teamId);

        return $database;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, StandaloneDocker|SwarmDocker>  $destinationMap
     */
    private function importService(
        array $payload,
        Environment $environment,
        Server $server,
        array $destinationMap,
        bool $preserveUuids,
        bool $adoptMode,
        int $teamId = 0,
    ): Service {
        $destination = $this->resolveDestination(data_get($payload, 'destination_uuid'), $destinationMap);
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        if (Service::withTrashed()->where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $attributes = (array) data_get($payload, 'attributes', []);
        unset($attributes['id'], $attributes['environment_id'], $attributes['destination_id'], $attributes['destination_type'], $attributes['server_id']);
        $attributes = $this->onlyExistingColumns(Service::class, $attributes);
        $attributes['uuid'] = $uuid;
        $attributes['environment_id'] = $environment->id;
        $attributes['server_id'] = $server->id;
        $attributes['destination_id'] = $destination->id;
        $attributes['destination_type'] = $destination->getMorphClass();

        $service = Service::create($attributes);

        $this->importEnvVars(data_get($payload, 'environment_variables', []), $service, false);
        $this->importScheduledTasks(data_get($payload, 'scheduled_tasks', []), $service, $teamId);
        $this->importTags(data_get($payload, 'tags', []), $service, $teamId);

        foreach (data_get($payload, 'applications', []) as $appPayload) {
            $this->importServiceApplication($appPayload, $service, $preserveUuids, $adoptMode);
        }

        foreach (data_get($payload, 'databases', []) as $dbPayload) {
            $this->importServiceDatabase($dbPayload, $service, $preserveUuids, $adoptMode, $teamId);
        }

        return $service;
    }

    /**
     * @param  array<string, mixed>  $appPayload
     */
    private function importServiceApplication(array $appPayload, Service $service, bool $preserveUuids, bool $adoptMode): ServiceApplication
    {
        $appUuid = $preserveUuids && filled(data_get($appPayload, 'uuid'))
            ? (string) data_get($appPayload, 'uuid')
            : new_public_id();
        if (ServiceApplication::where('uuid', $appUuid)->exists()) {
            $appUuid = new_public_id();
        }

        $attributes = (array) data_get($appPayload, 'attributes', []);
        if ($attributes === []) {
            // Back-compat with older thin service-application payloads.
            $attributes = collect($appPayload)->except([
                'uuid', 'environment_variables', 'persistent_storages', 'file_storages', 'attributes',
            ])->all();
        }
        unset($attributes['id'], $attributes['service_id'], $attributes['created_at'], $attributes['updated_at'], $attributes['deleted_at']);
        $attributes = $this->onlyExistingColumns(ServiceApplication::class, $attributes);
        $attributes['service_id'] = $service->id;
        $attributes['name'] = data_get($attributes, 'name') ?: data_get($appPayload, 'name') ?: 'service-app';
        $attributes['status'] = $adoptMode
            ? (data_get($attributes, 'status') ?: data_get($appPayload, 'status') ?: 'running:unknown')
            : 'exited:unhealthy';

        $serviceApp = new ServiceApplication;
        $serviceApp->forceFill($attributes);
        $serviceApp->uuid = $appUuid;
        $serviceApp->save();

        $this->importEnvVars(data_get($appPayload, 'environment_variables', []), $serviceApp, false);
        $this->importPersistentStorages(data_get($appPayload, 'persistent_storages', []), $serviceApp);
        $this->importFileStorages(data_get($appPayload, 'file_storages', []), $serviceApp);

        return $serviceApp;
    }

    /**
     * @param  array<string, mixed>  $dbPayload
     */
    private function importServiceDatabase(array $dbPayload, Service $service, bool $preserveUuids, bool $adoptMode, int $teamId): ServiceDatabase
    {
        $dbUuid = $preserveUuids && filled(data_get($dbPayload, 'uuid'))
            ? (string) data_get($dbPayload, 'uuid')
            : new_public_id();
        if (ServiceDatabase::where('uuid', $dbUuid)->exists()) {
            $dbUuid = new_public_id();
        }

        $attributes = (array) data_get($dbPayload, 'attributes', []);
        if ($attributes === []) {
            $attributes = collect($dbPayload)->except([
                'uuid', 'environment_variables', 'persistent_storages', 'file_storages', 'scheduled_backups', 'attributes',
            ])->all();
        }
        unset($attributes['id'], $attributes['service_id'], $attributes['created_at'], $attributes['updated_at'], $attributes['deleted_at']);
        $attributes = $this->onlyExistingColumns(ServiceDatabase::class, $attributes);
        $attributes['service_id'] = $service->id;
        $attributes['name'] = data_get($attributes, 'name') ?: data_get($dbPayload, 'name') ?: 'service-db';
        $attributes['status'] = $adoptMode
            ? (data_get($attributes, 'status') ?: data_get($dbPayload, 'status') ?: 'running:unknown')
            : 'exited:unhealthy';

        $serviceDb = new ServiceDatabase;
        $serviceDb->forceFill($attributes);
        $serviceDb->uuid = $dbUuid;
        $serviceDb->save();

        $this->importPersistentStorages(data_get($dbPayload, 'persistent_storages', []), $serviceDb);
        $this->importFileStorages(data_get($dbPayload, 'file_storages', []), $serviceDb);
        $this->importScheduledBackups(data_get($dbPayload, 'scheduled_backups', []), $serviceDb, $teamId);

        return $serviceDb;
    }

    /**
     * @param  list<array<string, mixed>>  $variables
     */
    private function importEnvVars(array $variables, object $resource, bool $isPreview): void
    {
        foreach ($variables as $variable) {
            EnvironmentVariable::withoutEvents(function () use ($variable, $resource, $isPreview) {
                $uuid = filled(data_get($variable, 'uuid')) ? (string) data_get($variable, 'uuid') : new_public_id();
                if (EnvironmentVariable::where('uuid', $uuid)->exists()) {
                    $uuid = new_public_id();
                }

                $env = new EnvironmentVariable;
                $env->forceFill([
                    'key' => data_get($variable, 'key'),
                    'value' => data_get($variable, 'value'),
                    'is_literal' => (bool) data_get($variable, 'is_literal', false),
                    'is_multiline' => (bool) data_get($variable, 'is_multiline', false),
                    'is_preview' => $isPreview || (bool) data_get($variable, 'is_preview', false),
                    'is_runtime' => (bool) data_get($variable, 'is_runtime', true),
                    'is_buildtime' => (bool) data_get($variable, 'is_buildtime', true),
                    'is_shown_once' => (bool) data_get($variable, 'is_shown_once', false),
                    'is_shared' => (bool) data_get($variable, 'is_shared', false),
                    'is_required' => (bool) data_get($variable, 'is_required', false),
                    'comment' => data_get($variable, 'comment'),
                    'order' => data_get($variable, 'order'),
                    'resourceable_type' => $resource->getMorphClass(),
                    'resourceable_id' => $resource->id,
                ]);
                $env->uuid = $uuid;
                $env->save();
            });
        }
    }

    /**
     * @param  list<array<string, mixed>>  $volumes
     */
    private function importPersistentStorages(array $volumes, object $resource): void
    {
        foreach ($volumes as $volume) {
            $uuid = filled(data_get($volume, 'uuid')) ? (string) data_get($volume, 'uuid') : new_public_id();
            if (LocalPersistentVolume::where('uuid', $uuid)->exists()) {
                $uuid = new_public_id();
            }

            $volumeModel = new LocalPersistentVolume;
            $volumeModel->forceFill([
                'name' => data_get($volume, 'name'),
                'mount_path' => data_get($volume, 'mount_path'),
                'host_path' => data_get($volume, 'host_path'),
                'is_preview_suffix_enabled' => (bool) data_get($volume, 'is_preview_suffix_enabled', false),
                'resource_type' => $resource->getMorphClass(),
                'resource_id' => $resource->id,
            ]);
            $volumeModel->uuid = $uuid;
            $volumeModel->save();
            $this->volumeMap[$uuid] = $volumeModel;
            if (filled(data_get($volume, 'uuid'))) {
                $this->volumeMap[(string) data_get($volume, 'uuid')] = $volumeModel;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $storages
     */
    private function importFileStorages(array $storages, object $resource): void
    {
        foreach ($storages as $storage) {
            LocalFileVolume::withoutEvents(function () use ($storage, $resource) {
                $uuid = filled(data_get($storage, 'uuid')) ? (string) data_get($storage, 'uuid') : new_public_id();
                if (LocalFileVolume::where('uuid', $uuid)->exists()) {
                    $uuid = new_public_id();
                }

                // uuid is not fillable and withoutEvents skips BaseModel's creating hook.
                $file = new LocalFileVolume;
                $file->forceFill([
                    'fs_path' => data_get($storage, 'fs_path'),
                    'mount_path' => data_get($storage, 'mount_path'),
                    'content' => data_get($storage, 'content'),
                    'is_directory' => (bool) data_get($storage, 'is_directory', false),
                    'is_host_file' => (bool) data_get($storage, 'is_host_file', false),
                    'chown' => data_get($storage, 'chown'),
                    'chmod' => data_get($storage, 'chmod'),
                    'is_based_on_git' => (bool) data_get($storage, 'is_based_on_git', false),
                    'is_preview_suffix_enabled' => (bool) data_get($storage, 'is_preview_suffix_enabled', false),
                    'resource_type' => $resource->getMorphClass(),
                    'resource_id' => $resource->id,
                ]);
                $file->uuid = $uuid;
                $file->save();
                $this->volumeMap[$uuid] = $file;
                if (filled(data_get($storage, 'uuid'))) {
                    $this->volumeMap[(string) data_get($storage, 'uuid')] = $file;
                }
            });
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function importScheduledTasks(array $tasks, Application|Service $resource, int $teamId): void
    {
        foreach ($tasks as $task) {
            $uuid = filled(data_get($task, 'uuid')) ? (string) data_get($task, 'uuid') : new_public_id();
            if (ScheduledTask::where('uuid', $uuid)->exists()) {
                $uuid = new_public_id();
            }

            $payload = [
                'uuid' => $uuid,
                'name' => data_get($task, 'name'),
                'command' => data_get($task, 'command'),
                'frequency' => data_get($task, 'frequency'),
                'container' => data_get($task, 'container'),
                'timeout' => data_get($task, 'timeout'),
                'enabled' => (bool) data_get($task, 'enabled', true),
                'team_id' => $teamId,
            ];

            if ($resource instanceof Application) {
                $payload['application_id'] = $resource->id;
            } else {
                $payload['service_id'] = $resource->id;
            }

            ScheduledTask::create($payload);
        }
    }

    /**
     * @param  list<array{uuid?: string, name?: string}>  $tags
     */
    private function importTags(array $tags, Model $resource, int $teamId): void
    {
        if ($tags === [] || ! method_exists($resource, 'tags')) {
            return;
        }

        foreach ($tags as $tagPayload) {
            $name = strtolower(trim((string) data_get($tagPayload, 'name', '')));
            if ($name === '') {
                continue;
            }

            $uuid = filled(data_get($tagPayload, 'uuid')) ? (string) data_get($tagPayload, 'uuid') : new_public_id();

            $tag = Tag::query()
                ->where('team_id', $teamId)
                ->where('name', $name)
                ->first();

            if (! $tag) {
                // Prefer preserved uuid when free; otherwise create with a new one.
                if (Tag::where('uuid', $uuid)->exists()) {
                    $uuid = new_public_id();
                }
                $tag = new Tag;
                $tag->forceFill([
                    'name' => $name,
                    'team_id' => $teamId,
                ]);
                $tag->uuid = $uuid;
                $tag->save();
            }

            $resource->tags()->syncWithoutDetaching([$tag->id]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $backups
     */
    private function importScheduledBackups(array $backups, Model $database, int $teamId): void
    {
        foreach ($backups as $backup) {
            $uuid = filled(data_get($backup, 'uuid')) ? (string) data_get($backup, 'uuid') : new_public_id();
            if (ScheduledDatabaseBackup::where('uuid', $uuid)->exists()) {
                $uuid = new_public_id();
            }

            $s3Id = null;
            $s3Uuid = data_get($backup, 's3_storage_uuid');
            if ($s3Uuid && isset($this->s3StorageMap[$s3Uuid])) {
                $s3Id = $this->s3StorageMap[$s3Uuid]->id;
            }

            ScheduledDatabaseBackup::create([
                'uuid' => $uuid,
                'team_id' => $teamId,
                'description' => data_get($backup, 'description'),
                'enabled' => (bool) data_get($backup, 'enabled', true),
                'save_s3' => (bool) data_get($backup, 'save_s3', false) && $s3Id !== null,
                'frequency' => data_get($backup, 'frequency'),
                'databases_to_backup' => data_get($backup, 'databases_to_backup'),
                'dump_all' => (bool) data_get($backup, 'dump_all', false),
                'database_backup_retention_amount_locally' => data_get($backup, 'database_backup_retention_amount_locally', 0),
                'database_backup_retention_days_locally' => data_get($backup, 'database_backup_retention_days_locally'),
                'database_backup_retention_max_storage_locally' => data_get($backup, 'database_backup_retention_max_storage_locally'),
                'database_backup_retention_amount_s3' => data_get($backup, 'database_backup_retention_amount_s3'),
                'database_backup_retention_days_s3' => data_get($backup, 'database_backup_retention_days_s3'),
                'database_backup_retention_max_storage_s3' => data_get($backup, 'database_backup_retention_max_storage_s3'),
                'timeout' => data_get($backup, 'timeout'),
                'disable_local_backup' => (bool) data_get($backup, 'disable_local_backup', false),
                's3_storage_id' => $s3Id,
                'database_type' => $database->getMorphClass(),
                'database_id' => $database->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importGithubApp(array $payload, int $teamId, bool $preserveUuids): GithubApp
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $existing = GithubApp::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            return $existing;
        }
        if (GithubApp::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $privateKeyId = null;
        $keyUuid = data_get($payload, 'private_key_uuid');
        if ($keyUuid && isset($this->privateKeyMap[$keyUuid])) {
            $privateKeyId = $this->privateKeyMap[$keyUuid]->id;
        }

        $app = new GithubApp;
        $app->forceFill([
            'team_id' => $teamId,
            'private_key_id' => $privateKeyId,
            'name' => data_get($payload, 'name') ?: 'Imported GitHub App',
            'organization' => data_get($payload, 'organization'),
            'api_url' => data_get($payload, 'api_url') ?: 'https://api.github.com',
            'html_url' => data_get($payload, 'html_url') ?: 'https://github.com',
            'custom_user' => data_get($payload, 'custom_user'),
            'custom_port' => data_get($payload, 'custom_port'),
            'app_id' => data_get($payload, 'app_id'),
            'installation_id' => data_get($payload, 'installation_id'),
            'client_id' => data_get($payload, 'client_id'),
            'client_secret' => data_get($payload, 'client_secret'),
            'webhook_secret' => data_get($payload, 'webhook_secret'),
            'is_system_wide' => false,
            'is_public' => (bool) data_get($payload, 'is_public', false),
            'contents' => data_get($payload, 'contents'),
            'metadata' => data_get($payload, 'metadata'),
            'pull_requests' => data_get($payload, 'pull_requests'),
            'administration' => data_get($payload, 'administration'),
        ]);
        $app->uuid = $uuid;
        $app->save();

        return $app;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importGitlabApp(array $payload, int $teamId, bool $preserveUuids): GitlabApp
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $existing = GitlabApp::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            return $existing;
        }
        if (GitlabApp::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $privateKeyId = null;
        $keyUuid = data_get($payload, 'private_key_uuid');
        if ($keyUuid && isset($this->privateKeyMap[$keyUuid])) {
            $privateKeyId = $this->privateKeyMap[$keyUuid]->id;
        }

        $app = new GitlabApp;
        $app->forceFill([
            'team_id' => $teamId,
            'private_key_id' => $privateKeyId,
            'name' => data_get($payload, 'name') ?: 'Imported GitLab App',
            'organization' => data_get($payload, 'organization'),
            'api_url' => data_get($payload, 'api_url'),
            'html_url' => data_get($payload, 'html_url'),
            'custom_port' => data_get($payload, 'custom_port'),
            'custom_user' => data_get($payload, 'custom_user'),
            'is_system_wide' => false,
            'is_public' => (bool) data_get($payload, 'is_public', false),
            'app_id' => data_get($payload, 'app_id'),
            'app_secret' => data_get($payload, 'app_secret'),
            'oauth_id' => data_get($payload, 'oauth_id'),
            'client_id' => data_get($payload, 'client_id'),
            'client_secret' => data_get($payload, 'client_secret'),
            'access_token' => data_get($payload, 'access_token'),
            'refresh_token' => data_get($payload, 'refresh_token'),
            'expires_at' => data_get($payload, 'expires_at'),
            'redirect_uri' => data_get($payload, 'redirect_uri'),
            'group_name' => data_get($payload, 'group_name'),
            'public_key' => data_get($payload, 'public_key'),
            'webhook_token' => data_get($payload, 'webhook_token'),
            'deploy_key_id' => data_get($payload, 'deploy_key_id'),
        ]);
        $app->uuid = $uuid;
        $app->save();

        return $app;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importS3Storage(array $payload, int $teamId, bool $preserveUuids): S3Storage
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $existing = S3Storage::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            return $existing;
        }
        // Reuse by endpoint+bucket+key if already present for team
        $byIdentity = S3Storage::query()
            ->where('team_id', $teamId)
            ->where('endpoint', data_get($payload, 'endpoint'))
            ->where('bucket', data_get($payload, 'bucket'))
            ->first();
        if ($byIdentity) {
            return $byIdentity;
        }
        if (S3Storage::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $storage = new S3Storage;
        $storage->forceFill([
            'team_id' => $teamId,
            'name' => data_get($payload, 'name') ?: 'Imported S3',
            'description' => data_get($payload, 'description'),
            'region' => data_get($payload, 'region'),
            'key' => data_get($payload, 'key'),
            'secret' => data_get($payload, 'secret'),
            'bucket' => data_get($payload, 'bucket'),
            'endpoint' => data_get($payload, 'endpoint'),
            'is_usable' => (bool) data_get($payload, 'is_usable', true),
        ]);
        $storage->uuid = $uuid;
        $storage->save();

        return $storage;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importCloudProviderToken(array $payload, int $teamId, bool $preserveUuids): CloudProviderToken
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $existing = CloudProviderToken::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            return $existing;
        }
        if (CloudProviderToken::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $token = new CloudProviderToken;
        $token->forceFill([
            'team_id' => $teamId,
            'provider' => data_get($payload, 'provider'),
            'token' => data_get($payload, 'token'),
            'name' => data_get($payload, 'name') ?: 'Imported cloud token',
            'description' => data_get($payload, 'description'),
        ]);
        $token->uuid = $uuid;
        $token->save();

        return $token;
    }

    /**
     * @param  list<array<string, mixed>>  $previews
     */
    private function importPreviews(array $previews, Application $application, bool $preserveUuids, bool $adoptMode): void
    {
        foreach ($previews as $previewPayload) {
            $uuid = $preserveUuids && filled(data_get($previewPayload, 'uuid'))
                ? (string) data_get($previewPayload, 'uuid')
                : new_public_id();

            $preview = ApplicationPreview::withTrashed()->where('uuid', $uuid)->first();
            if (! $preview) {
                $pullRequestId = data_get($previewPayload, 'pull_request_id');
                if ($pullRequestId !== null) {
                    $preview = ApplicationPreview::withTrashed()
                        ->where('application_id', $application->id)
                        ->where('pull_request_id', $pullRequestId)
                        ->first();
                }
            }
            if ($preview && ApplicationPreview::withTrashed()->where('uuid', $uuid)->where('id', '!=', $preview->id)->exists()) {
                $uuid = new_public_id();
            } elseif (! $preview && ApplicationPreview::withTrashed()->where('uuid', $uuid)->exists()) {
                $uuid = new_public_id();
            }

            $fqdn = data_get($previewPayload, 'fqdn');
            // FQDN is globally unique; free it from other rows (including soft-deleted leftovers).
            if (filled($fqdn)) {
                ApplicationPreview::withoutEvents(function () use ($fqdn, $preview) {
                    ApplicationPreview::withTrashed()
                        ->where('fqdn', $fqdn)
                        ->when($preview, fn ($q) => $q->where('id', '!=', $preview->id))
                        ->get()
                        ->each(function (ApplicationPreview $conflict) {
                            $conflict->fqdn = null;
                            $conflict->saveQuietly();
                        });
                });
            }

            $status = $adoptMode
                ? (data_get($previewPayload, 'status') ?: 'exited')
                : 'exited';

            $attributes = [
                'application_id' => $application->id,
                'pull_request_id' => data_get($previewPayload, 'pull_request_id'),
                'pull_request_html_url' => data_get($previewPayload, 'pull_request_html_url') ?: '',
                'pull_request_issue_comment_id' => data_get($previewPayload, 'pull_request_issue_comment_id'),
                'fqdn' => $fqdn,
                'status' => $status,
                'git_type' => data_get($previewPayload, 'git_type'),
                'docker_compose_domains' => data_get($previewPayload, 'docker_compose_domains'),
                'docker_registry_image_tag' => data_get($previewPayload, 'docker_registry_image_tag'),
            ];

            if ($preview) {
                if ($preview->trashed()) {
                    $preview->restore();
                }
                $preview->forceFill($attributes);
                if ($preserveUuids && filled(data_get($previewPayload, 'uuid')) && $preview->uuid !== $uuid) {
                    if (! ApplicationPreview::withTrashed()->where('uuid', $uuid)->where('id', '!=', $preview->id)->exists()) {
                        $preview->uuid = $uuid;
                    }
                }
                $preview->save();
            } else {
                $preview = new ApplicationPreview;
                $preview->forceFill($attributes);
                $preview->uuid = $uuid;
                $preview->save();
            }

            // Avoid duplicating volumes when re-importing the same preview.
            if ($preview->wasRecentlyCreated || $preview->persistentStorages()->count() === 0) {
                $this->importPersistentStorages(data_get($previewPayload, 'persistent_storages', []), $preview);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $certificates
     */
    private function importSslCertificates(array $certificates, Server $server): int
    {
        $count = 0;
        foreach ($certificates as $certPayload) {
            $resourceType = null;
            $resourceId = null;
            $kind = data_get($certPayload, 'resource_kind');
            $resourceUuid = data_get($certPayload, 'resource_uuid');
            if ($kind === 'application' && $resourceUuid && isset($this->applicationMap[$resourceUuid])) {
                $resourceType = $this->applicationMap[$resourceUuid]->getMorphClass();
                $resourceId = $this->applicationMap[$resourceUuid]->id;
            } elseif ($kind === 'service' && $resourceUuid && isset($this->serviceMap[$resourceUuid])) {
                $resourceType = $this->serviceMap[$resourceUuid]->getMorphClass();
                $resourceId = $this->serviceMap[$resourceUuid]->id;
            } elseif ($resourceUuid && isset($this->databaseMap[$resourceUuid])) {
                $db = $this->databaseMap[$resourceUuid];
                $resourceType = $db->getMorphClass();
                $resourceId = $db->id;
            }

            SslCertificate::create([
                'ssl_certificate' => data_get($certPayload, 'ssl_certificate'),
                'ssl_private_key' => data_get($certPayload, 'ssl_private_key'),
                'configuration_dir' => data_get($certPayload, 'configuration_dir'),
                'mount_path' => data_get($certPayload, 'mount_path'),
                'common_name' => data_get($certPayload, 'common_name') ?: 'imported',
                'subject_alternative_names' => data_get($certPayload, 'subject_alternative_names'),
                'valid_until' => data_get($certPayload, 'valid_until') ?: now()->addYear(),
                'is_ca_certificate' => (bool) data_get($certPayload, 'is_ca_certificate', false),
                'server_id' => $server->id,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $backups
     */
    private function importVolumeBackups(array $backups, int $teamId): int
    {
        $count = 0;
        foreach ($backups as $backupPayload) {
            $volumeUuid = (string) data_get($backupPayload, 'backupable_uuid', '');
            $volume = $this->volumeMap[$volumeUuid] ?? null;
            if (! $volume) {
                continue;
            }

            $uuid = filled(data_get($backupPayload, 'uuid'))
                ? (string) data_get($backupPayload, 'uuid')
                : new_public_id();
            if (ScheduledVolumeBackup::where('uuid', $uuid)->exists()) {
                $uuid = new_public_id();
            }

            $s3Id = null;
            $s3Uuid = data_get($backupPayload, 's3_storage_uuid');
            if ($s3Uuid && isset($this->s3StorageMap[$s3Uuid])) {
                $s3Id = $this->s3StorageMap[$s3Uuid]->id;
            }

            ScheduledVolumeBackup::create([
                'uuid' => $uuid,
                'backupable_type' => $volume->getMorphClass(),
                'backupable_id' => $volume->id,
                'team_id' => $teamId,
                's3_storage_id' => $s3Id,
                'frequency' => data_get($backupPayload, 'frequency'),
                'enabled' => (bool) data_get($backupPayload, 'enabled', true),
                'save_s3' => (bool) data_get($backupPayload, 'save_s3', false) && $s3Id !== null,
                'disable_local_backup' => (bool) data_get($backupPayload, 'disable_local_backup', false),
                'stop_during_backup' => (bool) data_get($backupPayload, 'stop_during_backup', false),
                'retention_amount_locally' => data_get($backupPayload, 'retention_amount_locally'),
                'retention_days_locally' => data_get($backupPayload, 'retention_days_locally'),
                'retention_max_storage_locally' => data_get($backupPayload, 'retention_max_storage_locally'),
                'retention_amount_s3' => data_get($backupPayload, 'retention_amount_s3'),
                'retention_days_s3' => data_get($backupPayload, 'retention_days_s3'),
                'retention_max_storage_s3' => data_get($backupPayload, 'retention_max_storage_s3'),
                'timeout' => data_get($backupPayload, 'timeout'),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string, StandaloneDocker|SwarmDocker>  $destinationMap
     */
    private function resolveDestination(?string $uuid, array $destinationMap): StandaloneDocker|SwarmDocker
    {
        if ($uuid && isset($destinationMap[$uuid])) {
            return $destinationMap[$uuid];
        }

        $first = reset($destinationMap);
        if ($first instanceof StandaloneDocker || $first instanceof SwarmDocker) {
            return $first;
        }

        throw new RuntimeException('No destination available for imported resource.');
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function onlyExistingColumns(string $modelClass, array $attributes): array
    {
        /** @var Model $model */
        $model = new $modelClass;
        $columns = Schema::getColumnListing($model->getTable());

        return collect($attributes)
            ->only($columns)
            ->all();
    }
}
