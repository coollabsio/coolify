<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function ingressRules(): HasMany
    {
        return $this->hasMany(NodeIngressRule::class);
    }

    public function hasActivatedNetwork(): bool
    {
        return $this->network_status === 'active' || $this->last_reconciled_at !== null;
    }

    public function networkStatusLabel(): string
    {
        return match ($this->network_status) {
            'active', 'applied' => 'Active',
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
            'reconciling' => 'warning',
            'error', 'failed' => 'error',
            default => 'neutral',
        };
    }

    public function isNodeNetworkInSync(Node $node): bool
    {
        return $node->network_applied_revision !== null
            && (int) $node->network_applied_revision === $this->desired_revision
            && $node->corrosion_status === 'converged';
    }

    public function hasNodesNeedingAttention(): bool
    {
        return $this->nodes()
            ->where(fn ($query) => $query
                ->where('is_usable', false)
                ->orWhereNull('network_applied_revision')
                ->orWhere('network_applied_revision', '!=', $this->desired_revision)
                ->orWhereNull('corrosion_status')
                ->orWhere('corrosion_status', '!=', 'converged'))
            ->exists();
    }
}
