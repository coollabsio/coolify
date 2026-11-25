<?php

namespace App\Models\V5;

use App\Enums\V5\IngressStatus;
use App\Events\V5CanvasResourceUpdated;
use App\Events\V5ClusterUpdated;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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
        'status_observed_at',
        'ingress_type',
        'ingress_status',
        'capabilities',
        'has_coold',
        'is_ingress',
        'builder_enabled',
        'builder_capacity',
        'builder_cpu_quota',
        'node_address',
        'wireguard_listen_port_override',
        'wireguard_endpoint_override',
        'wireguard_management_ip',
        'wireguard_public_key',
        'coold_version',
        'agent_token_jti',
        'agent_token_expires_at',
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
                DB::afterCommit(fn () => V5ClusterUpdated::dispatch($server->team_id, $server->cluster_id));
            }

            if ($server->wasChanged('status')) {
                DB::afterCommit(fn () => V5CanvasResourceUpdated::dispatch(
                    $server->team_id,
                    null,
                    $server->isIngress() ? $server->id : null,
                    $server->id,
                ));

                return;
            }

            if ($server->isIngress()) {
                DB::afterCommit(fn () => V5CanvasResourceUpdated::dispatch($server->team_id, null, $server->id));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'has_coold' => 'boolean',
            'is_ingress' => 'boolean',
            'builder_enabled' => 'boolean',
            'container_subnets' => 'array',
            'canvas_x' => 'integer',
            'canvas_y' => 'integer',
            'status_observed_at' => 'datetime',
            'agent_token_expires_at' => 'datetime',
            'last_bootstrapped_at' => 'datetime',
            'last_bootstrap_ran_at' => 'datetime',
            'last_status_checked_at' => 'datetime',
        ];
    }

    public function fluxHostId(): string
    {
        return (string) $this->uuid;
    }

    /**
     * Virtual attribute kept for wire-format compatibility: capabilities are
     * stored as the indexed has_coold / is_ingress booleans, but reads and
     * writes of `capabilities` keep working with the historical string array.
     * Unknown capability names are dropped on write.
     *
     * The dropped `capabilities` column intentionally stays in `$fillable`:
     * call sites still mass-assign it, and this mutator maps those writes
     * onto the boolean columns.
     */
    protected function capabilities(): Attribute
    {
        return Attribute::make(
            get: fn () => array_values(array_filter([
                $this->has_coold ? 'coold' : null,
                $this->is_ingress ? 'ingress' : null,
            ])),
            set: fn (?array $capabilities) => [
                'has_coold' => in_array('coold', $capabilities ?? [], true),
                'is_ingress' => in_array('ingress', $capabilities ?? [], true),
            ],
        );
    }

    public function hasCapability(string $capability): bool
    {
        return match ($capability) {
            'coold' => (bool) $this->has_coold,
            'ingress' => (bool) $this->is_ingress,
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public function withCapability(string $capability): array
    {
        return collect($this->capabilities)
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
        return collect($this->capabilities)
            ->reject(fn (string $existingCapability) => $existingCapability === $capability)
            ->values()
            ->all();
    }

    public function isIngress(): bool
    {
        return (bool) $this->is_ingress;
    }

    public function ingressStatus(): string
    {
        return $this->ingress_status ?? IngressStatus::Unknown->value;
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
