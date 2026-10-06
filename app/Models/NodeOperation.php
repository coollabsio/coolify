<?php

namespace App\Models;

use App\Enums\NodeOperationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class NodeOperation extends BaseModel
{
    use HasFactory;

    /**
     * Commands that Coolify runs periodically in the background. They are
     * hidden from activity lists unless they fail.
     *
     * `discovery.corrosion.endpoints.reconcile.v1` is no longer sent. It stays
     * here so that rows from older versions stay hidden.
     */
    public const BACKGROUND_COMMAND_TYPES = [
        'container.list.v1',
        'discovery.corrosion.endpoints.reconcile.v1',
        'discovery.corrosion.inspect.v1',
        'network.firewall.inspect.v1',
        'network.wireguard.inspect.v1',
        'system.info.v1',
        'system.ping.v1',
    ];

    /** Commands that have a deployment log page on the application. */
    public const DEPLOYMENT_COMMAND_TYPES = ['workload.deploy.v1', 'workload.move.v1'];

    /** Readable names of server-level commands for activity lists. */
    private const COMMAND_LABELS = [
        'container.list.v1' => 'Refresh containers',
        'discovery.corrosion.endpoints.reconcile.v1' => 'Configure internal DNS',
        'discovery.corrosion.inspect.v1' => 'Inspect internal DNS',
        'discovery.corrosion.reconcile.v1' => 'Configure internal DNS',
        'ingress.reconcile.v1' => 'Apply routes and internal names',
        'network.cluster.leave.v1' => 'Leave cluster',
        'network.firewall.inspect.v1' => 'Inspect firewall',
        'network.firewall.reconcile.v1' => 'Apply firewall rules',
        'network.wireguard.inspect.v1' => 'Inspect private network',
        'network.wireguard.key.ensure.v1' => 'Prepare private network key',
        'network.wireguard.reconcile.v1' => 'Configure private network',
        'sentinel.upgrade.v1' => 'Upgrade Sentinel',
        'system.info.v1' => 'Refresh server details',
        'system.ping.v1' => 'Ping Sentinel',
        'trust.bundle.update.v1' => 'Update trust bundle',
        'workload.deploy.v1' => 'Deploy',
        'workload.move.v1' => 'Move',
        'workload.resources.v1' => 'Update resources',
    ];

    /** Past-tense phrases for succeeded application operations, keyed by their action label. */
    private const SUCCEEDED_PHRASES = [
        'Deploy' => 'Deployed',
        'Move' => 'Moved',
        'Remove container' => 'Container removed',
        'Restart' => 'Restarted',
        'Start' => 'Started',
        'Stop' => 'Stopped',
        'Update resources' => 'Resources updated',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => NodeOperationStatus::class,
            'attempt_count' => 'integer',
            'request' => 'array',
            'result' => 'array',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function workload(): BelongsTo
    {
        return $this->belongsTo(NodeWorkload::class, 'node_workload_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(NodeWorkloadRevision::class, 'node_workload_revision_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function scopeUserFacing(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNotIn('command_type', self::BACKGROUND_COMMAND_TYPES)
            ->orWhereIn('status', [NodeOperationStatus::FAILED, NodeOperationStatus::TIMED_OUT, NodeOperationStatus::UNCERTAIN]));
    }

    public function isDeployment(): bool
    {
        return in_array($this->command_type, self::DEPLOYMENT_COMMAND_TYPES, true);
    }

    /**
     * The human-readable action, like "Deploy", "Restart", or "Move to worker-b".
     */
    public function actionLabel(?string $moveTargetName = null): string
    {
        if ($this->command_type === 'workload.lifecycle.v1') {
            return match (data_get($this->request, 'action')) {
                'start' => 'Start',
                'stop' => 'Stop',
                'restart' => 'Restart',
                'remove' => 'Remove container',
                default => 'Update container',
            };
        }
        if ($this->command_type === 'workload.move.v1') {
            return $moveTargetName === null ? 'Move' : "Move to {$moveTargetName}";
        }

        return self::COMMAND_LABELS[$this->command_type]
            ?? str($this->command_type)->beforeLast('.v')->replace(['.', '_'], ' ')->ucfirst()->toString();
    }

    public function moveTargetUuid(): ?string
    {
        return $this->command_type === 'workload.move.v1' ? data_get($this->request, 'target_node_uuid') : null;
    }

    /**
     * A short status phrase for the latest operation of an application, like
     * "Deployed", "Stop queued", or "Move failed". The caller adds the time.
     *
     * @return array{text: string, tone: 'muted'|'active'|'warning'|'error', at: Carbon}
     */
    public function activitySummary(): array
    {
        $action = $this->actionLabel();
        [$text, $tone] = match ($this->status) {
            NodeOperationStatus::SUCCEEDED => [self::SUCCEEDED_PHRASES[$action] ?? "{$action} succeeded", 'muted'],
            NodeOperationStatus::QUEUED, NodeOperationStatus::DISPATCHED => ["{$action} queued", 'active'],
            NodeOperationStatus::RUNNING, NodeOperationStatus::VERIFYING => ["{$action} started", 'active'],
            NodeOperationStatus::FAILED => ["{$action} failed", 'error'],
            NodeOperationStatus::TIMED_OUT => ["{$action} timed out", 'error'],
            NodeOperationStatus::UNCERTAIN => ["{$action} result uncertain", 'warning'],
            NodeOperationStatus::CANCELLED => ["{$action} cancelled", 'muted'],
        };
        $at = match (true) {
            $this->status->isFinal() => $this->completed_at ?? $this->updated_at ?? $this->created_at,
            $this->status->isActive() => $this->started_at ?? $this->created_at,
            default => $this->updated_at ?? $this->created_at,
        };

        return ['text' => $text, 'tone' => $tone, 'at' => $at];
    }
}
