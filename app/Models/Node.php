<?php

namespace App\Models;

use App\Actions\Node\FetchLatestSentinelRelease;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Enums\NodeRole;
use App\Jobs\SyncNodeClusterDnsRecordsJob;
use App\Support\CpuArchitecture;
use App\Support\DevelopmentIngressTarget;
use App\Traits\Auditable;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Node extends BaseModel
{
    use Auditable, ClearsGlobalSearchCache, HasFactory, HasMetrics;

    protected $guarded = [];

    protected $hidden = ['sentinel_token'];

    protected static function booted(): void
    {
        // Development only: keep the DEV_SEED_IP:80 forward of scripts/dev on the current ingress Node.
        static::saved(function (Node $node): void {
            if ($node->wasChanged(['is_ingress', 'node_cluster_id', 'ip']) || $node->wasRecentlyCreated) {
                DevelopmentIngressTarget::write();
            }
            // The managed DNS records of cluster domains point to the ingress Nodes (decision 0019).
            if ($node->wasChanged(['is_ingress', 'node_cluster_id', 'ip']) && ($node->is_ingress || $node->getOriginal('is_ingress'))) {
                $node->syncClusterDnsRecords([$node->node_cluster_id, $node->getOriginal('node_cluster_id')]);
            }
            // A published port forwards only to a reachable Node (decisions 0016 and 0037). The inventory of
            // an offline Node is not refreshed, so its change of reachability must change the forward.
            if ($node->wasChanged('is_reachable') && ! $node->wasRecentlyCreated && $node->cluster !== null && $node->runningPublishedWorkloadIds() !== []) {
                rescue(fn () => QueueNodeClusterNetworkRevision::run($node->cluster));
            }
        });
        static::deleted(function (Node $node): void {
            DevelopmentIngressTarget::write();
            if ($node->is_ingress) {
                $node->syncClusterDnsRecords([$node->node_cluster_id]);
            }
        });
    }

    /**
     * The workloads with a published port in the cluster that have a running container on the Node,
     * by the stored inventory.
     *
     * @return list<int>
     */
    public function runningPublishedWorkloadIds(): array
    {
        if ($this->node_cluster_id === null) {
            return [];
        }

        return $this->containers()
            ->where('state', 'running')
            ->whereIn('node_workload_id', NodePublishedPort::query()->where('node_cluster_id', $this->node_cluster_id)->select('node_workload_id'))
            ->distinct()
            ->orderBy('node_workload_id')
            ->pluck('node_workload_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @param  array<int, int|string|null>  $clusterIds */
    private function syncClusterDnsRecords(array $clusterIds): void
    {
        collect($clusterIds)->filter()->unique()
            ->each(fn ($clusterId) => SyncNodeClusterDnsRecordsJob::dispatch((int) $clusterId)->afterCommit());
    }

    protected function casts(): array
    {
        return [
            'role' => NodeRole::class,
            'sentinel_token' => 'encrypted',
            'metadata' => 'array',
            'settings' => 'array',
            'sentinel_capabilities' => 'array',
            'is_reachable' => 'boolean',
            'is_usable' => 'boolean',
            'network_observed_state' => 'array',
            'wireguard_last_handshake_at' => 'datetime',
            'flux_trust_bundle_version' => 'integer',
            'flux_trust_bundle_acknowledged_at' => 'datetime',
            'network_attempts' => 'integer',
            'network_next_attempt_at' => 'datetime',
            'network_pending_leave' => 'array',
            'is_ingress' => 'boolean',
            'is_gpu_enabled' => 'boolean',
            'is_builder' => 'boolean',
            'concurrent_builds' => 'integer',
            'build_memory_limit_bytes' => 'integer',
            'build_cpu_limit_millis' => 'integer',
            'build_cache_bytes' => 'integer',
            'volume_inventory' => 'array',
        ];
    }

    public function isMetricsEnabled(): bool
    {
        return (bool) data_get($this->settings, 'is_metrics_enabled', true);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    /** Whether this Node may build images: the builder role on a Node that runs workloads. */
    public function canBuild(): bool
    {
        return $this->is_builder !== false && ($this->role?->runsWorkloads() ?? true);
    }

    /**
     * The builds that may run on this Node at the same time (decision 0035). A value above 1
     * needs a Sentinel that refuses a build at the limit (`image.build.concurrent.v1`).
     */
    public function buildSlots(): int
    {
        $slots = max(1, (int) $this->concurrent_builds);

        return $slots > 1 && $this->supportsCapability(NodeBuild::CONCURRENT_CAPABILITY) === true ? $slots : 1;
    }

    /**
     * Whether the memory or the disk of this builder is above the pressure threshold of its cluster.
     * Such a builder gets no new build. Missing or old resource data does not stop builds.
     */
    public function hasBuildPressure(): bool
    {
        $cluster = $this->cluster;
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        if ($cluster === null) {
            return false;
        }
        try {
            if (Carbon::parse($metadata['collected_at'] ?? '')->isBefore(now()->subMinutes($cluster->resource_stale_after_minutes))) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        $memoryTotal = (float) ($metadata['memory_bytes'] ?? 0);
        $memoryUsed = $metadata['memory_used_bytes'] ?? null;
        $diskTotal = (float) ($metadata['disk_total_bytes'] ?? 0);
        $diskAvailable = $metadata['disk_available_bytes'] ?? null;
        if ($memoryTotal > 0 && is_numeric($memoryUsed) && ($memoryUsed / $memoryTotal) * 100 >= $cluster->memory_pressure_threshold) {
            return true;
        }

        return $diskTotal > 0 && is_numeric($diskAvailable)
            && (($diskTotal - min((float) $diskAvailable, $diskTotal)) / $diskTotal) * 100 >= $cluster->disk_pressure_threshold;
    }

    /**
     * The CPU architecture of this Node in OCI names (`amd64`, `arm64`), from the `uname -m` that onboarding
     * and every Flux system report record. Null when it is not known yet: such a Node fits every image.
     */
    public function cpuArchitecture(): ?string
    {
        return CpuArchitecture::normalize(data_get($this->metadata, 'arch'));
    }

    /** Builds that ran or run on this Node. */
    public function builds(): HasMany
    {
        return $this->hasMany(NodeBuild::class);
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function workloads(): BelongsToMany
    {
        return $this->belongsToMany(NodeWorkload::class, 'node_workload_nodes')->withPivot('container_ip', 'next_container_ip')->withTimestamps();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(NodeOperation::class);
    }

    /** Volumes whose data lives on this Node. */
    public function volumes(): HasMany
    {
        return $this->hasMany(NodeVolume::class);
    }

    public function volumeCopies(): HasMany
    {
        return $this->hasMany(NodeVolumeCopy::class);
    }

    /**
     * The Coolify volume names of the last complete inventory, or null when Sentinel does not
     * report volumes or the inventory is older than five minutes.
     *
     * @return list<string>|null
     */
    public function freshVolumeInventory(): ?array
    {
        $observedAt = data_get($this->metadata, 'container_inventory_observed_at');
        if (! is_array($this->volume_inventory) || ! is_string($observedAt)) {
            return null;
        }

        try {
            return Carbon::parse($observedAt)->isAfter(now()->subMinutes(5)) ? array_values($this->volume_inventory) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isNonRoot(): bool
    {
        return $this->user !== 'root';
    }

    public function isIpv6(): bool
    {
        return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    public function ensureValidSentinelToken(): string
    {
        $token = $this->sentinel_token;
        if (! is_string($token) || ! preg_match('/\A[a-zA-Z0-9._\-+=\/]+\z/', $token)) {
            $token = $this->newSentinelToken();
            $this->forceFill(['sentinel_token' => $token])->saveQuietly();
        }

        return $token;
    }

    /**
     * The node UUID routes the lookup; the nonce makes every issued token distinct so rotation revokes the old one.
     */
    public function newSentinelToken(): string
    {
        return encrypt(json_encode(['node_uuid' => $this->uuid, 'nonce' => Str::random(32)]));
    }

    public function ensureSentinelUrl(): string
    {
        if (blank($this->sentinel_url)) {
            throw new RuntimeException('Set a reachable Coolify URL before enabling Sentinel.');
        }

        return $this->sentinel_url;
    }

    /**
     * The DNS label of this Node in the `nodes` discovery namespace. Sentinel
     * publishes the Node endpoint under this name.
     */
    public function discoveryDnsName(): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::slug((string) $this->name))), '-');
        if ($name === '') {
            $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $this->uuid)), '-');
        }

        return rtrim(substr($name, 0, 63), '-');
    }

    public function cacheKey(): string
    {
        return "flux:connection:{$this->uuid}";
    }

    public function hasRecentFluxHeartbeat(): bool
    {
        $connection = Cache::get($this->cacheKey());
        $heartbeat = data_get($connection, 'last_heartbeat_at');
        if (data_get($connection, 'status') !== 'connected' || ! is_string($heartbeat)) {
            return false;
        }

        try {
            return Carbon::parse($heartbeat)->isAfter(now()->subMinutes(2));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The server status shown in server lists and on the dashboard map.
     *
     * @return array{status: string, type: 'success'|'warning'|'error'}
     */
    public function statusBadge(): array
    {
        return match (true) {
            ! $this->is_reachable => ['status' => 'Unreachable', 'type' => 'error'],
            ! $this->is_usable => ['status' => 'Not ready', 'type' => 'warning'],
            ! $this->hasRecentFluxHeartbeat() => ['status' => 'Sentinel disconnected', 'type' => 'warning'],
            default => ['status' => 'Ready', 'type' => 'success'],
        };
    }

    /** A Node can take network commands when it is usable and Sentinel sent a recent Flux heartbeat. */
    public function canReceiveNetworkCommands(): bool
    {
        return $this->is_usable && $this->hasRecentFluxHeartbeat();
    }

    /**
     * Nodes whose network runs the desired revision of their cluster. Keep in sync with
     * NodeCluster::nodeNetworkState().
     */
    public function scopeNetworkConverged(Builder $query): Builder
    {
        return $query
            ->where('nodes.corrosion_status', 'converged')
            ->where(fn (Builder $query) => $query->whereNull('nodes.network_status')->orWhere('nodes.network_status', 'converged'))
            ->whereHas('cluster', fn (Builder $query) => $query->whereColumn('node_clusters.desired_revision', 'nodes.network_applied_revision'));
    }

    /** Nodes in an active cluster network, or converged Nodes in a degraded one. */
    public function scopeOnDeployableClusterNetwork(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereHas('cluster', fn (Builder $query) => $query->where('network_status', 'active'))
            ->orWhere(fn (Builder $query) => $query
                ->whereHas('cluster', fn (Builder $query) => $query->where('network_status', 'degraded'))
                ->networkConverged()));
    }

    public function supportsCapability(string $capability): ?bool
    {
        $capabilities = data_get(Cache::get($this->cacheKey()), 'capabilities') ?? $this->sentinel_capabilities;
        if (! is_array($capabilities)) {
            return null;
        }

        return in_array($capability, $capabilities, true);
    }

    /**
     * Merges keys into `metadata` under a row lock. Flux refreshes and network reconciliation write
     * different keys concurrently; writing back a whole copy loaded earlier would drop the other
     * writer's keys, such as the ingress state recorded while a `system.info` request was waiting.
     *
     * @param  array<string, mixed>  $values  Metadata keys to set.
     * @param  array<string, mixed>  $attributes  Other columns to update in the same write.
     */
    public function mergeMetadata(array $values, array $attributes = []): void
    {
        $saved = DB::transaction(function () use ($values, $attributes): self {
            $locked = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $locked->forceFill([...$attributes, 'metadata' => [...$metadata, ...$values]])->save();

            return $locked;
        });
        $columns = ['metadata', 'updated_at', ...array_keys($attributes)];
        foreach ($columns as $column) {
            $this->setAttribute($column, $saved->getAttribute($column));
        }
        $this->syncOriginalAttributes($columns);
    }

    /**
     * Whether a network run applied this revision or a newer one up to its last step: the
     * firewall, and the ingress routes and internal names that every Node applies.
     */
    public function hasAppliedNetworkRevision(int $revision): bool
    {
        return (int) $this->network_applied_revision >= $revision
            && (int) data_get($this->metadata, 'firewall_applied_revision') >= $revision
            && (int) data_get($this->metadata, 'ingress_applied_revision') >= $revision;
    }

    /** Whether Sentinel on this Node reported that it can run the ingress proxy. */
    public function supportsIngress(): bool
    {
        return $this->supportsCapability(ReconcileNodeClusterNetwork::INGRESS_CAPABILITY) === true;
    }

    public function ensureCapability(string $capability): void
    {
        if ($this->supportsCapability($capability) === false) {
            throw new RuntimeException("This server does not support {$capability}. Upgrade Sentinel and try again.");
        }
    }

    /**
     * Unlike ensureCapability(), an unknown capability list also fails. Volume commands need
     * this: an older Sentinel ignores unknown fields and would run a container without its data.
     */
    public function ensureReportedCapability(string $capability): void
    {
        if ($this->supportsCapability($capability) !== true) {
            throw new RuntimeException("Server {$this->name} does not support {$capability}. Upgrade Sentinel and try again.");
        }
    }

    public function runningSentinelVersion(): ?string
    {
        $version = $this->sentinel_version
            ?? data_get(Cache::get($this->cacheKey()), 'sentinel_version')
            ?? data_get($this->metadata, 'sentinel_version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /** @param array{version: string}|null $release */
    public function needsSentinelUpgrade(?array $release): bool
    {
        return FetchLatestSentinelRelease::isUpgradeAvailable($this->runningSentinelVersion(), $release);
    }

    public function restartSentinel(): ?string
    {
        return instant_remote_process(['systemctl restart sentinel.service'], $this);
    }
}
