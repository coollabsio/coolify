<?php

namespace App\Models;

use App\Actions\Node\FetchLatestSentinelRelease;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Enums\NodeRole;
use App\Traits\Auditable;
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
    use Auditable, HasFactory;

    protected $guarded = [];

    protected $hidden = ['sentinel_token'];

    protected function casts(): array
    {
        return [
            'role' => NodeRole::class,
            'sentinel_token' => 'encrypted',
            'metadata' => 'array',
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
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(NodeCluster::class, 'node_cluster_id');
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function workloads(): BelongsToMany
    {
        return $this->belongsToMany(NodeWorkload::class, 'node_workload_nodes')->withPivot('container_ip')->withTimestamps();
    }

    public function containers(): HasMany
    {
        return $this->hasMany(NodeContainer::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(NodeOperation::class);
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
            $token = encrypt(json_encode(['node_uuid' => $this->uuid]));
            $this->forceFill(['sentinel_token' => $token])->saveQuietly();
        }

        return $token;
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
        $sentinelConnected = data_get(Cache::get($this->cacheKey()), 'status') === 'connected';

        return match (true) {
            ! $this->is_reachable => ['status' => 'Unreachable', 'type' => 'error'],
            ! $this->is_usable => ['status' => 'Not ready', 'type' => 'warning'],
            ! $sentinelConnected => ['status' => 'Sentinel disconnected', 'type' => 'warning'],
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

    /** Whether Sentinel on this Node reported that it can run the ingress proxy. */
    /**
     * Whether a network run applied this revision or a newer one up to its last step: the
     * firewall, and ingress on Nodes that get the ingress command.
     */
    public function hasAppliedNetworkRevision(int $revision): bool
    {
        return (int) $this->network_applied_revision >= $revision
            && (int) data_get($this->metadata, 'firewall_applied_revision') >= $revision
            && (! ($this->is_ingress || $this->supportsIngress()) || (int) data_get($this->metadata, 'ingress_applied_revision') >= $revision);
    }

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
