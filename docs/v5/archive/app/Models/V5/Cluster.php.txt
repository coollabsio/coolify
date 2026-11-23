<?php

namespace App\Models\V5;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cluster extends V5Model
{
    protected $table = 'v5_clusters';

    /**
     * Single source of truth for cluster defaults: `$attributes` below is
     * built from these consts, and the column defaults in
     * 2026_06_16_130649_v5_create_clusters_table mirror them (kept there for
     * historical rows only — update both when changing a default).
     */
    public const DEFAULT_WIREGUARD_INTERFACE = 'wg0';

    public const DEFAULT_WIREGUARD_MANAGEMENT_POOL = '100.64.0.0/16';

    public const DEFAULT_WIREGUARD_LISTEN_PORT = 51820;

    public const DEFAULT_CONTAINER_NETWORK_POOL = '10.210.0.0/16';

    public const DEFAULT_CONTAINER_NETWORK_PREFIX = 24;

    public const DEFAULT_NAMESPACES = ['default'];

    public const DEFAULT_COOLD_VERSION = 'nightly';

    public const DEFAULT_CORROSION_VERSION = 'v1.0.0';

    public const DEFAULT_CORROSION_GOSSIP_PORT = 8787;

    public const DEFAULT_CORROSION_API_PORT = 8080;

    public const DEFAULT_BUILDER_CAPACITY = 2;

    public const DEFAULT_BUILDER_CPU_QUOTA = '200%';

    public const DEFAULT_BUILDER_MEMORY_MAX = '2G';

    public const DEFAULT_BUILDER_TIMEOUT_SECS = 1800;

    protected $fillable = [
        'uuid',
        'team_id',
        'created_by_user_id',
        'name',
        'description',
        'wireguard_interface',
        'wireguard_management_pool',
        'wireguard_listen_port',
        'container_network_pool',
        'container_network_prefix',
        'namespaces',
        'default_deny_containers',
        'coold_version',
        'corrosion_version',
        'corrosion_gossip_port',
        'corrosion_api_port',
        'builder_enabled',
        'builder_capacity',
        'builder_cpu_quota',
        'builder_memory_max',
        'builder_timeout_secs',
        'last_cli_action',
        'last_cli_status',
        'last_cli_summary',
        'last_cli_ran_at',
    ];

    protected $attributes = [
        'wireguard_interface' => self::DEFAULT_WIREGUARD_INTERFACE,
        'wireguard_management_pool' => self::DEFAULT_WIREGUARD_MANAGEMENT_POOL,
        'wireguard_listen_port' => self::DEFAULT_WIREGUARD_LISTEN_PORT,
        'container_network_pool' => self::DEFAULT_CONTAINER_NETWORK_POOL,
        'container_network_prefix' => self::DEFAULT_CONTAINER_NETWORK_PREFIX,
        'default_deny_containers' => true,
        'coold_version' => self::DEFAULT_COOLD_VERSION,
        'corrosion_version' => self::DEFAULT_CORROSION_VERSION,
        'corrosion_gossip_port' => self::DEFAULT_CORROSION_GOSSIP_PORT,
        'corrosion_api_port' => self::DEFAULT_CORROSION_API_PORT,
        'builder_enabled' => true,
        'builder_capacity' => self::DEFAULT_BUILDER_CAPACITY,
        'builder_cpu_quota' => self::DEFAULT_BUILDER_CPU_QUOTA,
        'builder_memory_max' => self::DEFAULT_BUILDER_MEMORY_MAX,
        'builder_timeout_secs' => self::DEFAULT_BUILDER_TIMEOUT_SECS,
    ];

    protected function casts(): array
    {
        return [
            'namespaces' => 'array',
            'default_deny_containers' => 'boolean',
            'builder_enabled' => 'boolean',
            'last_cli_ran_at' => 'datetime',
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

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
