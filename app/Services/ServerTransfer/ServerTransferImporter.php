<?php

namespace App\Services\ServerTransfer;

use App\Enums\GithubRunnerDockerMode;
use App\Enums\ServerRole;
use App\Jobs\ValidateAndInstallServerJob;
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
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\ResourceLink;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\ScheduledVolumeBackup;
use App\Models\SecretManagerLink;
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
use App\Models\StandaloneSqlite;
use App\Models\SwarmDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use App\Rules\DockerImageFormat;
use App\Rules\ValidServerIp;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\ProjectIconStorageService;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        'StandaloneSqlite' => StandaloneSqlite::class,
    ];

    /** @var array<string, PrivateKey> */
    private array $privateKeyMap = [];

    /** @var array<string, GithubApp> */
    private array $githubAppMap = [];

    /** @var array<string, GitlabApp> */
    private array $gitlabAppMap = [];

    /** @var array<string, GiteaApp> */
    private array $giteaAppMap = [];

    /** @var array<string, S3Storage> */
    private array $s3StorageMap = [];

    /** @var array<string, CloudProviderToken> */
    private array $cloudTokenMap = [];

    /** @var array<string, IntegrationToken> */
    private array $integrationTokenMap = [];

    /** @var array<string, DnsProviderZone> */
    private array $dnsZoneMap = [];

    /**
     * Imported owned DNS records that carry the ownership comment of the source instance. This instance takes
     * ownership at the provider after the import transaction commits.
     *
     * @var list<array{record_id: int, previous_comment: string}>
     */
    private array $pendingDnsAdoptions = [];

    /**
     * Icons of imported projects. They are stored after the import transaction commits, so a storage error
     * cannot roll back DB rows.
     *
     * @var list<array{project: Project, icon: array<string, mixed>}>
     */
    private array $pendingProjectIcons = [];

    /** @var array<string, LocalPersistentVolume|LocalFileVolume> */
    private array $volumeMap = [];

    /** @var array<string, Application> */
    private array $applicationMap = [];

    /** @var array<string, ApplicationPreview> */
    private array $previewMap = [];

    /** @var array<string, Service> */
    private array $serviceMap = [];

    /** @var array<string, ServiceApplication> */
    private array $serviceApplicationMap = [];

    /** @var array<string, Model> */
    private array $databaseMap = [];

    /**
     * Imported volumes that mount a SQLite database volume, keyed by volume id. The database may be imported later.
     *
     * @var array<int, array{volume: LocalPersistentVolume, sqlite_uuid: string}>
     */
    private array $pendingSqliteLinks = [];

    /**
     * Warnings about references of imported resources that this instance cannot resolve.
     *
     * @var list<string>
     */
    private array $importWarnings = [];

    /**
     * Applications whose git source needs a check after the transfer: a system-wide source of the source
     * instance, or a source that this instance does not have.
     *
     * @var list<string>
     */
    private array $gitSourceApplications = [];

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
     *     claim: array<string, mixed>|null,
     *     checklist: list<string>
     * }
     */
    public function import(
        array $bundle,
        int $teamId,
        bool $dryRun = false,
        bool $preserveUuids = true,
        bool $adoptMode = true,
    ): array {
        // Checks every id, list size, and imported value before anything is written.
        $validation = ServerTransferBundle::validate($bundle);
        if (! $validation['valid']) {
            throw ValidationException::withMessages(['bundle' => $validation['errors']]);
        }
        $this->assertValidServerPayload((array) data_get($bundle, 'server', []));

        if ($adoptMode && ! $preserveUuids) {
            throw ValidationException::withMessages([
                'preserve_uuids' => ['Adopt mode keeps the running containers, so it needs the original UUIDs. Import with preserve_uuids=true.'],
            ]);
        }

        $warnings = $validation['warnings'];
        if (is_array(data_get($bundle, 'warnings'))) {
            $warnings = array_values(array_unique(array_merge($warnings, $bundle['warnings'])));
        }

        $serverUuid = (string) data_get($bundle, 'server.uuid');
        $serverIp = (string) data_get($bundle, 'server.ip');

        // A server that comes back replaces the archived copy that this instance kept from the first transfer.
        $archive = $preserveUuids ? $this->replaceableArchive($serverUuid, $teamId) : null;
        $uuidConflict = $archive === null && $preserveUuids && Server::withTrashed()->where('uuid', $serverUuid)->exists();
        $ipConflict = Server::where('ip', Server::normalizeIp($serverIp))->when($archive, fn ($query) => $query->whereKeyNot($archive->id))->exists();

        if (! $dryRun) {
            $this->assertCanImport($serverUuid, $serverIp, $teamId, $preserveUuids);
            // The import validates the server over SSH, so it uses the same Cloud limit as a new server.
            Server::throttleValidation($teamId, $serverIp);
        }

        if ($uuidConflict) {
            $warnings[] = "A server with UUID {$serverUuid} already exists on this instance.";
        }
        if ($ipConflict) {
            $warnings[] = "A server with IP/domain {$serverIp} already exists on this instance.";
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
            'gitea_apps' => 0,
            's3_storages' => 0,
            'cloud_provider_tokens' => 0,
            'integration_tokens' => 0,
            'managed_dns_records' => 0,
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
            $created['gitea_apps'] = count(data_get($bundle, 'gitea_apps', []));
            $created['s3_storages'] = count(data_get($bundle, 's3_storages', []));
            $created['cloud_provider_tokens'] = count(data_get($bundle, 'cloud_provider_tokens', []));
            $created['integration_tokens'] = count(data_get($bundle, 'integration_tokens', []));
            $created['managed_dns_records'] = count(data_get($bundle, 'managed_dns_records', []));
            $created['ssl_certificates'] = count(data_get($bundle, 'ssl_certificates', []));
            $created['volume_backups'] = count(data_get($bundle, 'volume_backups', []));

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
                'checklist' => $this->postTransferChecklist($bundle),
            ];
        }

        $result = DB::transaction(function () use ($bundle, $teamId, $preserveUuids, $adoptMode, $warnings, $archive, &$created) {
            // Removed in the same transaction as the import, so a failed import keeps the archive.
            if ($archive) {
                app(ServerTransferClaimer::class)->deleteArchive($archive);
            }
            Team::ensureServerCapacity($teamId);

            $this->privateKeyMap = [];
            $this->githubAppMap = [];
            $this->gitlabAppMap = [];
            $this->giteaAppMap = [];
            $this->s3StorageMap = [];
            $this->cloudTokenMap = [];
            $this->integrationTokenMap = [];
            $this->dnsZoneMap = [];
            $this->pendingDnsAdoptions = [];
            $this->pendingProjectIcons = [];
            $this->volumeMap = [];
            $this->applicationMap = [];
            $this->previewMap = [];
            $this->serviceMap = [];
            $this->serviceApplicationMap = [];
            $this->databaseMap = [];
            $this->pendingSqliteLinks = [];
            $this->importWarnings = [];
            $this->gitSourceApplications = [];

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

            foreach (data_get($bundle, 'gitea_apps', []) as $gtPayload) {
                // Defensive: never import system-wide Gitea Apps from a bundle.
                if ((bool) data_get($gtPayload, 'is_system_wide', false)) {
                    continue;
                }
                $gt = $this->importGiteaApp($gtPayload, $teamId, $preserveUuids);
                $this->giteaAppMap[(string) data_get($gtPayload, 'uuid', $gt->uuid)] = $gt;
                $this->giteaAppMap[$gt->uuid] = $gt;
                $created['gitea_apps']++;
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

            foreach (data_get($bundle, 'integration_tokens', []) as $tokenPayload) {
                $token = $this->importIntegrationToken($tokenPayload, $teamId, $preserveUuids);
                $this->integrationTokenMap[(string) data_get($tokenPayload, 'uuid', $token->uuid)] = $token;
                $this->integrationTokenMap[$token->uuid] = $token;
                $created['integration_tokens']++;
            }

            $serverKeyUuid = (string) data_get($bundle, 'private_key.uuid', '');
            $privateKey = $this->privateKeyMap[$serverKeyUuid]
                ?? $this->importPrivateKey(data_get($bundle, 'private_key', []), $teamId, $preserveUuids);

            $server = $this->importServer(data_get($bundle, 'server', []), $privateKey, $teamId, $preserveUuids, $adoptMode);
            $destinationMap = $this->importDestinations(data_get($bundle, 'destinations', []), $server, $preserveUuids);
            $created['destinations'] = count($destinationMap);
            $this->importGithubRunnerConfig(data_get($bundle, 'github_runner_config'), $server, $teamId, $preserveUuids);

            $this->importServerSharedEnvVars(data_get($bundle, 'shared_environment_variables.server', []), $server, $teamId);
            $this->importTeamSharedEnvVars(data_get($bundle, 'shared_environment_variables.team', []), $teamId);

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

                    $this->importCanvasPositions((array) data_get($environmentPayload, 'canvas_positions', []), $environment);
                    $this->importResourceLinks((array) data_get($environmentPayload, 'resource_links', []), $environment, $preserveUuids);
                }
            }

            $warnings = array_merge($warnings, $this->linkSqliteVolumes());

            $this->importDnsZones(data_get($bundle, 'dns_zones', []), $preserveUuids);
            $created['managed_dns_records'] = $this->importManagedDnsRecords(data_get($bundle, 'managed_dns_records', []), $server, $teamId, $preserveUuids);

            $created['ssl_certificates'] = $this->importSslCertificates(data_get($bundle, 'ssl_certificates', []), $server);
            $created['volume_backups'] = $this->importVolumeBackups(data_get($bundle, 'volume_backups', []), $teamId);
            $warnings = array_merge($warnings, $this->importWarnings);

            $metadata = $server->server_metadata ?? [];
            $metadata['transfer'] = [
                'status' => 'imported',
                'export_id' => data_get($bundle, 'export_id'),
                'source_instance_url' => data_get($bundle, 'source_instance.url'),
                'imported_at' => now()->toIso8601String(),
                'adopt_mode' => $adoptMode,
                'git_source_applications' => array_values(array_unique($this->gitSourceApplications)),
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
                'checklist' => $this->postTransferChecklist($bundle),
            ];
        });

        // Provider and storage calls run after the import transaction commits, so an error cannot roll back DB rows.
        $result['warnings'] = array_values(array_unique(array_merge($result['warnings'], $this->adoptManagedDnsRecords(), $this->storeProjectIcons())));

        // Claim after the import transaction commits so a claim failure cannot roll back DB rows.
        if (filled(data_get($result, 'server_uuid'))) {
            $server = Server::where('uuid', $result['server_uuid'])->where('team_id', $teamId)->first();
            if ($server) {
                try {
                    $result['claim'] = app(ServerTransferClaimer::class)->claim($server);
                    $result['claimed'] = true;
                    // Same check as the Validate button. A running proxy and Docker are kept as they are.
                    ValidateAndInstallServerJob::dispatch($server);
                    $this->startFinishChecklist($server);
                    $result['server_url'] = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/').'/server/'.$server->uuid;
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
     * Check the server connection fields with the same rules as the create server API.
     *
     * @param  array<string, mixed>  $server
     */
    private function assertValidServerPayload(array $server): void
    {
        Validator::make($server, [
            'name' => 'nullable|string|max:255',
            'ip' => ['required', 'string', new ValidServerIp],
            'port' => 'required|integer|between:1,65535',
            'user' => ValidationPatterns::serverUsernameRules(required: false),
        ], [], [
            'ip' => 'server.ip',
            'port' => 'server.port',
            'user' => 'server.user',
        ])->validate();
    }

    /**
     * Checks that a server can be imported into the team, without the bundle. Every check runs, so the
     * source instance can show which checks are OK and which failed.
     *
     * @return list<array{field: string, label: string, ok: bool, message: string|null}>
     */
    public function importChecks(string $serverUuid, string $serverIp, int $teamId, bool $preserveUuids = true): array
    {
        $team = Team::find($teamId);
        $archive = $preserveUuids ? $this->replaceableArchive($serverUuid, $teamId) : null;
        // An archive does not use a server slot, so replacing it also needs a free slot.
        $limitReached = ! $team || Team::serverLimitReached($team);

        $existingServer = $preserveUuids && $archive === null ? Server::withTrashed()->where('uuid', $serverUuid)->first() : null;
        $uuidMessage = match (true) {
            $existingServer === null => null,
            $existingServer->trashed() => "The server with UUID {$serverUuid} is still being deleted on this instance. Wait a minute, then transfer again.",
            $existingServer->isTransferredAway() => 'Another team of this instance has the archived copy of this server. Transfer the server into that team, or delete the archive there first.',
            default => "A server with UUID {$serverUuid} already exists on this instance. Delete/transfer it first, or import with preserve_uuids=false.",
        };

        $ipExists = Server::where('ip', Server::normalizeIp($serverIp))->when($archive, fn ($query) => $query->whereKeyNot($archive->id))->exists();

        return [
            [
                'field' => 'server',
                'label' => 'the team can have one more server',
                'ok' => ! $limitReached,
                'message' => $limitReached ? 'Server limit reached for your subscription.' : null,
            ],
            [
                'field' => 'server.uuid',
                'label' => $archive ? 'the archived copy of this server on this instance is replaced' : 'no server with the same UUID exists',
                'ok' => $uuidMessage === null,
                'message' => $uuidMessage,
            ],
            [
                'field' => 'server.ip',
                'label' => 'no server with the same IP exists',
                'ok' => ! $ipExists,
                'message' => $ipExists ? "A server with IP/domain {$serverIp} already exists on this instance. Remove it first." : null,
            ],
        ];
    }

    /**
     * The steps that finish the transfer here, and one notification that points the team to them.
     * A failure here never fails the import: the server is imported and claimed already.
     */
    private function startFinishChecklist(Server $server): void
    {
        try {
            $items = app(TransferFinishChecklist::class)->start($server);
            $server->team?->notify(new GeneralNotification(
                "Server '{$server->name}' was transferred to this instance. Open the server to finish the transfer: ".count($items).' steps to check.'
            ));
        } catch (Throwable $e) {
            Log::warning('Could not start the transfer finish checklist', ['server_uuid' => $server->uuid, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The archive that this instance kept when it transferred the server away. A transfer back into the same team
     * replaces it.
     */
    private function replaceableArchive(string $serverUuid, int $teamId): ?Server
    {
        $server = Server::query()->where('uuid', $serverUuid)->where('team_id', $teamId)->first();

        return $server?->isTransferredAway() ? $server : null;
    }

    /**
     * @throws ValidationException with the first failed import check
     */
    public function assertCanImport(string $serverUuid, string $serverIp, int $teamId, bool $preserveUuids = true): void
    {
        foreach ($this->importChecks($serverUuid, $serverIp, $teamId, $preserveUuids) as $check) {
            if (! $check['ok']) {
                throw ValidationException::withMessages([$check['field'] => [$check['message']]]);
            }
        }
    }

    /**
     * Running containers are found by the resource UUID. In adopt mode a new UUID would leave them
     * without a resource, so a conflict stops the import instead.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function resolveResourceUuid(string $modelClass, string $uuid, bool $adoptMode, string $label): string
    {
        if (! $modelClass::withTrashed()->where('uuid', $uuid)->exists()) {
            return $uuid;
        }

        if ($adoptMode) {
            throw ValidationException::withMessages([
                'bundle' => ["The {$label} UUID {$uuid} cannot be used on this instance. Adopt mode needs the same UUID to keep the running containers. If you deleted the {$label} on this instance, delete it permanently first."],
            ]);
        }

        return new_public_id();
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
            // Keys are unique per team, so only a key of the target team can be reused.
            $existing = PrivateKey::query()
                ->where('team_id', $teamId)
                ->where('fingerprint', $fingerprint)
                ->first();
            if ($existing) {
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
            'hetzner_server_id' => data_get($payload, 'hetzner_server_id'),
            'vultr_instance_id' => data_get($payload, 'vultr_instance_id'),
            'digitalocean_droplet_id' => data_get($payload, 'digitalocean_droplet_id'),
            'hostinger_virtual_machine_id' => data_get($payload, 'hostinger_virtual_machine_id'),
            'proxy_error_page' => data_get($payload, 'proxy_error_page'),
            'swarm_cluster' => data_get($payload, 'swarm_cluster'),
        ]);
        $server->uuid = $uuid;
        $server->save();

        if ($server->settings) {
            $serverRole = data_get($payload, 'server_role');
            if (! in_array($serverRole, array_column(ServerRole::cases(), 'value'), true)) {
                $serverRole = data_get($payload, 'is_build_server') ? ServerRole::BUILD->value : ServerRole::BOTH->value;
            }
            $server->settings->server_role = $serverRole;
            $server->settings->is_build_server = $serverRole === ServerRole::BUILD->value;
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
                    // A foreign key of the source instance; older bundles still contain it.
                    'cloudflare_tunnel_integration_token_id',
                    'cloudflare_tunnel_integration_token_uuid',
                    'patch_check_result', 'patch_check_error', 'patch_checked_at',
                ])
                ->all();
            $server->settings->fill($safe);
            $server->settings->cloudflare_tunnel_integration_token_id = $this->importedIntegrationTokenId(
                data_get($settingsPayload, 'cloudflare_tunnel_integration_token_uuid'),
                'The Cloudflare tunnel of this server: its Cloudflare API token was not found on this instance; select it again in the Cloudflare tunnel settings.',
            );
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
        // The created hook of the server made a default destination; the server gets only the destinations of the bundle.
        StandaloneDocker::query()->whereKey($existingStandalone->slice($standaloneIndex)->modelKeys())->delete();
        SwarmDocker::query()->whereKey($existingSwarm->slice($swarmIndex)->modelKeys())->delete();

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $variables
     */
    private function importServerSharedEnvVars(array $variables, Server $server, int $teamId): void
    {
        foreach ($variables as $variable) {
            $this->createSharedVariable($variable, ['type' => 'server', 'server_id' => $server->id, 'team_id' => $teamId]);
        }
    }

    /**
     * A team variable that already exists keeps the value of this instance.
     *
     * @param  list<array<string, mixed>>  $variables
     */
    private function importTeamSharedEnvVars(array $variables, int $teamId): void
    {
        foreach ($variables as $variable) {
            $key = (string) data_get($variable, 'key');
            $exists = SharedEnvironmentVariable::query()
                ->where('type', 'team')
                ->where('team_id', $teamId)
                ->where('key', $key)
                ->exists();
            if ($exists) {
                $this->importWarnings[] = "Team shared variable {$key} already exists on this instance. It keeps its value here, which can differ from the value on the source instance.";

                continue;
            }
            $this->createSharedVariable($variable, ['type' => 'team', 'team_id' => $teamId]);
        }
    }

    /**
     * @param  array<string, mixed>  $variable  A shared variable of the bundle.
     * @param  array<string, mixed>  $scope  The type, the team, and the server, project, or environment of the variable.
     */
    private function createSharedVariable(array $variable, array $scope): void
    {
        $shared = new SharedEnvironmentVariable([
            'value' => data_get($variable, 'value'),
            'is_multiline' => (bool) data_get($variable, 'is_multiline', false),
            'is_literal' => (bool) data_get($variable, 'is_literal', false),
            'is_shown_once' => (bool) data_get($variable, 'is_shown_once', false),
            'comment' => data_get($variable, 'comment'),
            ...$scope,
        ]);
        $this->keepVariableKey($shared, (string) data_get($variable, 'key'));
        $shared->save();
    }

    /**
     * Sets the key of a variable as it is on the source. The key setter rejects names that only older Coolify
     * versions accepted (such as my-var); deployments skip those with a warning, so the transfer keeps them.
     */
    private function keepVariableKey(EnvironmentVariable|SharedEnvironmentVariable $variable, string $key): void
    {
        $variable->setRawAttributes(['key' => $key] + $variable->getAttributes());
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

        $icon = data_get($payload, 'icon');
        $existing = Project::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            $this->importProjectSharedEnvVars(data_get($payload, 'shared_environment_variables', []), $existing, $teamId);
            // An icon that the project already has on this instance is kept.
            if (is_array($icon) && blank($existing->icon_path)) {
                $this->pendingProjectIcons[] = ['project' => $existing, 'icon' => $icon];
            }

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

        $project = $project->fresh(['environments']);
        if (is_array($icon)) {
            $this->pendingProjectIcons[] = ['project' => $project, 'icon' => $icon];
        }

        return $project;
    }

    /**
     * Stores the icons of the imported projects in the icon storage of this instance. Coolify stores every
     * icon as a JPEG file and serves it as one, so other content is not stored.
     *
     * @return list<string> warnings
     */
    private function storeProjectIcons(): array
    {
        $warnings = [];
        foreach ($this->pendingProjectIcons as ['project' => $project, 'icon' => $icon]) {
            $contents = base64_decode((string) data_get($icon, 'content_base64', ''), true);
            $problem = match (true) {
                ! is_string($contents) || $contents === '' => 'its content is not valid',
                strlen($contents) > ServerTransferExporter::MAX_PROJECT_ICON_BYTES => 'it is larger than 2 MB',
                (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) !== 'image/jpeg' => 'it is not a JPEG image',
                default => null,
            };
            if ($problem === null) {
                try {
                    app(ProjectIconStorageService::class)->storeProjectContents($project, $contents);

                    continue;
                } catch (Throwable) {
                    $problem = 'the icon storage of this instance is not available';
                }
            }
            $warnings[] = "The icon of project {$project->name} was not imported, because {$problem}. Upload it again in the project settings.";
        }
        $this->pendingProjectIcons = [];

        return $warnings;
    }

    /**
     * Manual steps after the transfer, with the URL of this instance in place of {target_url}.
     *
     * @param  array<string, mixed>  $bundle
     * @return list<string>
     */
    private function postTransferChecklist(array $bundle): array
    {
        $targetUrl = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');

        return array_values(array_unique(array_map(
            fn (string $step) => str_replace('{target_url}', $targetUrl, $step),
            array_filter((array) data_get($bundle, 'post_transfer_checklist', []), 'is_string'),
        )));
    }

    /**
     * The config is imported disabled: an enabled config makes this instance remove every runner container on
     * the server that it does not know, also the runners that the source started. The user enables it after
     * the GitHub App sends its webhooks here.
     */
    private function importGithubRunnerConfig(mixed $payload, Server $server, int $teamId, bool $preserveUuids): void
    {
        if (! is_array($payload)) {
            return;
        }

        $appUuid = (string) data_get($payload, 'github_app_uuid', '');
        $githubApp = $appUuid === '' ? null : ($this->githubAppMap[$appUuid]
            ?? GithubApp::query()->where('uuid', $appUuid)->where('is_system_wide', true)->first()
            ?? GithubApp::query()->where('uuid', $appUuid)->where('team_id', $teamId)->first());
        if (! $githubApp) {
            $this->importWarnings[] = 'The GitHub runner settings of this server were not imported, because their GitHub App was not found on this instance. Set up GitHub runners on the server again.';

            return;
        }

        $validator = Validator::make($payload, [
            'labels' => ['required', 'array', 'min:1'],
            'labels.*' => ['string', 'regex:/\A[a-z0-9][a-z0-9._-]{0,99}\z/'],
            'max_runners' => ['required', 'integer', 'min:1', 'max:32'],
            'docker_mode' => ['required', Rule::enum(GithubRunnerDockerMode::class)],
            'runner_image' => ['nullable', 'string', 'max:255', new DockerImageFormat],
            'cpu_limit' => ['nullable', 'string', 'regex:/\A\d+(\.\d+)?\z/'],
            'memory_limit' => ['nullable', 'string', 'regex:/\A\d+[bkmg]?\z/i'],
            'capacity_wait_timeout' => ['required', 'integer', 'min:1', 'max:1440'],
            'idle_timeout' => ['required', 'integer', 'min:1', 'max:1440'],
            'job_timeout' => ['required', 'integer', 'min:1', 'max:7200'],
        ]);
        if ($validator->fails()) {
            $this->importWarnings[] = 'The GitHub runner settings of this server were not imported, because they are not valid: '.$validator->errors()->first().' Set up GitHub runners on the server again.';

            return;
        }

        $uuid = (string) data_get($payload, 'uuid', '');
        $config = new GithubRunnerConfig;
        $config->forceFill([
            'server_id' => $server->id,
            'github_app_id' => $githubApp->id,
            'labels' => array_values($payload['labels']),
            'is_enabled' => false,
            'is_dedicated' => (bool) data_get($payload, 'is_dedicated', false),
            'allow_pull_requests' => (bool) data_get($payload, 'allow_pull_requests', false),
            'max_runners' => (int) $payload['max_runners'],
            'docker_mode' => GithubRunnerDockerMode::from($payload['docker_mode']),
            'runner_image' => data_get($payload, 'runner_image'),
            'cpu_limit' => data_get($payload, 'cpu_limit'),
            'memory_limit' => data_get($payload, 'memory_limit'),
            'capacity_wait_timeout' => (int) $payload['capacity_wait_timeout'],
            'idle_timeout' => (int) $payload['idle_timeout'],
            'job_timeout' => (int) $payload['job_timeout'],
        ]);
        if ($preserveUuids && $uuid !== '' && ! GithubRunnerConfig::where('uuid', $uuid)->exists()) {
            $config->uuid = $uuid;
        }
        $config->save();
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
            $this->createSharedVariable($variable, ['type' => 'project', 'project_id' => $project->id, 'team_id' => $teamId]);
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
            $this->createSharedVariable($variable, ['type' => 'environment', 'environment_id' => $environment->id, 'team_id' => $teamId]);
        }
    }

    /**
     * Canvas positions are keyed by resource uuid. Resources may get a new uuid on import.
     *
     * @param  array<string, mixed>  $positions
     */
    private function importCanvasPositions(array $positions, Environment $environment): void
    {
        $imported = [];
        foreach ($positions as $uuid => $position) {
            $resource = $this->applicationMap[$uuid] ?? $this->databaseMap[$uuid] ?? $this->serviceMap[$uuid] ?? null;
            if ($resource) {
                $imported[$resource->uuid] = $position;
            }
        }
        if ($imported === []) {
            return;
        }

        $environment->canvas_positions = array_merge($environment->canvas_positions ?? [], $imported);
        $environment->save();
    }

    /**
     * Links between two imported resources of the environment. The user who drew the link is a user of the
     * source instance, so created_by stays empty.
     *
     * @param  list<array<string, mixed>>  $links
     */
    private function importResourceLinks(array $links, Environment $environment, bool $preserveUuids): void
    {
        foreach ($links as $link) {
            $source = $this->linkedResource(data_get($link, 'source'));
            $target = $this->linkedResource(data_get($link, 'target'));
            if (! $source || ! $target || $source->is($target)
                || (int) $source->environment_id !== $environment->id || (int) $target->environment_id !== $environment->id) {
                $this->importWarnings[] = "A link between resources on the canvas of environment {$environment->name} was not imported, because one of its resources was not imported.";

                continue;
            }

            $attributes = [
                'environment_id' => $environment->id,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
            ];
            if (ResourceLink::query()->where($attributes)->exists()) {
                continue;
            }

            $label = data_get($link, 'label');
            $resourceLink = new ResourceLink([
                ...$attributes,
                'label' => is_string($label) && trim($label) !== '' ? mb_substr(trim($label), 0, 64) : null,
                'created_by' => null,
            ]);
            $uuid = (string) data_get($link, 'uuid', '');
            if ($preserveUuids && $uuid !== '' && ! ResourceLink::where('uuid', $uuid)->exists()) {
                $resourceLink->uuid = $uuid;
            }
            $resourceLink->save();
        }
    }

    /**
     * An end of a canvas link: the kind and the original uuid of an imported resource.
     */
    private function linkedResource(mixed $end): ?Model
    {
        $uuid = (string) data_get($end, 'uuid', '');

        return match (data_get($end, 'kind')) {
            'application' => $this->applicationMap[$uuid] ?? null,
            'database' => $this->databaseMap[$uuid] ?? null,
            'service' => $this->serviceMap[$uuid] ?? null,
            default => null,
        };
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

        $uuid = $this->resolveResourceUuid(Application::class, $uuid, $adoptMode, 'application');

        $attributes = (array) data_get($payload, 'attributes', []);
        unset($attributes['id'], $attributes['environment_id'], $attributes['destination_id'], $attributes['destination_type'], $attributes['source_id'], $attributes['source_type'], $attributes['private_key_id']);
        $attributes = $this->onlyExistingColumns(Application::class, $attributes);

        $attributes['uuid'] = $uuid;
        $attributes['environment_id'] = $environment->id;
        $attributes['destination_id'] = $destination->id;
        $attributes['destination_type'] = $destination->getMorphClass();

        $name = (string) data_get($attributes, 'name', $uuid);

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
            } elseif ($sourceType === 'gitea_app') {
                $gt = $this->giteaAppMap[$sourceUuid]
                    ?? GiteaApp::query()->where('uuid', $sourceUuid)->where('is_system_wide', true)->first()
                    ?? GiteaApp::query()->where('uuid', $sourceUuid)->where('team_id', $teamId)->first();
                if ($gt) {
                    $attributes['source_type'] = GiteaApp::class;
                    $attributes['source_id'] = $gt->id;
                }
            }
        }
        $sourceMissing = data_get($payload, 'had_git_source') && ! isset($attributes['source_id']);
        if ($sourceMissing || data_get($payload, 'source.system_wide') === true) {
            $this->gitSourceApplications[] = $uuid;
        }
        if ($sourceMissing) {
            $sourceLabel = match (data_get($payload, 'source.type')) {
                'github_app' => 'GitHub App',
                'gitlab_app' => 'GitLab App',
                'gitea_app' => 'Gitea App',
                default => 'git source',
            };
            $this->importWarnings[] = "Application {$name}: its {$sourceLabel} was not found on this instance; set the source again.";
        }

        // Re-link deploy key
        $appKeyUuid = data_get($payload, 'private_key_uuid');
        if ($appKeyUuid && isset($this->privateKeyMap[$appKeyUuid])) {
            $attributes['private_key_id'] = $this->privateKeyMap[$appKeyUuid]->id;
        }
        if (data_get($payload, 'had_private_key') && ! isset($attributes['private_key_id'])) {
            $this->importWarnings[] = "Application {$name}: its private key was not found on this instance; select the private key again.";
        }

        if ($adoptMode) {
            // Keep runtime status if provided so inventory can match, but default exited if empty.
            $attributes['status'] = data_get($attributes, 'status') ?: 'exited';
        } else {
            $attributes['status'] = 'exited';
        }

        $application = new Application($attributes);
        // Maintenance mode is not fillable.
        $application->forceFill(collect($attributes)->only(['is_maintenance_enabled', 'maintenance_page'])->all());
        $application->save();
        // The created hook gives a new application the newest compose parser and default variables. A transferred
        // application keeps its parser, which names its volumes and domains, and gets only the variables of the bundle.
        $application->forceFill(collect($attributes)->only('compose_parsing_version')->filter()->all())->saveQuietly();
        EnvironmentVariable::query()->whereMorphedTo('resourceable', $application)->delete();

        $settings = (array) data_get($payload, 'settings', []);
        if ($settings !== [] && $application->settings) {
            $portsExposes = $application->ports_exposes;
            $application->settings->fill(collect($settings)->except(['id', 'application_id'])->all());
            $application->settings->save();
            // The is_static setter sets the port of a static application to 80; the user can change it after that.
            $application->refresh()->forceFill(['ports_exposes' => $portsExposes])->saveQuietly();
        }

        $this->importEnvVars(data_get($payload, 'environment_variables', []), $application, false);
        $this->importEnvVars(data_get($payload, 'environment_variables_preview', []), $application, true);
        $this->importPersistentStorages(data_get($payload, 'persistent_storages', []), $application);
        $this->importFileStorages(data_get($payload, 'file_storages', []), $application);
        $this->importScheduledTasks(data_get($payload, 'scheduled_tasks', []), $application, $teamId);
        $this->importTags(data_get($payload, 'tags', []), $application, $teamId);
        $this->importPreviews(data_get($payload, 'previews', []), $application, $preserveUuids, $adoptMode, $teamId);
        $this->importSecretManagerLink(data_get($payload, 'secret_manager_link'), $application, "Application {$name}");

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
        $modelClass = self::DATABASE_MODELS[$type] ?? null;

        if (! $modelClass) {
            throw new RuntimeException("Unsupported database type: {$type}");
        }

        $destination = $this->resolveDestination(data_get($payload, 'destination_uuid'), $destinationMap);
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $uuid = $this->resolveResourceUuid($modelClass, $uuid, $adoptMode, 'database');

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
        $this->importSecretManagerLink(data_get($payload, 'secret_manager_link'), $database, "Database {$database->name}");

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

        $uuid = $this->resolveResourceUuid(Service::class, $uuid, $adoptMode, 'service');

        $attributes = (array) data_get($payload, 'attributes', []);
        unset($attributes['id'], $attributes['environment_id'], $attributes['destination_id'], $attributes['destination_type'], $attributes['server_id']);
        $attributes = $this->onlyExistingColumns(Service::class, $attributes);
        $attributes['uuid'] = $uuid;
        $attributes['environment_id'] = $environment->id;
        $attributes['server_id'] = $server->id;
        $attributes['destination_id'] = $destination->id;
        $attributes['destination_type'] = $destination->getMorphClass();

        $service = new Service($attributes);
        // Maintenance mode and the Coolify default variables switch are not fillable.
        $service->forceFill(collect($attributes)->only(['is_maintenance_enabled', 'maintenance_page', 'include_coolify_default_variables'])->all());
        $service->save();
        // The created hook gives a new service the newest compose parser; a transferred service keeps its own.
        $service->forceFill(collect($attributes)->only('compose_parsing_version')->filter()->all())->saveQuietly();

        $this->importEnvVars(data_get($payload, 'environment_variables', []), $service, false);
        $this->importScheduledTasks(data_get($payload, 'scheduled_tasks', []), $service, $teamId);
        $this->importTags(data_get($payload, 'tags', []), $service, $teamId);
        $this->importSecretManagerLink(data_get($payload, 'secret_manager_link'), $service, "Service {$service->name}");

        foreach (data_get($payload, 'applications', []) as $appPayload) {
            $serviceApp = $this->importServiceApplication($appPayload, $service, $preserveUuids, $adoptMode);
            $this->serviceApplicationMap[(string) data_get($appPayload, 'uuid', $serviceApp->uuid)] = $serviceApp;
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
                    'value' => data_get($variable, 'value'),
                    'is_literal' => (bool) data_get($variable, 'is_literal', false),
                    'is_multiline' => (bool) data_get($variable, 'is_multiline', false),
                    'is_preview' => $isPreview || (bool) data_get($variable, 'is_preview', false),
                    'is_runtime' => (bool) data_get($variable, 'is_runtime', true),
                    'is_buildtime' => (bool) data_get($variable, 'is_buildtime', true),
                    'is_shown_once' => (bool) data_get($variable, 'is_shown_once', false),
                    'is_shared' => (bool) data_get($variable, 'is_shared', false),
                    'is_required' => (bool) data_get($variable, 'is_required', false),
                    // Exports from older versions have no flag; their values use the old escaping.
                    'uses_legacy_escaping' => (bool) data_get($variable, 'uses_legacy_escaping', true),
                    'preserve_compose_null' => (bool) data_get($variable, 'preserve_compose_null', false),
                    'comment' => data_get($variable, 'comment'),
                    'order' => data_get($variable, 'order'),
                    'resourceable_type' => $resource->getMorphClass(),
                    'resourceable_id' => $resource->id,
                ]);
                $this->keepVariableKey($env, (string) data_get($variable, 'key'));
                // Older bundles have no version; the column default applies.
                if (filled(data_get($variable, 'version'))) {
                    $env->version = (string) data_get($variable, 'version');
                }
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
                // The server keeps its Docker volumes. A bundle without the flag is from a Coolify version that created them without driver options.
                'ignores_compose_driver_options' => (bool) data_get($volume, 'ignores_compose_driver_options', true),
                'resource_type' => $resource->getMorphClass(),
                'resource_id' => $resource->id,
            ]);
            $volumeModel->uuid = $uuid;
            $volumeModel->save();
            $this->volumeMap[$uuid] = $volumeModel;
            if (filled(data_get($volume, 'uuid'))) {
                $this->volumeMap[(string) data_get($volume, 'uuid')] = $volumeModel;
            }
            if (filled(data_get($volume, 'standalone_sqlite_uuid'))) {
                $this->pendingSqliteLinks[$volumeModel->id] = [
                    'volume' => $volumeModel,
                    'sqlite_uuid' => (string) data_get($volume, 'standalone_sqlite_uuid'),
                ];
            }
        }
    }

    /**
     * Connect imported volumes to the imported SQLite database whose data volume they mount.
     *
     * @return list<string> warnings for volumes whose database is not in the bundle
     */
    private function linkSqliteVolumes(): array
    {
        $warnings = [];
        foreach ($this->pendingSqliteLinks as $link) {
            $sqlite = $this->databaseMap[$link['sqlite_uuid']] ?? null;
            if (! $sqlite instanceof StandaloneSqlite) {
                $warnings[] = "Volume {$link['volume']->name} mounts a SQLite database that is not part of this transfer. Coolify does not protect it when that database or its applications are deleted.";

                continue;
            }

            $link['volume']->forceFill(['standalone_sqlite_id' => $sqlite->id])->save();
        }

        return $warnings;
    }

    /**
     * @param  list<array<string, mixed>>  $storages
     */
    private function importFileStorages(array $storages, object $resource): void
    {
        foreach ($storages as $storage) {
            LocalFileVolume::withoutEvents(function () use ($storage, $resource) {
                $isHostFile = (bool) data_get($storage, 'is_host_file', false);
                $fsPath = data_get($storage, 'fs_path');
                if (! is_string($fsPath)) {
                    throw new RuntimeException('Invalid imported file storage path.');
                }
                if ($isHostFile) {
                    $fsPath = validateHostFileMountPath($fsPath, 'imported host file source path');
                }
                $chown = data_get($storage, 'chown');
                $chmod = data_get($storage, 'chmod');
                if (filled($chown) && (! is_string($chown) || ! preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_.-]*|[0-9]+)(?::(?:[A-Za-z_][A-Za-z0-9_.-]*|[0-9]+))?\z/', $chown))) {
                    throw new RuntimeException('Invalid imported file owner.');
                }
                if (filled($chmod) && (! is_string($chmod) || ! preg_match('/\A[0-7]{3,4}\z/', $chmod))) {
                    throw new RuntimeException('Invalid imported file mode.');
                }
                $uuid = filled(data_get($storage, 'uuid')) ? (string) data_get($storage, 'uuid') : new_public_id();
                if (LocalFileVolume::where('uuid', $uuid)->exists()) {
                    $uuid = new_public_id();
                }

                // uuid is not fillable and withoutEvents skips BaseModel's creating hook.
                $file = new LocalFileVolume;
                $file->forceFill([
                    'fs_path' => $fsPath,
                    'mount_path' => data_get($storage, 'mount_path'),
                    'content' => data_get($storage, 'content'),
                    'is_directory' => (bool) data_get($storage, 'is_directory', false),
                    'is_host_file' => $isHostFile,
                    'chown' => $chown,
                    'chmod' => $chmod,
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

            $s3Ids = $this->importedBackupS3StorageIds($backup);
            $s3Id = $s3Ids[0] ?? null;
            $this->warnAboutMissingS3Storage($backup, $s3Id, "database {$database->name}");

            $importedBackup = ScheduledDatabaseBackup::create([
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
                'missing_backup_notification_days' => (int) data_get($backup, 'missing_backup_notification_days', 0),
                's3_storage_id' => $s3Id,
                'database_type' => $database->getMorphClass(),
                'database_id' => $database->id,
            ]);
            $importedBackup->syncS3Storages($s3Ids);
        }
    }

    /**
     * @param  array<string, mixed>  $backup
     */
    private function warnAboutMissingS3Storage(array $backup, ?int $s3Id, string $backupOf): void
    {
        if (! data_get($backup, 'had_s3_storage') || $s3Id !== null) {
            return;
        }

        $this->importWarnings[] = data_get($backup, 'save_s3')
            ? "Backup of {$backupOf}: S3 upload was turned off because its S3 storage was not found."
            : "Backup of {$backupOf}: its S3 storage was not found; select it again.";
    }

    /**
     * Maps the exported S3 destinations of a backup to imported storages, primary first. Older bundles only have
     * `s3_storage_uuid`.
     *
     * @param  array<string, mixed>  $backup
     * @return list<int>
     */
    private function importedBackupS3StorageIds(array $backup): array
    {
        $uuids = collect(data_get($backup, 's3_storage_uuids', []))
            ->prepend(data_get($backup, 's3_storage_uuid'))
            ->filter(fn ($uuid): bool => is_string($uuid) && isset($this->s3StorageMap[$uuid]));

        return $uuids->map(fn (string $uuid): int => $this->s3StorageMap[$uuid]->id)->unique()->values()->all();
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
            'organization_self_hosted_runners' => data_get($payload, 'organization_self_hosted_runners'),
            'actions' => data_get($payload, 'actions'),
            'webhook_events' => data_get($payload, 'webhook_events'),
            'runner_group_id' => data_get($payload, 'runner_group_id'),
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
    private function importGiteaApp(array $payload, int $teamId, bool $preserveUuids): GiteaApp
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $existing = GiteaApp::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            return $existing;
        }
        if (GiteaApp::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $privateKeyId = null;
        $keyUuid = data_get($payload, 'private_key_uuid');
        if ($keyUuid && isset($this->privateKeyMap[$keyUuid])) {
            $privateKeyId = $this->privateKeyMap[$keyUuid]->id;
        }

        $app = new GiteaApp;
        $app->forceFill([
            'team_id' => $teamId,
            'private_key_id' => $privateKeyId,
            'name' => data_get($payload, 'name') ?: 'Imported Gitea App',
            'organization' => data_get($payload, 'organization'),
            'api_url' => data_get($payload, 'api_url'),
            'html_url' => data_get($payload, 'html_url'),
            'custom_port' => data_get($payload, 'custom_port') ?? 22,
            'custom_user' => data_get($payload, 'custom_user') ?? 'git',
            'is_system_wide' => false,
            'is_public' => (bool) data_get($payload, 'is_public', false),
            'client_id' => data_get($payload, 'client_id'),
            'client_secret' => data_get($payload, 'client_secret'),
            'access_token' => data_get($payload, 'access_token'),
            'refresh_token' => data_get($payload, 'refresh_token'),
            'expires_at' => data_get($payload, 'expires_at'),
            'redirect_uri' => data_get($payload, 'redirect_uri'),
            'webhook_secret' => data_get($payload, 'webhook_secret'),
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
     * A token with the same UUID in the target team is reused. A UUID that another team uses gets a new UUID.
     *
     * @param  array<string, mixed>  $payload
     */
    private function importIntegrationToken(array $payload, int $teamId, bool $preserveUuids): IntegrationToken
    {
        $uuid = $preserveUuids && filled(data_get($payload, 'uuid'))
            ? (string) data_get($payload, 'uuid')
            : new_public_id();

        $existing = IntegrationToken::where('uuid', $uuid)->where('team_id', $teamId)->first();
        if ($existing) {
            return $existing;
        }
        if (IntegrationToken::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $token = new IntegrationToken;
        $token->forceFill([
            'team_id' => $teamId,
            'provider' => (string) data_get($payload, 'provider'),
            'name' => data_get($payload, 'name') ?: 'Imported integration token',
            'token' => (string) data_get($payload, 'token'),
            'capabilities' => (array) data_get($payload, 'capabilities', []),
            'metadata' => data_get($payload, 'metadata'),
        ]);
        $token->uuid = $uuid;
        $token->save();

        return $token;
    }

    /**
     * The ID of the imported token, or null with a warning when the bundle names a token that it does not contain.
     */
    private function importedIntegrationTokenId(mixed $tokenUuid, string $missingWarning): ?int
    {
        if (! is_string($tokenUuid) || $tokenUuid === '') {
            return null;
        }
        if (! isset($this->integrationTokenMap[$tokenUuid])) {
            $this->importWarnings[] = $missingWarning;

            return null;
        }

        return $this->integrationTokenMap[$tokenUuid]->id;
    }

    private function importSecretManagerLink(mixed $payload, Model $resource, string $label): void
    {
        if (! is_array($payload) || ! method_exists($resource, 'secretManagerLink')) {
            return;
        }

        $tokenId = $this->importedIntegrationTokenId(
            data_get($payload, 'integration_token_uuid'),
            "{$label}: its secret manager token was not found on this instance; link the secret manager source again.",
        );
        if ($tokenId === null) {
            return;
        }

        $uuid = filled(data_get($payload, 'uuid')) ? (string) data_get($payload, 'uuid') : new_public_id();
        if (SecretManagerLink::where('uuid', $uuid)->exists()) {
            $uuid = new_public_id();
        }

        $link = new SecretManagerLink;
        $link->forceFill([
            'resourceable_type' => $resource->getMorphClass(),
            'resourceable_id' => $resource->getKey(),
            'integration_token_id' => $tokenId,
            'settings' => data_get($payload, 'settings'),
        ]);
        $link->uuid = $uuid;
        $link->save();
    }

    /**
     * A zone of the same token and provider zone that the target team already has is reused.
     *
     * @param  list<array<string, mixed>>  $zones
     */
    private function importDnsZones(array $zones, bool $preserveUuids): void
    {
        foreach ($zones as $payload) {
            $token = $this->integrationTokenMap[(string) data_get($payload, 'integration_token_uuid')] ?? null;
            if (! $token) {
                continue;
            }

            $zone = DnsProviderZone::query()
                ->where('integration_token_id', $token->id)
                ->where('provider_zone_id', (string) data_get($payload, 'provider_zone_id'))
                ->first();
            if (! $zone) {
                $uuid = $preserveUuids && filled(data_get($payload, 'uuid')) ? (string) data_get($payload, 'uuid') : new_public_id();
                if (DnsProviderZone::where('uuid', $uuid)->exists()) {
                    $uuid = new_public_id();
                }
                $zone = new DnsProviderZone;
                $zone->forceFill([
                    'integration_token_id' => $token->id,
                    'provider_zone_id' => (string) data_get($payload, 'provider_zone_id'),
                    'name' => strtolower((string) data_get($payload, 'name')),
                    'account_id' => data_get($payload, 'account_id'),
                    'account_name' => data_get($payload, 'account_name'),
                ]);
                $zone->uuid = $uuid;
                $zone->save();
            }

            $this->dnsZoneMap[(string) data_get($payload, 'uuid', $zone->uuid)] = $zone;
        }
    }

    /**
     * Imports the managed DNS records and links them to the imported resources. An owned record keeps the ownership
     * comment of the source at the provider until adoptManagedDnsRecords() replaces it.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function importManagedDnsRecords(array $records, Server $server, int $teamId, bool $preserveUuids): int
    {
        $count = 0;
        foreach ($records as $payload) {
            $name = (string) data_get($payload, 'name');
            $zone = $this->dnsZoneMap[(string) data_get($payload, 'dns_provider_zone_uuid')] ?? null;
            $resources = collect(data_get($payload, 'references', []))
                ->map(fn ($reference) => match (data_get($reference, 'kind')) {
                    'application' => $this->applicationMap[(string) data_get($reference, 'uuid')] ?? null,
                    'preview' => $this->previewMap[(string) data_get($reference, 'uuid')] ?? null,
                    'service_application' => $this->serviceApplicationMap[(string) data_get($reference, 'uuid')] ?? null,
                    'server' => $server,
                    default => null,
                })
                ->filter()
                ->values();
            if (! $zone || $resources->isEmpty()) {
                $this->importWarnings[] = "DNS record {$name}: its DNS zone or resources were not imported, so this instance does not manage it.";

                continue;
            }

            $record = ManagedDnsRecord::query()
                ->where('dns_provider_zone_id', $zone->id)
                ->where('provider_record_id', (string) data_get($payload, 'provider_record_id'))
                ->first();
            if (! $record) {
                $uuid = $preserveUuids && filled(data_get($payload, 'uuid')) ? (string) data_get($payload, 'uuid') : new_public_id();
                if (ManagedDnsRecord::where('uuid', $uuid)->exists()) {
                    $uuid = new_public_id();
                }
                $previousComment = data_get($payload, 'ownership_comment');
                // Without the source comment there is no proof of ownership at the provider.
                $owned = (bool) data_get($payload, 'owned', false) && is_string($previousComment) && $previousComment !== '';

                $record = ManagedDnsRecord::query()->create([
                    'uuid' => $uuid,
                    'team_id' => $teamId,
                    'integration_token_id' => $zone->integration_token_id,
                    'dns_provider_zone_id' => $zone->id,
                    'provider_record_id' => (string) data_get($payload, 'provider_record_id'),
                    'type' => (string) data_get($payload, 'type'),
                    'name' => $name,
                    'content' => (string) data_get($payload, 'content'),
                    'owned' => $owned,
                ]);
                if ($owned && $previousComment !== $record->ownershipComment()) {
                    $this->pendingDnsAdoptions[] = ['record_id' => $record->id, 'previous_comment' => $previousComment];
                }
                $count++;
            }

            foreach ($resources as $resource) {
                $record->addReference($resource);
            }
        }

        return $count;
    }

    /**
     * Replaces the ownership comment of the source instance with the comment of this instance at the provider.
     * A record that cannot be adopted is no longer owned, so this instance never changes or deletes it.
     *
     * @return list<string> warnings
     */
    private function adoptManagedDnsRecords(): array
    {
        $warnings = [];
        foreach ($this->pendingDnsAdoptions as $adoption) {
            $record = ManagedDnsRecord::query()->find($adoption['record_id']);
            if (! $record) {
                continue;
            }

            try {
                $adopted = app(CloudflareDnsProvider::class)->adoptRecord($record, $adoption['previous_comment']);
            } catch (Throwable) {
                $adopted = false;
            }
            if ($adopted) {
                continue;
            }

            $record->forceFill(['owned' => false])->save();
            $warnings[] = "DNS record {$record->name}: this instance could not take ownership; it will not change or delete it.";
        }
        $this->pendingDnsAdoptions = [];

        return $warnings;
    }

    /**
     * @param  list<array<string, mixed>>  $previews
     */
    private function importPreviews(array $previews, Application $application, bool $preserveUuids, bool $adoptMode, int $teamId): void
    {
        foreach ($previews as $previewPayload) {
            $uuid = $preserveUuids && filled(data_get($previewPayload, 'uuid'))
                ? (string) data_get($previewPayload, 'uuid')
                : new_public_id();

            $preview = ApplicationPreview::withTrashed()->where('uuid', $uuid)->first();
            if ($preview && ! $this->previewBelongsToTeam($preview, $teamId)) {
                throw ValidationException::withMessages([
                    'previews' => ['An imported preview UUID cannot be used on this instance.'],
                ]);
            }
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
            // FQDN is globally unique; only release conflicts owned by the target team.
            if (filled($fqdn)) {
                $conflicts = ApplicationPreview::withTrashed()
                    ->where('fqdn', $fqdn)
                    ->when($preview, fn ($q) => $q->where('id', '!=', $preview->id))
                    ->get();
                foreach ($conflicts as $conflict) {
                    if (! $this->previewBelongsToTeam($conflict, $teamId)) {
                        throw ValidationException::withMessages([
                            'previews' => ['An imported preview domain conflicts with a preview outside the target team.'],
                        ]);
                    }
                }
                ApplicationPreview::withoutEvents(function () use ($conflicts) {
                    $conflicts->each(function (ApplicationPreview $conflict) {
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
                'domain_port_overrides' => data_get($previewPayload, 'domain_port_overrides'),
                'max_restart_count' => (int) data_get($previewPayload, 'max_restart_count', 0),
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

            $this->previewMap[(string) data_get($previewPayload, 'uuid', $preview->uuid)] = $preview;

            // Avoid duplicating volumes when re-importing the same preview.
            if ($preview->wasRecentlyCreated || $preview->persistentStorages()->count() === 0) {
                $this->importPersistentStorages(data_get($previewPayload, 'persistent_storages', []), $preview);
            }
        }
    }

    private function previewBelongsToTeam(ApplicationPreview $preview, int $teamId): bool
    {
        return Application::withTrashed()
            ->whereKey($preview->application_id)
            ->whereRelation('environment.project', 'team_id', $teamId)
            ->exists();
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

            $s3Ids = $this->importedBackupS3StorageIds($backupPayload);
            $s3Id = $s3Ids[0] ?? null;
            $this->warnAboutMissingS3Storage($backupPayload, $s3Id, 'volume '.($volume->name ?? $volume->mount_path));

            $importedBackup = ScheduledVolumeBackup::create([
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
                'missing_backup_notification_days' => (int) data_get($backupPayload, 'missing_backup_notification_days', 0),
            ]);
            $importedBackup->syncS3Storages($s3Ids);
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
