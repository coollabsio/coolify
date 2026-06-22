<?php

namespace App\Models\V5;

use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Server extends V5Model
{
    protected $table = 'v5_servers';

    protected $fillable = [
        'uuid',
        'team_id',
        'cluster_id',
        'created_by_user_id',
        'private_key_id',
        'name',
        'host',
        'ssh_user',
        'ssh_port',
        'status',
        'ingress_type',
        'ingress_status',
        'capabilities',
        'builder_enabled',
        'builder_capacity',
        'builder_cpu_quota',
        'node_address',
        'wireguard_listen_port_override',
        'wireguard_endpoint_override',
        'wireguard_management_ip',
        'wireguard_public_key',
        'container_subnets',
        'canvas_x',
        'canvas_y',
        'last_bootstrapped_at',
        'last_bootstrap_action',
        'last_bootstrap_status',
        'last_bootstrap_output',
        'last_bootstrap_ran_at',
        'last_status_check',
        'last_status_output',
        'last_status_checked_at',
    ];

    protected static function booted(): void
    {
        static::updated(function (self $server): void {
            if (
                ! $server->wasChanged('status')
                && ! $server->wasChanged('ingress_type')
                && ! $server->wasChanged('ingress_status')
            ) {
                return;
            }

            if ($server->wasChanged('status') && $server->cluster_id !== null) {
                V5ClusterUpdated::dispatch($server->team_id, $server->cluster_id);
            }

            if ($server->wasChanged('status')) {
                V5CanvasResourceUpdated::dispatch(
                    $server->team_id,
                    null,
                    $server->isIngress() ? $server->id : null,
                    $server->id,
                );

                return;
            }

            if ($server->isIngress()) {
                V5CanvasResourceUpdated::dispatch($server->team_id, null, $server->id);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'builder_enabled' => 'boolean',
            'container_subnets' => 'array',
            'canvas_x' => 'integer',
            'canvas_y' => 'integer',
            'last_bootstrapped_at' => 'datetime',
            'last_bootstrap_ran_at' => 'datetime',
            'last_status_checked_at' => 'datetime',
        ];
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
    }

    /**
     * @return array<int, string>
     */
    public function withCapability(string $capability): array
    {
        return collect($this->capabilities ?? [])
            ->push($capability)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function withoutCapability(string $capability): array
    {
        return collect($this->capabilities ?? [])
            ->reject(fn (string $existingCapability) => $existingCapability === $capability)
            ->values()
            ->all();
    }

    public function isIngress(): bool
    {
        return $this->hasCapability('ingress');
    }

    public function ingressStatus(): string
    {
        if ($this->ingress_status !== null) {
            return $this->ingress_status;
        }

        return $this->status === 'installed' ? 'running' : 'unknown';
    }

    public function ingressType(): string
    {
        return $this->ingress_type ?? 'caddy';
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Cluster::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
