<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected function casts(): array
    {
        return [
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

    public function isNodeNetworkInSync(Node $node): bool
    {
        return $this->nodeNetworkState($node) === 'converged';
    }

    /**
     * Derives the cluster network status from its member Nodes: `active` when every Node
     * converged, `degraded` when only some did, and `error` when none did.
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
