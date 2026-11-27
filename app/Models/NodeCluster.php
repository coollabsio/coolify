<?php

namespace App\Models;

use App\Actions\Node\BuildNodeClusterIngressRoutes;
use App\Actions\Node\DispatchWorkloadDeployment;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Enums\NodeImageStorage;
use App\Enums\NodeWorkloadSourceType;
use App\Support\NodeImages;
use App\Traits\Auditable;
use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class NodeCluster extends BaseModel
{
    use Auditable, ClearsGlobalSearchCache, HasFactory;

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

    public const INGRESS_ROUTES_CAPABILITY = 'ingress.routes.v2';

    /** Basic auth, the IP allow list, headers, and compression of a route (decision 0031). */
    public const INGRESS_ROUTE_OPTIONS_CAPABILITY = 'ingress.route_options.v1';

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
            'image_storage' => NodeImageStorage::class,
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

    /** The login for the external image storage. */
    public function imageRegistryCredential(): BelongsTo
    {
        return $this->belongsTo(RegistryCredential::class, 'image_registry_credential_id');
    }

    /**
     * Where the builds of this cluster go. The choice of the user when it is the external registry.
     * Otherwise the Podman store of the build Node (decision 0030), when every Node can load a build
     * and, with more Nodes, serve and copy local images between them. Null when a Node runs an
     * older Sentinel: then a build fails with imageStorageError().
     */
    public function imageStorage(): ?NodeImageStorage
    {
        if ($this->image_storage === NodeImageStorage::EXTERNAL) {
            return NodeImageStorage::EXTERNAL;
        }

        return $this->localImageStorageError() === null ? NodeImageStorage::LOCAL : null;
    }

    /** Why the builds of the cluster cannot stay on its servers, or null when they can. */
    public function localImageStorageError(): ?string
    {
        $nodes = $this->nodes()->orderBy('name')->get();
        $required = $nodes->count() > 1
            ? [NodeImages::LOAD_CAPABILITY, NodeImages::SERVE_CAPABILITY, NodeImages::FETCH_CAPABILITY]
            : [NodeImages::LOAD_CAPABILITY];
        $old = $nodes->filter(fn (Node $node): bool => collect($required)->contains(fn (string $capability): bool => $node->supportsCapability($capability) !== true));
        if ($old->isEmpty()) {
            return null;
        }

        return 'Sentinel on '.$old->pluck('name')->implode(', ').' cannot keep builds on the servers of the cluster. Upgrade Sentinel there, or select an external registry in the Advanced settings of the cluster (Image storage).';
    }

    /**
     * Whether the cluster builds images (decision 0006): a workload on its Nodes builds from source,
     * or a build ran on one of them. Then its builder Nodes run BuildKit.
     */
    public function runsBuilds(): bool
    {
        $nodeIds = $this->nodes()->pluck('id');
        if ($nodeIds->isEmpty()) {
            return false;
        }
        $buildTypes = collect(NodeWorkloadSourceType::cases())->filter(fn (NodeWorkloadSourceType $type): bool => $type->builds())->map(fn (NodeWorkloadSourceType $type): string => $type->value)->values()->all();

        return NodeBuild::query()->whereIn('node_id', $nodeIds)->exists()
            || NodeWorkload::query()->withoutGlobalScopes()->whereIn('source_type', $buildTypes)
                ->whereHas('nodes', fn ($nodes) => $nodes->whereIn('nodes.id', $nodeIds))
                ->exists();
    }

    /** Whether the user should choose the image storage: the cluster has more servers and no choice yet. Builds do not wait for it. */
    public function needsImageStorageChoice(): bool
    {
        return $this->image_storage === null && $this->nodes()->count() > 1;
    }

    /**
     * The login for a build image in the external image storage, read again at each build and
     * deploy. Null when the image is not there or the storage has no login.
     *
     * @return array{username: string, password: string}|null
     */
    public function imageStorageLogin(string $image): ?array
    {
        if ($this->image_registry_credential_id === null || NodeImages::isLocalImage($image)) {
            return null;
        }
        $credential = RegistryCredential::query()->where('team_id', $this->team_id)->find($this->image_registry_credential_id)
            ?? throw new RuntimeException("The registry credential of the image storage of the cluster {$this->name} is not available anymore. Select another one in the Advanced settings of the cluster.");

        return $credential->matchesImage($image) ? ['username' => $credential->username, 'password' => $credential->password] : null;
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /**
     * The CPU architectures that the builder Nodes of this cluster build for (decision 0020). A Git
     * application is placed on a Node of one of them. Null when there is no builder yet or the
     * architecture of one is unknown: every Node fits.
     *
     * @return list<string>|null
     */
    public function builderArchitectures(): ?array
    {
        $architectures = $this->nodes()->get()->filter(fn (Node $node): bool => $node->canBuild())->map(fn (Node $node): ?string => $node->cpuArchitecture());

        return $architectures->isEmpty() || $architectures->contains(null) ? null : $architectures->unique()->values()->all();
    }

    public function firewallRules(): HasMany
    {
        return $this->hasMany(NodeFirewallRule::class);
    }

    public function publishedPorts(): HasMany
    {
        return $this->hasMany(NodePublishedPort::class);
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

    /**
     * Whether the SSH network repair can help: the network is degraded or failed, or a server that
     * applied the network does not reach Flux. A healthy cluster does not need it.
     */
    public function needsNetworkRepair(): bool
    {
        return in_array($this->network_status, ['degraded', 'error', 'failed'], true)
            || $this->nodes()->whereNotNull('network_applied_revision')->where('is_reachable', false)->exists();
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

    /** Sentinel resolves stack names with `_` (`rallly_db`), decision 0023. */
    public const UNDERSCORE_NAMES_CAPABILITY = 'discovery.names.underscore.v1';

    /**
     * Whether every usable server of the cluster resolves stack names with `_`. One server
     * without it would reject the whole name table.
     */
    public function supportsUnderscoreNames(): bool
    {
        $nodes = $this->nodes()->where('is_usable', true)->get();

        return $nodes->isNotEmpty() && $nodes->every(fn (Node $node): bool => $node->supportsCapability(self::UNDERSCORE_NAMES_CAPABILITY) === true);
    }

    /**
     * Whether every usable server of the cluster can publish ports (decision 0016). A server
     * without it would not open the ports of a stack.
     */
    public function supportsPublishedPorts(): bool
    {
        $nodes = $this->nodes()->where('is_usable', true)->get();

        return $nodes->isNotEmpty() && $nodes->every(fn (Node $node): bool => $node->supportsCapability(ReconcileNodeClusterNetwork::PUBLISHED_PORTS_CAPABILITY) === true);
    }

    /**
     * Whether every usable server of the cluster mounts the host Podman socket in a container
     * (`container_socket_paths`). Services of a stack can run on any server of the cluster, and
     * Flux refuses the deploy on a server without it.
     */
    public function supportsContainerSocket(): bool
    {
        $nodes = $this->nodes()->where('is_usable', true)->get();

        return $nodes->isNotEmpty() && $nodes->every(fn (Node $node): bool => $node->supportsCapability(DispatchWorkloadDeployment::CONTAINER_SOCKET_CAPABILITY) === true);
    }

    /**
     * Whether every usable server of the cluster runs a container as the Compose `user:`. Services
     * of a stack can run on any server of the cluster, and Flux refuses the deploy on a server
     * without it.
     */
    public function supportsWorkloadUser(): bool
    {
        $nodes = $this->nodes()->where('is_usable', true)->get();

        return $nodes->isNotEmpty() && $nodes->every(fn (Node $node): bool => $node->supportsCapability(DispatchWorkloadDeployment::USER_CAPABILITY) === true);
    }

    /**
     * Whether every usable server of the cluster sets the runtime options of decision 0028
     * (`shm_size`, `ulimits`, `tmpfs`, `stop_grace_period`, `tty`, `stdin_open`). Services of a
     * stack can run on any server of the cluster, and Flux refuses the deploy on a server without it.
     */
    public function supportsRuntimeOptions(): bool
    {
        $nodes = $this->nodes()->where('is_usable', true)->get();

        return $nodes->isNotEmpty() && $nodes->every(fn (Node $node): bool => $node->supportsCapability(DispatchWorkloadDeployment::RUNTIME_OPTIONS_CAPABILITY) === true);
    }

    /**
     * The host ports that the Nodes of the cluster use, with what uses them (decision 0016). A
     * published port cannot take them, on TCP or UDP. Sentinel refuses the same ports, and it refuses
     * the full firewall reconcile for one of them.
     *
     * @return array<int, string>
     */
    public function reservedHostPorts(): array
    {
        $reserved = [
            53 => 'the internal DNS of the cluster',
            80 => 'HTTP ingress',
            443 => 'HTTPS ingress',
            (int) $this->wireguard_port => 'the WireGuard network of the cluster',
            ReconcileNodeClusterNetwork::CORROSION_GOSSIP_PORT => 'Corrosion service discovery',
            ReconcileNodeClusterNetwork::CORROSION_API_PORT => 'the Corrosion API',
            8888 => 'the Sentinel API',
        ];
        // Sentinel moves volumes with rsync and copies images between Nodes on these ports of the WireGuard address.
        foreach (range(7800, 7899) as $port) {
            $reserved[$port] = 'volume moves and image copies between servers';
        }
        foreach ($this->nodes()->orderBy('id')->get(['id', 'name', 'port']) as $node) {
            $reserved[(int) $node->port] ??= "SSH on server {$node->name}";
        }
        ksort($reserved);

        return $reserved;
    }

    /**
     * The host that generated domains end with, like the generated domains of v4: the wildcard
     * domain of the first ingress server, else the sslip.io name of its address. In development,
     * the ingress servers are VMs on a private network, so it uses DEV_SEED_IP, like the seeded v4
     * domains. Null without an ingress server.
     */
    public function generatedDomainBase(): ?string
    {
        $ingress = $this->nodes()->where('is_ingress', true)->orderBy('name')->first();
        $wildcard = data_get($ingress?->settings, 'wildcard_domain');
        if (filled($wildcard)) {
            return parse_url($wildcard, PHP_URL_HOST) ?: null;
        }
        $ip = isDev() ? config('constants.coolify.dev_seed_ip') : $ingress?->ip;
        if ($ip === null || filter_var(normalizeIpAddress($ip), FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return sslipHostLabel($ip).'.sslip.io';
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

    /**
     * Paths, redirects, per-domain protocol, and indexing need `ingress.routes.v2` on every member
     * Node. Without it, ingress gets one plain route per host, because an older Sentinel rejects
     * duplicate hosts and the new fields.
     */
    public function supportsIngressRoutesV2(): bool
    {
        return $this->nodes()->get()->every(fn (Node $node): bool => $node->supportsCapability(self::INGRESS_ROUTES_CAPABILITY) === true);
    }

    /**
     * The proxy options of decision 0031 need `ingress.route_options.v1` on every Node of the
     * cluster: an older Sentinel ignores them and would serve a route without its basic auth or IP
     * allow list, and an older Corrosion schema has no table for them.
     */
    public function supportsIngressRouteOptions(): bool
    {
        return $this->nodes()->get()->every(fn (Node $node): bool => $node->supportsCapability(self::INGRESS_ROUTE_OPTIONS_CAPABILITY) === true);
    }

    /** Whether a workload with domains on this cluster has basic auth or an IP allow list. */
    public function hasProtectedIngressRoutes(): bool
    {
        return BuildNodeClusterIngressRoutes::routedWorkloads($this)->contains(fn (NodeWorkload $workload): bool => $workload->hasProtectedIngressOptions());
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
