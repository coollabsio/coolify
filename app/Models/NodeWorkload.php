<?php

namespace App\Models;

use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadDesiredState;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NodeWorkload extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    /** @var list<int> Clusters that routed this workload before it was deleted. */
    private array $ingressClusterIdsBeforeDelete = [];

    protected function casts(): array
    {
        return [
            'desired_state' => NodeWorkloadDesiredState::class,
            'domains' => 'array',
            'http_port' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (NodeWorkload $workload): void {
            $workload->ingressClusterIdsBeforeDelete = $workload->hasIngressRoutes() ? $workload->clusterIds() : [];
        });
        static::deleted(function (NodeWorkload $workload): void {
            // The routes of a deleted workload must disappear from every ingress Node.
            NodeCluster::query()
                ->whereKey($workload->ingressClusterIdsBeforeDelete)
                ->get()
                ->each(fn (NodeCluster $cluster) => QueueNodeClusterNetworkRevision::run($cluster));
        });
    }

    /** Whether ingress Nodes route public HTTP traffic to this workload. */
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
        return data_get($this, 'environment.project.team');
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

    /**
     * Runtime variables as the container receives them, with shared variable
     * references resolved and without the .env escaping used by compose files.
     * Keys are sorted so that reordering variables does not change the hash.
     *
     * @return array<string, string>
     */
    public function runtimeEnvironment(): array
    {
        $environment = $this->environment_variables()
            ->where('is_runtime', true)
            ->get()
            ->mapWithKeys(fn (EnvironmentVariable $variable): array => [
                $variable->key => (string) $variable->get_real_environment_variables_with_server($variable->value, $this),
            ])
            ->all();
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

            return self::configurationHash($latest->image, $latest->configuration ?? [], $this->runtimeEnvironment());
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
}
