<?php

namespace App\Models;

use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeDatabaseType;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Services\Security\SensitiveDataRedactor;
use App\Support\HealthCheckCommand;
use App\Traits\ScopedByAccessGrants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class NodeWorkload extends BaseModel
{
    use HasFactory, ScopedByAccessGrants;

    protected $guarded = [];

    /** The v4 manual webhook secrets (decision 0011). */
    public const MANUAL_WEBHOOK_SECRETS = [
        'manual_webhook_secret_github',
        'manual_webhook_secret_gitlab',
        'manual_webhook_secret_bitbucket',
        'manual_webhook_secret_gitea',
    ];

    protected $hidden = self::MANUAL_WEBHOOK_SECRETS;

    protected $attributes = [
        'www_redirect' => 'both',
        'force_https' => true,
        'permanent_redirect' => true,
    ];

    /** @var list<int> Clusters that routed this workload before it was deleted. */
    private array $ingressClusterIdsBeforeDelete = [];

    protected function casts(): array
    {
        return [
            'desired_state' => NodeWorkloadDesiredState::class,
            'source_type' => NodeWorkloadSourceType::class,
            'database_type' => NodeDatabaseType::class,
            'github_app_id' => 'integer',
            'private_key_id' => 'integer',
            'registry_credential_id' => 'integer',
            'domains' => 'array',
            'domain_routes' => 'array',
            'force_https' => 'boolean',
            'permanent_redirect' => 'boolean',
            'is_env_sorting_enabled' => 'boolean',
            'include_coolify_default_variables' => 'boolean',
            'redact_all_env_values_in_logs' => 'boolean',
            'health_check_enabled' => 'boolean',
            'health_check_return_code' => 'integer',
            'health_check_interval' => 'integer',
            'health_check_timeout' => 'integer',
            'health_check_retries' => 'integer',
            'health_check_start_period' => 'integer',
            'custom_healthcheck_found' => 'boolean',
            'manual_webhook_secret_github' => 'encrypted',
            'manual_webhook_secret_gitlab' => 'encrypted',
            'manual_webhook_secret_bitbucket' => 'encrypted',
            'manual_webhook_secret_gitea' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NodeWorkload $workload): void {
            foreach (self::MANUAL_WEBHOOK_SECRETS as $secret) {
                $workload->{$secret} ??= Str::random(40);
            }
        });
        static::deleting(function (NodeWorkload $workload): void {
            $workload->ingressClusterIdsBeforeDelete = $workload->hasIngressRoutes() || filled($workload->internal_dns_name) ? $workload->clusterIds() : [];
        });
        static::deleted(function (NodeWorkload $workload): void {
            // The routes and internal name of a deleted workload must disappear from every Node.
            NodeCluster::query()
                ->whereKey($workload->ingressClusterIdsBeforeDelete)
                ->get()
                ->each(fn (NodeCluster $cluster) => QueueNodeClusterNetworkRevision::run($cluster));
        });
    }

    /**
     * The certificate report of each domain, from the clusters of this workload that serve HTTPS.
     *
     * @return array<string, array{host: string, status: string, not_after: int, error: string, next_attempt_at: int}>
     */
    public function domainCertificates(): array
    {
        if (blank($this->domains)) {
            return [];
        }

        return NodeCluster::query()
            ->whereKey($this->clusterIds())
            ->get()
            ->filter(fn (NodeCluster $cluster): bool => $cluster->supportsIngressTls())
            ->flatMap(fn (NodeCluster $cluster): array => $cluster->ingressCertificates())
            ->only($this->domains)
            ->all();
    }

    /**
     * Whether ingress serves the domain over HTTPS now: it has a certificate that has not expired.
     * A failed renewal keeps serving the old certificate.
     *
     * @param  array{not_after?: int}|null  $certificate
     */
    public static function servesHttps(?array $certificate): bool
    {
        return (int) ($certificate['not_after'] ?? 0) > now()->timestamp;
    }

    /** Whether ingress Nodes route public HTTP traffic to this workload. */
    public function hasIngressRoutes(): bool
    {
        return is_array($this->domain_routes) && $this->domain_routes !== [];
    }

    /** @return list<int> The distinct container ports that the domains of this workload route to. */
    public function domainPorts(): array
    {
        return collect($this->domain_routes ?? [])->pluck('port')->map(fn ($port): int => (int) $port)->unique()->values()->all();
    }

    /** @return list<int> The clusters of the Nodes that run this workload. */
    public function clusterIds(): array
    {
        return $this->nodes()
            ->whereNotNull('nodes.node_cluster_id')
            ->pluck('nodes.node_cluster_id')
            ->unique()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Matches the other project resources so shared environment variables and
     * the environment variable policy can resolve the owning team.
     */
    public function team(): ?Team
    {
        // team_id is the owner that NodeWorkloadPolicy checks; the project relation can be gone.
        return Team::query()->find($this->team_id);
    }

    public function type(): string
    {
        return 'cluster-application';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(NodeWorkloadRevision::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(NodeBuild::class);
    }

    /** The worst health of the running managed containers on the Nodes of this workload, or null without a health check. */
    public function observedHealth(): ?string
    {
        $observed = NodeContainer::query()
            ->where('node_workload_id', $this->id)
            ->whereIn('node_id', $this->nodes()->pluck('nodes.id'))
            ->where('is_managed', true)
            ->where('state', 'running')
            ->pluck('health_status');

        return collect(['unhealthy', 'starting', 'healthy'])->first(fn (string $health): bool => $observed->contains($health));
    }

    /** Whether this workload is a database (decision 0010). */
    public function isDatabase(): bool
    {
        return filled($this->database_type);
    }

    /** Whether Coolify builds the image of this workload from a Git repository. */
    public function buildsFromSource(): bool
    {
        return ($this->source_type ?? NodeWorkloadSourceType::IMAGE)->builds();
    }

    /** Whether Coolify builds the image of this workload from a Git repository, not an inline Dockerfile. */
    public function buildsFromGit(): bool
    {
        return ($this->source_type ?? NodeWorkloadSourceType::IMAGE)->usesGit();
    }

    /** The GitHub App that reads a private repository (v4 `source_id`). */
    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }

    /** The deploy key that reads a private repository over SSH (v4 `private_key_id`). */
    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    /** The registry login: the pull login of an image application, or the push login of a Git application. */
    public function registryCredential(): BelongsTo
    {
        return $this->belongsTo(RegistryCredential::class);
    }

    /** Whether a build needs a Git credential: the workload has a GitHub App or a deploy key. */
    public function usesPrivateGitSource(): bool
    {
        return $this->github_app_id !== null || $this->private_key_id !== null;
    }

    /**
     * The Sentinel capabilities that a build of this workload needs. A build-time variable
     * needs `image.build.variables.v1`, because an older Sentinel builds without it.
     *
     * @return list<string>
     */
    public function buildCapabilities(): array
    {
        return array_values(array_filter([
            NodeRegistry::BUILD_CAPABILITY,
            $this->usesPrivateGitSource() ? NodeRegistry::PRIVATE_GIT_BUILD_CAPABILITY : null,
            $this->environment_variables()->where('is_buildtime', true)->exists() ? NodeRegistry::BUILD_VARIABLES_CAPABILITY : null,
            $this->source_type === NodeWorkloadSourceType::DOCKERFILE_INLINE ? NodeRegistry::INLINE_DOCKERFILE_BUILD_CAPABILITY : null,
        ]));
    }

    /**
     * Build-time variables, resolved like runtime variables (decision 0012). A secret is what logs
     * always hide: a locked variable, a reference to a locked shared variable, or a secret file. The
     * build reads it only through a BuildKit secret mount, so it is never a build arg and never in
     * the image. Every other variable is a build arg, as in v4.
     *
     * @return array{args: array<string, string>, secrets: array<string, string>}
     */
    public function buildVariables(): array
    {
        [$secrets, $args] = $this->environment_variables()->where('is_buildtime', true)->get()
            ->partition(fn (EnvironmentVariable $variable): bool => $variable->isLockedForLogs() || $variable->is_file_secret);
        $resolve = fn (Collection $variables): array => $variables
            ->mapWithKeys(fn (EnvironmentVariable $variable): array => [
                $variable->key => (string) $variable->get_real_environment_variables_with_server($variable->value, $this),
            ])
            ->sortKeys()
            ->all();

        return ['args' => $resolve($args), 'secrets' => $resolve($secrets)];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, string>  $environment
     * @param  array<string, string>  $secrets  Files in /run/secrets, keyed by file name.
     */
    public function createRevision(string $image, array $configuration, array $environment = [], array $secrets = []): NodeWorkloadRevision
    {
        return $this->revisions()->create([
            'image' => $image,
            'configuration' => $configuration,
            'environment' => $environment,
            'secrets' => $secrets === [] ? null : $secrets,
            'configuration_hash' => self::configurationHash($image, $configuration, $environment, $secrets),
        ]);
    }

    /**
     * Without secrets the key is absent, so the hash of a revision without secrets stays the same.
     *
     * @param  array<string, mixed>  $configuration
     * @param  array<string, string>  $environment
     * @param  array<string, string>  $secrets
     */
    public static function configurationHash(string $image, array $configuration, array $environment, array $secrets = []): string
    {
        return hash_hmac('sha256', json_encode([
            'image' => $image,
            'configuration' => $configuration,
            'environment' => $environment,
            ...($secrets === [] ? [] : ['secrets' => $secrets]),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')->where('is_preview', false);
    }

    /** The text with the runtime values of this workload hidden, like a deployment log. */
    public function redactLogText(string $text): string
    {
        try {
            $secrets = EnvironmentVariable::logRedactionSecretsFor($this);
        } catch (\Throwable) {
            return REDACTED;
        }

        // Errors of a pull or a push must not show the registry password either.
        $registryPassword = $this->registry_credential_id === null ? null : RegistryCredential::query()->whereKey($this->registry_credential_id)->value('password');

        return app(SensitiveDataRedactor::class)->redactText($text, $secrets['known'], [...$secrets['locked'], ...(filled($registryPassword) ? [$registryPassword] : [])]);
    }

    /** Whether logs hide every environment value, or only locked values. */
    public function redactsAllEnvValuesInLogs(): bool
    {
        return $this->redact_all_env_values_in_logs ?? true;
    }

    /**
     * Runtime variables as the container receives them, with shared variable
     * references resolved and without the .env escaping used by compose files.
     * The Coolify default variables are added when the setting is on; a variable
     * that the user defines wins. There is no COOLIFY_SERVER_IP, because one
     * revision runs on more than one server.
     * Keys are sorted so that reordering variables does not change the hash.
     *
     * @return array<string, string>
     */
    public function runtimeEnvironment(): array
    {
        $variables = $this->environment_variables()->get();
        $environment = $this->resolvedRuntimeValues($variables->where('is_file_secret', false));
        // A model created in this request has no column default loaded yet (null), which means on.
        if ($this->include_coolify_default_variables !== false) {
            $defaults = collect([
                'COOLIFY_APP_NAME' => $this->name,
                'COOLIFY_PROJECT_NAME' => $this->project?->name,
                'COOLIFY_ENVIRONMENT_NAME' => $this->environment?->name,
            ])->whereNotNull()->map(fn ($value): string => (string) $value)->except($variables->pluck('key')->all());
            $environment = [...$environment, ...$defaults->all()];
        }
        // A database image reads a credential that is a secret file through <key>_FILE. It follows the
        // delivery of the credential, so a change of the delivery never sets both forms.
        if ($this->isDatabase()) {
            $credentialKeys = array_column($this->database_type->credentials(), 'key');
            $variables->where('is_file_secret', true)->whereIn('key', $credentialKeys)
                ->each(function (EnvironmentVariable $variable) use (&$environment, $variables): void {
                    $fileKey = $variable->key.'_FILE';
                    if (! $variables->contains('key', $fileKey)) {
                        $environment[$fileKey] = '/run/secrets/'.$variable->key;
                    }
                });
        }
        ksort($environment);

        return $environment;
    }

    /**
     * Runtime variables that the container reads as files at /run/secrets/<key> instead of
     * from its environment (decision 0008), resolved like runtimeEnvironment().
     *
     * @return array<string, string>
     */
    public function runtimeSecrets(): array
    {
        $secrets = $this->resolvedRuntimeValues($this->environment_variables()->where('is_file_secret', true)->get());
        ksort($secrets);

        return $secrets;
    }

    /**
     * @param  Collection<int, EnvironmentVariable>  $variables
     * @return array<string, string>
     */
    private function resolvedRuntimeValues(Collection $variables): array
    {
        return $variables
            ->where('is_runtime', true)
            ->mapWithKeys(fn (EnvironmentVariable $variable): array => [
                $variable->key => (string) $variable->get_real_environment_variables_with_server($variable->value, $this),
            ])
            ->all();
    }

    public function deployedRevision(): ?NodeWorkloadRevision
    {
        return $this->operations()
            ->where('command_type', 'workload.deploy.v1')
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->latest('id')
            ->first()
            ?->revision;
    }

    /**
     * Hash of the configuration that the next deployment applies. It is null
     * until the workload has a successful deployment, like v4 `config_hash`.
     */
    protected function configHash(): Attribute
    {
        return Attribute::get(function (): ?string {
            $latest = $this->revisions()->latest('id')->first();
            if ($latest === null || $this->deployedRevision() === null) {
                return null;
            }

            return self::configurationHash($latest->image, $this->withWorkloadSettings($latest->configuration ?? []), $this->runtimeEnvironment(), $this->runtimeSecrets());
        });
    }

    public function isConfigurationChanged(): bool
    {
        $deployedHash = $this->deployedRevision()?->configuration_hash;

        return $deployedHash !== null && $this->config_hash !== $deployedHash;
    }

    public function isExited(): bool
    {
        return $this->desired_state !== NodeWorkloadDesiredState::RUNNING;
    }

    public function isRunning(): bool
    {
        return $this->desired_state === NodeWorkloadDesiredState::RUNNING;
    }

    /**
     * The Coolify health check that replaces the HEALTHCHECK of the image, like in v4. Null when
     * the check is off, or when an HTTP check has no port: the container then keeps the
     * HEALTHCHECK of its image, if it has one.
     *
     * @return array{command: string, description: string, interval_seconds: int, timeout_seconds: int, retries: int, start_period_seconds: int}|null
     */
    public function healthCheck(): ?array
    {
        if ($this->health_check_enabled !== true) {
            return null;
        }
        $command = $this->health_check_type === 'cmd' ? HealthCheckCommand::command($this->health_check_command) : null;
        if ($command !== null) {
            $check = ['command' => $command, 'description' => $command];
        } else {
            $port = (int) ($this->health_check_port ?: ($this->domainPorts()[0] ?? 0));
            if ($port < 1 || $port > 65535) {
                return null;
            }
            $check = HealthCheckCommand::http($this->health_check_method, $this->health_check_scheme, $this->health_check_host, $port, $this->health_check_path);
        }

        return [
            ...$check,
            'interval_seconds' => min(max((int) $this->health_check_interval, 1), 3600),
            'timeout_seconds' => min(max((int) $this->health_check_timeout, 1), 3600),
            'retries' => min(max((int) $this->health_check_retries, 1), 100),
            'start_period_seconds' => min(max((int) $this->health_check_start_period, 0), 3600),
        ];
    }

    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'node_workload_nodes')->withPivot('container_ip')->withTimestamps();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(NodeOperation::class);
    }

    public function scheduled_tasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('name', 'asc');
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(NodeVolume::class);
    }

    /** Whether a move of this workload has not finished yet. */
    public function hasActiveMove(): bool
    {
        return $this->operations()
            ->where('command_type', 'workload.move.v1')
            ->whereIn('status', [
                NodeOperationStatus::QUEUED,
                NodeOperationStatus::DISPATCHED,
                NodeOperationStatus::RUNNING,
                NodeOperationStatus::VERIFYING,
                NodeOperationStatus::UNCERTAIN,
            ])
            ->exists();
    }

    /**
     * Rejects a deploy or lifecycle action from outside a move while a move copies the volumes:
     * a source container that starts during the final pass writes data the target never gets.
     */
    public function ensureNotMovingVolumes(): void
    {
        if ($this->volumes()->whereNotNull('node_id')->exists() && $this->hasActiveMove()) {
            throw new RuntimeException('This application is moving with its volumes. Wait until the move finishes.');
        }
    }

    /** Whether the latest revision mounts the volume, so the reconciler can still deploy it. */
    public function latestRevisionMounts(NodeVolume $volume): bool
    {
        $configuration = $this->revisions()->latest('id')->first()?->configuration ?? [];

        return collect($configuration['volumes'] ?? [])->contains(fn ($mount): bool => data_get($mount, 'volume_uuid') === $volume->uuid);
    }

    /**
     * The mounts that the next revision records: every volume that is not detached, sorted by path.
     *
     * @return list<array{volume_uuid: string, volume_name: string, container_path: string, read_only: bool}>
     */
    public function volumeMounts(): array
    {
        return $this->volumes()
            ->whereNull('detached_at')
            ->orderBy('mount_path')
            ->get()
            ->map(fn (NodeVolume $volume): array => [
                'volume_uuid' => $volume->uuid,
                'volume_name' => $volume->podmanName(),
                'container_path' => $volume->mount_path,
                'read_only' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * The configuration with the current mounts and health check, which are edited on the
     * workload. A key is absent when it has no value, so the hash of an application without
     * volumes or a health check stays the same as before these settings existed.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function withWorkloadSettings(array $configuration): array
    {
        // Assigned in place: the key order is part of the hash and of the revision comparison.
        foreach (['volumes' => $this->volumeMounts(), 'health_check' => $this->healthCheck() ?? []] as $key => $value) {
            if ($value === []) {
                unset($configuration[$key]);
            } else {
                $configuration[$key] = $value;
            }
        }

        return $configuration;
    }
}
