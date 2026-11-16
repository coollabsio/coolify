<?php

namespace App\Models;

use App\Actions\Node\BuildNodeClusterIngressRoutes;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeContainerSlot;
use App\Enums\NodeDatabaseType;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Services\Security\SensitiveDataRedactor;
use App\Support\HealthCheckCommand;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\ReleasesManagedDnsRecords;
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
    use ClearsGlobalSearchCache, HasFactory, ReleasesManagedDnsRecords, ScopedByAccessGrants;

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
        'pull_request_from_fork' => false,
        'preview_deployments_enabled' => false,
        'fork_preview_deployments_enabled' => false,
        'preview_url_template' => self::DEFAULT_PREVIEW_URL_TEMPLATE,
        'preview_uses_production_values' => true,
    ];

    /** The v4 default: the pull request number in front of each domain of the parent. */
    public const DEFAULT_PREVIEW_URL_TEMPLATE = '{{pr_id}}.{{domain}}';

    /** @var list<int> Clusters that routed, named, or published this workload before it was deleted. */
    private array $networkClusterIdsBeforeDelete = [];

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
            'stack_health_check' => 'array',
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
            'preview_of_id' => 'integer',
            'pull_request_id' => 'integer',
            'pull_request_from_fork' => 'boolean',
            'pull_request_comment_id' => 'integer',
            'preview_deployments_enabled' => 'boolean',
            'fork_preview_deployments_enabled' => 'boolean',
            'preview_uses_production_values' => 'boolean',
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
            $workload->networkClusterIdsBeforeDelete = array_values(array_unique([
                ...($workload->hasIngressRoutes() || filled($workload->internal_dns_name) ? $workload->clusterIds() : []),
                ...$workload->publishedPorts()->pluck('node_cluster_id')->map(fn ($id): int => (int) $id),
            ]));
        });
        static::deleted(function (NodeWorkload $workload): void {
            // The routes, internal name, and published ports of a deleted workload must disappear from every Node.
            NodeCluster::query()
                ->whereKey($workload->networkClusterIdsBeforeDelete)
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

    /**
     * The hostnames that point to the ingress Nodes: the domains and the sources of the www redirect.
     *
     * @return list<string>
     */
    public function dnsHostnames(): array
    {
        $domains = array_values(array_map(fn (string $domain): string => strtolower(rtrim($domain, '.')), $this->domains ?? []));

        return array_values(array_unique([...$domains, ...array_keys(BuildNodeClusterIngressRoutes::wwwRedirects($domains, $this->www_redirect))]));
    }

    /** Whether ingress Nodes route public HTTP traffic to this workload. */
    public function hasIngressRoutes(): bool
    {
        return is_array($this->domain_routes) && $this->domain_routes !== [];
    }

    /**
     * Whether other containers call this workload through the cluster firewall, so a new address
     * must be allowed on every Node before it takes traffic: ingress, the peers of its stack, or a
     * published port.
     */
    public function receivesClusterTraffic(): bool
    {
        return $this->hasIngressRoutes() || $this->node_stack_id !== null || $this->publishedPorts()->exists();
    }

    /**
     * The stack that this workload is a service of (decision 0023). Unscoped: the access of a
     * service is the access of its stack, and a job resolves it without an actor.
     */
    public function stack(): BelongsTo
    {
        return $this->belongsTo(NodeStack::class, 'node_stack_id')->withoutGlobalScopes();
    }

    /**
     * The workload that this preview deploys a pull request of (decision 0025). Unscoped: the
     * parent is in the same environment, and a webhook or job resolves it without an actor.
     */
    public function previewOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'preview_of_id')->withoutGlobalScopes();
    }

    /** The previews of the pull requests of this workload. */
    public function previews(): HasMany
    {
        return $this->hasMany(self::class, 'preview_of_id')->orderBy('pull_request_id');
    }

    /**
     * The resource whose access grants apply to this workload: the parent of a preview (decision 0025),
     * the stack of a stack service, else the workload itself. Null when the parent is gone.
     */
    public function accessTarget(): NodeWorkload|NodeStack|null
    {
        return match (true) {
            $this->isPreview() => $this->previewOf,
            $this->node_stack_id !== null => $this->stack,
            default => $this,
        };
    }

    public function isPreview(): bool
    {
        return $this->preview_of_id !== null;
    }

    /**
     * Only a Git application can have previews: not a database, an image application, an inline
     * Dockerfile, a service of a stack, or a preview.
     */
    public function canHavePreviews(): bool
    {
        return $this->buildsFromGit() && ! $this->isDatabase() && $this->node_stack_id === null && ! $this->isPreview();
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

    /**
     * The worst health of the running managed containers on the Nodes of this workload, or null without a health check.
     * `restarting` means a crash loop: Podman restarted the container less than a minute before the Node saw it.
     * Both times come from the Node clock.
     */
    public function observedHealth(): ?string
    {
        $containers = NodeContainer::query()
            ->where('node_workload_id', $this->id)
            ->whereIn('node_id', $this->nodes()->pluck('nodes.id'))
            ->where('is_managed', true)
            ->where('state', 'running')
            ->get(['health_status', 'restart_count', 'runtime_started_at', 'observed_at']);
        if ($containers->contains(fn (NodeContainer $container): bool => (int) $container->restart_count > 0
            && $container->runtime_started_at !== null
            && $container->observed_at !== null
            && $container->runtime_started_at->diffInSeconds($container->observed_at) < 60)) {
            return 'restarting';
        }
        $observed = $containers->pluck('health_status');

        return collect(['unhealthy', 'starting', 'healthy'])->first(fn (string $health): bool => $observed->contains($health));
    }

    /** Whether this workload is a database (decision 0010). */
    public function isDatabase(): bool
    {
        return filled($this->database_type);
    }

    /** The page of the workload, like `link()` of v4 resources, for notifications. */
    public function link(): ?string
    {
        $project = $this->project()->value('uuid');
        $environment = $this->environment()->value('uuid');
        if ($project === null || $environment === null) {
            return null;
        }

        return route('project.cluster-application.show', ['project_uuid' => $project, 'environment_uuid' => $environment, 'workload_uuid' => $this->uuid]);
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
            filled($this->build_target) ? NodeRegistry::BUILD_TARGET_CAPABILITY : null,
        ]));
    }

    /**
     * The configuration of the revision that the first build creates. A service of a stack keeps
     * the command, restart policy, and files of its Compose service. A preview takes the latest
     * configuration of its parent at each build (decision 0025), without the mounts and the
     * health check, which the preview has of its own.
     *
     * @return array<string, mixed>
     */
    public function firstRevisionConfiguration(): array
    {
        if ($this->isPreview()) {
            $configuration = $this->previewOf?->revisions()->latest('id')->first()?->configuration;
            unset($configuration['volumes'], $configuration['health_check'], $configuration['ports']);

            return $configuration ?: ['restart_policy' => 'unless-stopped'];
        }
        $stack = $this->node_stack_id === null ? null : NodeStack::query()->withoutGlobalScopes()->find($this->node_stack_id);

        return $stack === null || $this->stack_service === null
            ? ['restart_policy' => 'unless-stopped']
            : $stack->serviceConfiguration($this->stack_service);
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
     * @param  array<string, string|array{value: string, uid?: int, gid?: int, mode?: int}>  $secrets  Files in /run/secrets, keyed by file name.
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
     * @param  array<string, string|array{value: string, uid?: int, gid?: int, mode?: int}>  $secrets
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

    /** The preview variables of this parent (decision 0025), which the Environment Variables tab edits. */
    public function environment_variables_preview(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')->where('is_preview', true);
    }

    /**
     * The variables that a preview of a pull request of this parent gets, by key (decision 0025):
     * the preview variables, over the production values only when "Use production values in
     * previews" is on and the pull request is not from a fork, as in v4. Build-time variables
     * follow the same rule, so a fork preview gets no production build secret.
     *
     * @return Collection<string, EnvironmentVariable>
     */
    public function resolvedPreviewVariables(bool $fromFork): Collection
    {
        // Base collections: an Eloquent merge keys by id, so a preview value would not replace its production key.
        $preview = $this->environment_variables_preview()->orderBy('id')->get()->toBase()->keyBy('key');
        if ($fromFork || $this->preview_uses_production_values === false) {
            return $preview;
        }

        return $this->environment_variables()->orderBy('id')->get()->toBase()->keyBy('key')->merge($preview);
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
     * from its environment (decision 0008), resolved like runtimeEnvironment(). An entry is
     * the value, or the value with the owner and the mode of the file when they are set.
     *
     * @return array<string, string|array{value: string, uid?: int, gid?: int, mode?: int}>
     */
    public function runtimeSecrets(): array
    {
        $variables = $this->environment_variables()->where('is_file_secret', true)->get()->keyBy('key');
        $secrets = collect($this->resolvedRuntimeValues($variables))
            ->map(fn (string $value, int|string $key): string|array => $variables[$key]->secretFileEntry($value))
            ->all();
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
            // A stack service keeps the health check of its Compose file until the user sets one.
            return $this->stack_health_check ?: null;
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
        return $this->belongsToMany(Node::class, 'node_workload_nodes')->withPivot('container_ip', 'next_container_ip')->withTimestamps();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }

    /** The ports that every Node of the cluster publishes for this workload (decision 0016). */
    public function publishedPorts(): HasMany
    {
        return $this->hasMany(NodePublishedPort::class)->orderBy('protocol')->orderBy('host_port');
    }

    /** The cluster of the Nodes that run this workload, else of its stack. */
    public function cluster(): ?NodeCluster
    {
        $clusterId = $this->clusterIds()[0] ?? $this->stack?->node_cluster_id;

        return $clusterId === null ? null : NodeCluster::query()->find($clusterId);
    }

    /**
     * The slot of the container of this workload on the Node, from the inventory: the running
     * container, else the newest. A rolling deploy alternates the slots (decision 0022).
     */
    public function containerSlotOn(Node $node): NodeContainerSlot
    {
        $slots = collect(NodeContainerSlot::cases())->keyBy(fn (NodeContainerSlot $slot): string => $slot->containerName($this));
        $name = $this->containers()
            ->where('node_id', $node->id)
            ->whereIn('name', $slots->keys())
            ->orderByRaw("CASE WHEN state = 'running' THEN 0 ELSE 1 END")
            ->orderByRaw('runtime_created_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->value('name');

        return $name === null ? NodeContainerSlot::MAIN : $slots->get($name);
    }

    public function containerNameOn(Node $node): string
    {
        return $this->containerSlotOn($node)->containerName($this);
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

    /** The volumes of a stack that this service mounts (decision 0024), with its path and mode. */
    public function stackVolumes(): BelongsToMany
    {
        return $this->belongsToMany(NodeVolume::class, 'node_volume_mounts', 'node_workload_id', 'node_volume_id')
            ->withPivot(['mount_path', 'read_only'])
            ->withTimestamps();
    }

    public function stackVolumeMounts(): HasMany
    {
        return $this->hasMany(NodeVolumeMount::class);
    }

    /**
     * Every volume that this workload owns or mounts: its own volumes (also detached ones) and the
     * stack volumes that it mounts.
     *
     * @return Collection<int, NodeVolume>
     */
    public function allVolumes(): Collection
    {
        return $this->volumes()->get()->concat($this->stackVolumes()->get())->unique('id')->values();
    }

    /** Whether a volume that this workload owns or mounts has data on a Node. */
    public function hasVolumesWithData(): bool
    {
        return $this->volumes()->whereNotNull('node_id')->exists() || $this->stackVolumes()->whereNotNull('node_volumes.node_id')->exists();
    }

    /**
     * The services that share stack volumes with this one, directly or through another service,
     * with this one first (decision 0024). A workload without stack volumes is its own group.
     *
     * @return Collection<int, NodeWorkload>
     */
    public function volumeGroup(): Collection
    {
        if ($this->node_stack_id === null) {
            return new Collection([$this]);
        }
        $mounts = NodeVolumeMount::query()
            ->whereHas('workload', fn ($workloads) => $workloads->withoutGlobalScopes()->where('node_stack_id', $this->node_stack_id))
            ->get(['node_volume_id', 'node_workload_id']);
        $ids = [$this->id];
        $volumeIds = [];
        do {
            $before = count($ids);
            $volumeIds = $mounts->whereIn('node_workload_id', $ids)->pluck('node_volume_id')->unique()->all();
            $ids = $mounts->whereIn('node_volume_id', $volumeIds)->pluck('node_workload_id')->push($this->id)->unique()->values()->all();
        } while (count($ids) > $before);
        if (count($ids) === 1) {
            return new Collection([$this]);
        }
        $others = self::query()->withoutGlobalScopes()->whereKey(array_diff($ids, [$this->id]))->orderBy('id')->get();

        return (new Collection([$this]))->concat($others)->values();
    }

    /** Whether a move of this workload, or of a service of its volume group, has not finished yet. */
    public function hasActiveMove(): bool
    {
        return NodeOperation::movingWorkloadIds([$this->id]) !== [];
    }

    /**
     * Rejects a deploy or lifecycle action from outside a move while a move copies the volumes:
     * a source container that starts during the final pass writes data the target never gets.
     */
    public function ensureNotMovingVolumes(): void
    {
        if ($this->hasVolumesWithData() && $this->hasActiveMove()) {
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
     * The mounts that the next revision records: every volume that is not detached and every stack
     * volume that the service mounts (decision 0024), sorted by path.
     *
     * @return list<array{volume_uuid: string, volume_name: string, container_path: string, read_only: bool}>
     */
    public function volumeMounts(): array
    {
        $own = $this->volumes()
            ->whereNull('detached_at')
            ->get()
            ->map(fn (NodeVolume $volume): array => [
                'volume_uuid' => $volume->uuid,
                'volume_name' => $volume->podmanName(),
                'container_path' => $volume->mount_path,
                'read_only' => false,
            ]);
        $shared = $this->stackVolumes()
            ->get()
            ->map(fn (NodeVolume $volume): array => [
                'volume_uuid' => $volume->uuid,
                'volume_name' => $volume->podmanName(),
                'container_path' => $volume->pivot->mount_path,
                'read_only' => (bool) $volume->pivot->read_only,
            ]);

        return $own->concat($shared)->sortBy('container_path')->values()->all();
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
