<?php

namespace App\Models;

use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Traits\ScopedByAccessGrants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

class NodeWorkload extends BaseModel
{
    use HasFactory, ScopedByAccessGrants;

    protected $guarded = [];

    /** @var list<int> Clusters that routed this workload before it was deleted. */
    private array $ingressClusterIdsBeforeDelete = [];

    protected function casts(): array
    {
        return [
            'desired_state' => NodeWorkloadDesiredState::class,
            'source_type' => NodeWorkloadSourceType::class,
            'domains' => 'array',
            'http_port' => 'integer',
            'is_env_sorting_enabled' => 'boolean',
            'include_coolify_default_variables' => 'boolean',
            'redact_all_env_values_in_logs' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
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

    /** Whether ingress Nodes route public HTTP traffic to this workload. */
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

    public function hasIngressRoutes(): bool
    {
        return is_array($this->domains) && $this->domains !== [] && $this->http_port !== null;
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

    /** Whether Coolify builds the image of this workload from a Git repository. */
    public function buildsFromSource(): bool
    {
        return ($this->source_type ?? NodeWorkloadSourceType::IMAGE)->builds();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, string>  $environment
     */
    public function createRevision(string $image, array $configuration, array $environment = []): NodeWorkloadRevision
    {
        return $this->revisions()->create([
            'image' => $image,
            'configuration' => $configuration,
            'environment' => $environment,
            'configuration_hash' => self::configurationHash($image, $configuration, $environment),
        ]);
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, string>  $environment
     */
    public static function configurationHash(string $image, array $configuration, array $environment): string
    {
        return hash_hmac('sha256', json_encode([
            'image' => $image,
            'configuration' => $configuration,
            'environment' => $environment,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')->where('is_preview', false);
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
        $environment = $variables
            ->where('is_runtime', true)
            ->mapWithKeys(fn (EnvironmentVariable $variable): array => [
                $variable->key => (string) $variable->get_real_environment_variables_with_server($variable->value, $this),
            ])
            ->all();
        // A model created in this request has no column default loaded yet (null), which means on.
        if ($this->include_coolify_default_variables !== false) {
            $defaults = collect([
                'COOLIFY_APP_NAME' => $this->name,
                'COOLIFY_PROJECT_NAME' => $this->project?->name,
                'COOLIFY_ENVIRONMENT_NAME' => $this->environment?->name,
            ])->whereNotNull()->map(fn ($value): string => (string) $value)->except($variables->pluck('key')->all());
            $environment = [...$environment, ...$defaults->all()];
        }
        ksort($environment);

        return $environment;
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

            return self::configurationHash($latest->image, $this->withVolumeMounts($latest->configuration ?? []), $this->runtimeEnvironment());
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
     * The configuration with the current mounts. Without volumes the key is absent, so the hash of
     * an application without volumes stays the same as before volumes existed.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function withVolumeMounts(array $configuration): array
    {
        $mounts = $this->volumeMounts();
        if ($mounts === []) {
            unset($configuration['volumes']);
        } else {
            // Assigned in place: the key order is part of the hash and of the revision comparison.
            $configuration['volumes'] = $mounts;
        }

        return $configuration;
    }
}
