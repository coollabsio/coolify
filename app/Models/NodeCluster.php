<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class NodeCluster extends BaseModel
{
    use Auditable, HasFactory;

    protected $attributes = [
        'cpu_pressure_threshold' => 95,
        'memory_pressure_threshold' => 90,
        'disk_pressure_threshold' => 90,
        'resource_stale_after_minutes' => 5,
    ];

    protected $guarded = [];

    protected $hidden = ['ingress_tls_key'];

    /** Sentinel serves HTTPS and can be the certificate issuer. */
    public const INGRESS_TLS_CAPABILITY = 'ingress.tls.v1';

    protected function casts(): array
    {
        return [
            'ingress_tls_key' => 'encrypted',
            'ingress_tls_issuer_node_id' => 'integer',
            'desired_revision' => 'integer',
            'wireguard_port' => 'integer',
            'cpu_pressure_threshold' => 'integer',
            'memory_pressure_threshold' => 'integer',
            'disk_pressure_threshold' => 'integer',
            'resource_stale_after_minutes' => 'integer',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** The image registry of this cluster (decision 0006). */
    public function registry(): HasOne
    {
        return $this->hasOne(NodeRegistry::class);
    }

    /** How long the registry Node may be unreachable before the registry moves automatically. */
    public const REGISTRY_MOVE_AFTER_MINUTES = 10;

    /**
     * The Node that should run the registry. A selected Node is kept, also while it is offline.
     * Otherwise the registry stays on its current Node; it moves to the first available Node by
     * ID only after the current Node was unreachable for REGISTRY_MOVE_AFTER_MINUTES, because a
     * move starts an empty registry. It does not move back when the old Node returns.
     */
    public function desiredRegistryNode(): ?Node
    {
        $registry = $this->registry;
        if ($registry === null) {
            return null;
        }
        if ($registry->selected_node_id !== null) {
            return $registry->selectedNode;
        }
        $available = fn (Node $node): bool => $node->canReceiveNetworkCommands()
            && $node->supportsCapability(NodeRegistry::CAPABILITY) === true;
        $current = $registry->node;
        $awayKey = "node-registry:{$registry->id}:unreachable-since";
        if ($current !== null && $current->node_cluster_id === $this->id) {
            if ($available($current)) {
                Cache::forget($awayKey);

                return $current;
            }
            $since = Cache::get($awayKey);
            if (! is_string($since)) {
                Cache::put($awayKey, $since = now()->toIso8601String(), now()->addDay());
            }
            if (Carbon::parse($since)->isAfter(now()->subMinutes(self::REGISTRY_MOVE_AFTER_MINUTES))) {
                return $current;
            }
        }
        Cache::forget($awayKey);

        return $this->nodes()->orderBy('id')->get()->first($available);
    }

    /**
     * Records the desired registry Node. Returns whether it changed; the caller then applies a
     * network revision, so the previous Node stops zot and every Node opens the new address.
     */
    public function syncRegistryNode(): bool
    {
        $registry = $this->registry;
        if ($registry === null) {
            return false;
        }
        $nodeId = $this->desiredRegistryNode()?->id;
        if ($registry->node_id === $nodeId) {
            return false;
        }
        $registry->update(['node_id' => $nodeId]);

        return true;
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    public function firewallRules(): HasMany
    {
        return $this->hasMany(NodeFirewallRule::class);
    }

    public function hasActivatedNetwork(): bool
    {
        return in_array($this->network_status, ['active', 'degraded'], true) || $this->last_reconciled_at !== null;
    }

    /** A cluster without servers has no network yet, so it shows no network status. */
    public function hasNetworkStatus(): bool
    {
        return $this->relationLoaded('nodes') ? $this->nodes->isNotEmpty() : $this->nodes()->exists();
    }

    public function networkStatusLabel(): string
    {
        return match ($this->network_status) {
            'active', 'applied' => 'Active',
            'degraded' => 'Degraded',
            'reconciling' => 'Syncing',
            'error', 'failed' => 'Failed',
            default => 'Pending',
        };
    }

    /** @return 'success'|'warning'|'error'|'neutral' */
    public function networkStatusBadgeType(): string
    {
        return match ($this->network_status) {
            'active', 'applied' => 'success',
            'degraded', 'reconciling' => 'warning',
            'error', 'failed' => 'error',
            default => 'neutral',
        };
    }

    /**
     * The network state of one member Node. A Node is converged when it runs the desired
     * revision and its discovery converged; `pending` means it still waits for that revision.
     * Keep in sync with Node::scopeNetworkConverged().
     *
     * @return 'converged'|'pending'|'error'
     */
    public function nodeNetworkState(Node $node): string
    {
        if ($node->network_status === 'error') {
            return 'error';
        }

        return $node->network_status !== 'pending'
            && $node->network_applied_revision !== null
            && (int) $node->network_applied_revision === $this->desired_revision
            && $node->corrosion_status === 'converged'
                ? 'converged'
                : 'pending';
    }

    /**
     * The ingress state of one member Node: `active` when Sentinel runs Caddy with the desired
     * revision, `pending` while the Node still has to apply it.
     *
     * @return 'off'|'active'|'pending'
     */
    public function nodeIngressState(Node $node): string
    {
        if (! $node->is_ingress) {
            return 'off';
        }

        return data_get($node->metadata, 'ingress_active') === true
            && (int) data_get($node->metadata, 'ingress_applied_revision') === $this->desired_revision
                ? 'active'
                : 'pending';
    }

    /**
     * HTTPS needs an ACME directory, at least one ingress Node, and Sentinel with `ingress.tls.v1`
     * on every member Node, because every Node keeps the certificate tables in its Corrosion.
     */
    public function supportsIngressTls(): bool
    {
        if (blank(config('constants.coolify.acme_directory_url'))) {
            return false;
        }
        $nodes = $this->nodes()->get();

        return $nodes->contains('is_ingress', true)
            && $nodes->every(fn (Node $node): bool => $node->supportsCapability(self::INGRESS_TLS_CAPABILITY) === true);
    }

    /** The ingress Node that orders certificates: the oldest ingress Node that can take commands now. */
    public function tlsIssuerNode(): ?Node
    {
        return $this->nodes()
            ->where('is_ingress', true)
            ->orderBy('id')
            ->get()
            ->first(fn (Node $node): bool => $node->canReceiveNetworkCommands()
                && $node->supportsCapability(self::INGRESS_TLS_CAPABILITY) === true);
    }

    /**
     * The base64 32-byte key that seals certificate private keys in Corrosion. Created once; the
     * caller holds the network lock, so two runs cannot create different keys.
     */
    public function ingressTlsKey(): string
    {
        if (blank($this->ingress_tls_key)) {
            $this->forceFill(['ingress_tls_key' => base64_encode(random_bytes(32))])->save();
        }

        return $this->ingress_tls_key;
    }

    /**
     * The newest certificate report of the issuer, by host.
     *
     * @return array<string, array{host: string, status: string, not_after: int, error: string, next_attempt_at: int}>
     */
    public function ingressCertificates(): array
    {
        $report = $this->nodes()
            ->where('is_ingress', true)
            ->get()
            ->sortByDesc(fn (Node $node): int => (int) data_get($node->metadata, 'ingress_certificates_at'))
            ->first(fn (Node $node): bool => is_array(data_get($node->metadata, 'ingress_certificates')));

        return collect(data_get($report?->metadata, 'ingress_certificates', []))
            ->filter(fn ($certificate): bool => is_array($certificate) && is_string($certificate['host'] ?? null))
            ->keyBy('host')
            ->all();
    }

    /**
     * The badge for an ingress state from nodeIngressState(), or `on`/`off` for a Node without a cluster.
     *
     * @return array{status: string, type: 'success'|'warning'|'neutral'}
     */
    public static function ingressBadge(string $state): array
    {
        return match ($state) {
            'active' => ['status' => 'Active', 'type' => 'success'],
            'pending' => ['status' => 'Pending', 'type' => 'warning'],
            'on' => ['status' => 'On', 'type' => 'success'],
            default => ['status' => 'Off', 'type' => 'neutral'],
        };
    }

    public function isNodeNetworkInSync(Node $node): bool
    {
        return $this->nodeNetworkState($node) === 'converged';
    }

    /**
     * Derives the cluster network status from its member Nodes: `active` when every Node
     * converged, `degraded` when only some did, and `error` when none did. When none converged
     * because an activated network only got a new revision that no Node failed to apply yet,
     * for example right after a revision bump, the status is `pending`, not `error`.
     */
    public function deriveNetworkStatus(): string
    {
        $states = $this->nodes()->get()->map(fn (Node $node): string => $this->nodeNetworkState($node));
        if ($states->isEmpty()) {
            return 'pending';
        }
        $converged = $states->filter(fn (string $state): bool => $state === 'converged')->count();

        return match (true) {
            $converged === $states->count() => 'active',
            $converged > 0 => 'degraded',
            ! $states->contains('error') && $this->hasActivatedNetwork() => 'pending',
            default => 'error',
        };
    }

    /** Whether Nodes converge on their own: the network was activated once, or its activation failed. */
    public function convergesAutomatically(): bool
    {
        return $this->hasActivatedNetwork() || $this->network_status === 'error';
    }

    /** One network reconciliation or inspection per cluster at a time. */
    public function networkLock(): Lock
    {
        return Cache::lock("node-cluster-network:{$this->id}", 1200);
    }

    /** The team owner or admin that automatic network repairs run as. */
    public function networkOperator(): ?User
    {
        return $this->team?->members()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->orderBy('users.id')
            ->first();
    }

    public function hasNodesNeedingAttention(): bool
    {
        return $this->nodes()
            ->where(fn ($query) => $query
                ->where('is_usable', false)
                ->orWhereIn('network_status', ['pending', 'error'])
                ->orWhereNull('network_applied_revision')
                ->orWhere('network_applied_revision', '!=', $this->desired_revision)
                ->orWhereNull('corrosion_status')
                ->orWhere('corrosion_status', '!=', 'converged'))
            ->exists();
    }
}
