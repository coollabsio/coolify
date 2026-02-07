<?php

namespace App\Models;

use App\Actions\Proxy\StartProxy;
use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;
use App\Actions\Server\StartSentinel;
use App\Actions\Server\ValidatePrerequisites;
use App\Enums\ProxyTypes;
use App\Events\ServerReachabilityChanged;
use App\Helpers\SslHelper;
use App\Jobs\CheckAndStartSentinelJob;
use App\Jobs\RegenerateSslCertJob;
use App\Notifications\Server\Reachable;
use App\Notifications\Server\Unreachable;
use App\Services\ConfigurationRepository;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\HasMetrics;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use OpenApi\Attributes as OA;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\SchemalessAttributes\SchemalessAttributesTrait;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

/**
 * @property array{
 *     current: string,
 *     latest: string,
 *     type: 'patch_update'|'minor_upgrade',
 *     checked_at: string,
 *     newer_branch_target?: string,
 *     newer_branch_latest?: string,
 *     upgrade_target?: string
 * }|null $traefik_outdated_info Traefik version tracking information.
 *
 * This JSON column stores information about outdated Traefik proxy versions on this server.
 * The structure varies depending on the type of update available:
 *
 * **For patch updates** (e.g., 3.5.0 → 3.5.2):
 * ```php
 * [
 *     'current' => '3.5.0',              // Current version (without 'v' prefix)
 *     'latest' => '3.5.2',               // Latest patch version available
 *     'type' => 'patch_update',          // Update type identifier
 *     'checked_at' => '2025-11-14T10:00:00Z',  // ISO8601 timestamp
 *     'newer_branch_target' => 'v3.6',   // (Optional) Available major/minor version
 *     'newer_branch_latest' => '3.6.2'   // (Optional) Latest version in that branch
 * ]
 * ```
 *
 * **For minor/major upgrades** (e.g., 3.5.6 → 3.6.2):
 * ```php
 * [
 *     'current' => '3.5.6',              // Current version
 *     'latest' => '3.6.2',               // Latest version in target branch
 *     'type' => 'minor_upgrade',         // Update type identifier
 *     'upgrade_target' => 'v3.6',        // Target branch (with 'v' prefix)
 *     'checked_at' => '2025-11-14T10:00:00Z'  // ISO8601 timestamp
 * ]
 * ```
 *
 * **Null value**: Set to null when:
 * - Server is fully up-to-date with the latest version
 * - Traefik image uses the 'latest' tag (no fixed version tracking)
 * - No Traefik version detected on the server
 *
 * @see \App\Jobs\CheckTraefikVersionForServerJob Where this data is populated
 * @see \App\Livewire\Server\Proxy Where this data is read and displayed
 */
#[OA\Schema(
    description: 'Server model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The server ID.'],
        'uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
        'name' => ['type' => 'string', 'description' => 'The server name.'],
        'description' => ['type' => 'string', 'description' => 'The server description.'],
        'ip' => ['type' => 'string', 'description' => 'The IP address.'],
        'user' => ['type' => 'string', 'description' => 'The user.'],
        'port' => ['type' => 'integer', 'description' => 'The port number.'],
        'proxy' => ['type' => 'object', 'description' => 'The proxy configuration.'],
        'proxy_type' => ['type' => 'string', 'enum' => ['traefik', 'caddy', 'none'], 'description' => 'The proxy type.'],
        'high_disk_usage_notification_sent' => ['type' => 'boolean', 'description' => 'The flag to indicate if the high disk usage notification has been sent.'],
        'unreachable_notification_sent' => ['type' => 'boolean', 'description' => 'The flag to indicate if the unreachable notification has been sent.'],
        'unreachable_count' => ['type' => 'integer', 'description' => 'The unreachable count for your server.'],
        'validation_logs' => ['type' => 'string', 'description' => 'The validation logs.'],
        'log_drain_notification_sent' => ['type' => 'boolean', 'description' => 'The flag to indicate if the log drain notification has been sent.'],
        'swarm_cluster' => ['type' => 'string', 'description' => 'The swarm cluster configuration.'],
        'settings' => ['$ref' => '#/components/schemas/ServerSetting'],
    ]
)]

class Server extends BaseModel
{
    use ClearsGlobalSearchCache, HasFactory, HasMetrics, SchemalessAttributesTrait, SoftDeletes;

    public static $batch_counter = 0;

    /**
     * Identity map cache for request-scoped Server lookups.
     * Prevents N+1 queries when the same Server is accessed multiple times.
     */
    private static ?array $identityMapCache = null;

    protected $appends = ['is_coolify_host'];

    protected static function booted()
    {
        static::saving(function ($server) {
            $payload = [];
            if ($server->user) {
                $payload['user'] = str($server->user)->trim();
            }
            if ($server->ip) {
                $payload['ip'] = str($server->ip)->trim();

                // Update ip_previous when ip is being changed
                if ($server->isDirty('ip') && $server->getOriginal('ip')) {
                    $payload['ip_previous'] = $server->getOriginal('ip');
                }
            }
            $server->forceFill($payload);
        });
        static::saved(function ($server) {
            if ($server->privateKey?->isDirty()) {
                refresh_server_connection($server->privateKey);
            }
        });
        static::created(function ($server) {
            ServerSetting::create([
                'server_id' => $server->id,
            ]);
            if ($server->id === 0) {
                if ($server->isSwarm()) {
                    SwarmDocker::create([
                        'id' => 0,
                        'name' => 'coolify',
                        'network' => 'coolify-overlay',
                        'server_id' => $server->id,
                    ]);
                } else {
                    StandaloneDocker::create([
                        'id' => 0,
                        'name' => 'coolify',
                        'network' => 'coolify',
                        'server_id' => $server->id,
                    ]);
                }
            } else {
                if ($server->isSwarm()) {
                    SwarmDocker::create([
                        'name' => 'coolify-overlay',
                        'network' => 'coolify-overlay',
                        'server_id' => $server->id,
                    ]);
                } else {
                    $standaloneDocker = new StandaloneDocker([
                        'name' => 'coolify',
                        'uuid' => (string) new Cuid2,
                        'network' => 'coolify',
                        'server_id' => $server->id,
                    ]);
                    $standaloneDocker->saveQuietly();
                }
            }
            if (! isset($server->proxy->redirect_enabled)) {
                $server->proxy->redirect_enabled = true;
            }
        });
        static::retrieved(function ($server) {
            if (! isset($server->proxy->redirect_enabled)) {
                $server->proxy->redirect_enabled = true;
            }
        });

        static::forceDeleting(function ($server) {
            $server->destinations()->each(function ($destination) {
                $destination->delete();
            });
            $server->settings()->delete();
            $server->sslCertificates()->delete();
        });

        static::updated(function () {
            static::flushIdentityMap();
        });
    }

    /**
     * Find a Server by ID using the identity map cache.
     * This prevents N+1 queries when the same Server is accessed multiple times.
     */
    public static function findCached($id): ?static
    {
        if ($id === null) {
            return null;
        }

        if (static::$identityMapCache === null) {
            static::$identityMapCache = [];
        }

        if (! isset(static::$identityMapCache[$id])) {
            static::$identityMapCache[$id] = static::query()->find($id);
        }

        return static::$identityMapCache[$id];
    }

    /**
     * Flush the identity map cache.
     * Called automatically on update, and should be called in tests.
     */
    public static function flushIdentityMap(): void
    {
        static::$identityMapCache = null;
    }

    protected $casts = [
        'proxy' => SchemalessAttributes::class,
        'traefik_outdated_info' => 'array',
        'logdrain_axiom_api_key' => 'encrypted',
        'logdrain_newrelic_license_key' => 'encrypted',
        'delete_unused_volumes' => 'boolean',
        'delete_unused_networks' => 'boolean',
        'unreachable_notification_sent' => 'boolean',
        'is_build_server' => 'boolean',
        'force_disabled' => 'boolean',
    ];

    protected $schemalessAttributes = [
        'proxy',
    ];

    protected $fillable = [
        'name',
        'ip',
        'port',
        'user',
        'description',
        'private_key_id',
        'cloud_provider_token_id',
        'team_id',
        'hetzner_server_id',
        'hetzner_server_status',
        'is_validating',
        'detected_traefik_version',
        'traefik_outdated_info',
    ];

    protected $guarded = [];

    use HasSafeStringAttribute;

    public function type()
    {
        return 'server';
    }

    protected function isCoolifyHost(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->id === 0;
            }
        );
    }

    public static function isReachable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true);
    }

    /**
     * Get query builder for servers owned by current team.
     * If you need all servers without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return Server::whereTeamId($teamId)->with('settings', 'swarmDockers', 'standaloneDockers')->select($selectArray->all())->orderBy('name');
    }

    /**
     * Get all servers owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return Server::ownedByCurrentTeam()->get();
        });
    }

    public static function isUsable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true)->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_swarm_worker', false)->whereRelation('settings', 'is_build_server', false)->whereRelation('settings', 'force_disabled', false);
    }

    public function settings()
    {
        return $this->hasOne(ServerSetting::class);
    }

    public function dockerCleanupExecutions()
    {
        return $this->hasMany(DockerCleanupExecution::class);
    }

    public function proxySet()
    {
        return $this->proxyType() && $this->proxyType() !== 'NONE' && $this->isFunctional() && ! $this->isSwarmWorker() && ! $this->settings->is_build_server;
    }

    public function setupDefaultRedirect()
    {
        $banner =
            "# This file is generated by Coolify, do not edit it manually.\n".
            "# Disable the default redirect to customize (only if you know what are you doing).\n\n";
        $dynamic_conf_path = $this->proxyPath().'/dynamic';
        $proxy_type = $this->proxyType();
        $redirect_enabled = $this->proxy->redirect_enabled ?? true;
        $redirect_url = $this->proxy->redirect_url;
        if (isDev()) {
            if ($proxy_type === ProxyTypes::CADDY->value) {
                $dynamic_conf_path = '/data/coolify/proxy/caddy/dynamic';
            }
        }
        if ($proxy_type === ProxyTypes::TRAEFIK->value) {
            $default_redirect_file = "$dynamic_conf_path/default_redirect_503.yaml";
        } elseif ($proxy_type === ProxyTypes::CADDY->value) {
            $default_redirect_file = "$dynamic_conf_path/default_redirect_503.caddy";
        }

        instant_remote_process([
            "mkdir -p $dynamic_conf_path",
            "rm -f $dynamic_conf_path/default_redirect_404.yaml",
            "rm -f $dynamic_conf_path/default_redirect_404.caddy",
        ], $this);

        if ($redirect_enabled === false) {
            instant_remote_process(["rm -f $default_redirect_file"], $this);
        } else {
            if ($proxy_type === ProxyTypes::CADDY->value) {
                if (filled($redirect_url)) {
                    $conf = ":80, :443 {
   redir $redirect_url
}";
                } else {
                    $conf = ':80, :443 {
    respond 503
}';
                }
            } elseif ($proxy_type === ProxyTypes::TRAEFIK->value) {
                $dynamic_conf = [
                    'http' => [
                        'routers' => [
                            'catchall' => [
                                'entryPoints' => [
                                    0 => 'http',
                                    1 => 'https',
                                ],
                                'service' => 'noop',
                                'rule' => 'PathPrefix(`/`)',
                                'tls' => [
                                    'certResolver' => 'letsencrypt',
                                ],
                                'priority' => -1000,
                            ],
                        ],
                        'services' => [
                            'noop' => [
                                'loadBalancer' => [
                                    'servers' => [],
                                ],
                            ],
                        ],
                    ],
                ];
                if (filled($redirect_url)) {
                    $dynamic_conf['http']['routers']['catchall']['middlewares'] = [
                        0 => 'redirect-regexp',
                    ];

                    $dynamic_conf['http']['services']['noop']['loadBalancer']['servers'][0] = [
                        'url' => '',
                    ];
                    $dynamic_conf['http']['middlewares'] = [
                        'redirect-regexp' => [
                            'redirectRegex' => [
                                'regex' => '(.*)',
                                'replacement' => $redirect_url,
                                'permanent' => false,
                            ],
                        ],
                    ];
                }
                $conf = Yaml::dump($dynamic_conf, 12, 2);
            }
            $conf = $banner.$conf;
            $base64 = base64_encode($conf);
            instant_remote_process([
                "echo '$base64' | base64 -d | tee $default_redirect_file > /dev/null",
            ], $this);
        }

        if ($proxy_type === 'CADDY') {
            $this->reloadCaddy();
        }
    }

    public function setupDynamicProxyConfiguration()
    {
        $settings = instanceSettings();
        $dynamic_config_path = $this->proxyPath().'/dynamic';
        if ($this->proxyType() === ProxyTypes::TRAEFIK->value) {
            $file = "$dynamic_config_path/coolify.yaml";
            if (empty($settings->fqdn) || (isCloud() && $this->id !== 0) || ! $this->isLocalhost()) {
                instant_remote_process([
                    "rm -f $file",
                ], $this);
            } else {
                $url = Url::fromString($settings->fqdn);
                $host = $url->getHost();
                $schema = $url->getScheme();
                $traefik_dynamic_conf = [
                    'http' => [
                        'middlewares' => [
                            'redirect-to-https' => [
                                'redirectscheme' => [
                                    'scheme' => 'https',
                                ],
                            ],
                            'gzip' => [
                                'compress' => true,
                            ],
                        ],
                        'routers' => [
                            'coolify-http' => [
                                'middlewares' => [
                                    0 => 'gzip',
                                ],
                                'entryPoints' => [
                                    0 => 'http',
                                ],
                                'service' => 'coolify',
                                'rule' => "Host(`{$host}`)",
                            ],
                            'coolify-realtime-ws' => [
                                'entryPoints' => [
                                    0 => 'http',
                                ],
                                'service' => 'coolify-realtime',
                                'rule' => "Host(`{$host}`) && PathPrefix(`/app`)",
                            ],
                            'coolify-terminal-ws' => [
                                'entryPoints' => [
                                    0 => 'http',
                                ],
                                'service' => 'coolify-terminal',
                                'rule' => "Host(`{$host}`) && PathPrefix(`/terminal/ws`)",
                            ],
                        ],
                        'services' => [
                            'coolify' => [
                                'loadBalancer' => [
                                    'servers' => [
                                        0 => [
                                            'url' => 'http://coolify:8080',
                                        ],
                                    ],
                                ],
                            ],
                            'coolify-realtime' => [
                                'loadBalancer' => [
                                    'servers' => [
                                        0 => [
                                            'url' => 'http://coolify-realtime:6001',
                                        ],
                                    ],
                                ],
                            ],
                            'coolify-terminal' => [
                                'loadBalancer' => [
                                    'servers' => [
                                        0 => [
                                            'url' => 'http://coolify-realtime:6002',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];

                if ($schema === 'https') {
                    $traefik_dynamic_conf['http']['routers']['coolify-http']['middlewares'] = [
                        0 => 'redirect-to-https',
                    ];

                    $traefik_dynamic_conf['http']['routers']['coolify-https'] = [
                        'entryPoints' => [
                            0 => 'https',
                        ],
                        'service' => 'coolify',
                        'rule' => "Host(`{$host}`)",
                        'tls' => [
                            'certresolver' => 'letsencrypt',
                        ],
                    ];
                    $traefik_dynamic_conf['http']['routers']['coolify-realtime-wss'] = [
                        'entryPoints' => [
                            0 => 'https',
                        ],
                        'service' => 'coolify-realtime',
                        'rule' => "Host(`{$host}`) && PathPrefix(`/app`)",
                        'tls' => [
                            'certresolver' => 'letsencrypt',
                        ],
                    ];
                    $traefik_dynamic_conf['http']['routers']['coolify-terminal-wss'] = [
                        'entryPoints' => [
                            0 => 'https',
                        ],
                        'service' => 'coolify-terminal',
                        'rule' => "Host(`{$host}`) && PathPrefix(`/terminal/ws`)",
                        'tls' => [
                            'certresolver' => 'letsencrypt',
                        ],
                    ];
                }
                $yaml = Yaml::dump($traefik_dynamic_conf, 12, 2);
                $yaml =
                    "# This file is automatically generated by Coolify.\n".
                    "# Do not edit it manually (only if you know what are you doing).\n\n".
                    $yaml;

                $base64 = base64_encode($yaml);
                instant_remote_process([
                    "mkdir -p $dynamic_config_path",
                    "echo '$base64' | base64 -d | tee $file > /dev/null",
                ], $this);
            }
        } elseif ($this->proxyType() === 'CADDY') {
            $file = "$dynamic_config_path/coolify.caddy";
            if (empty($settings->fqdn) || (isCloud() && $this->id !== 0) || ! $this->isLocalhost()) {
                instant_remote_process([
                    "rm -f $file",
                ], $this);
                $this->reloadCaddy();
            } else {
                $url = Url::fromString($settings->fqdn);
                $host = $url->getHost();
                $schema = $url->getScheme();
                $caddy_file = "
$schema://$host {
    handle /app/* {
        reverse_proxy coolify-realtime:6001
    }
    handle /terminal/ws {
        reverse_proxy coolify-realtime:6002
    }
    reverse_proxy coolify:8080
}";
                $base64 = base64_encode($caddy_file);
                instant_remote_process([
                    "echo '$base64' | base64 -d | tee $file > /dev/null",
                ], $this);
                $this->reloadCaddy();
            }
        }
    }

    public function reloadCaddy()
    {
        return instant_remote_process([
            'docker exec coolify-proxy caddy reload --config /config/caddy/Caddyfile.autosave',
        ], $this);
    }

    public function proxyPath()
    {
        $base_path = config('constants.coolify.base_config_path');
        $proxyType = $this->proxyType();
        $proxy_path = "$base_path/proxy";

        if ($proxyType === ProxyTypes::TRAEFIK->value) {
            $proxy_path = $proxy_path.'/';
        } elseif ($proxyType === ProxyTypes::CADDY->value) {
            $proxy_path = $proxy_path.'/caddy';
        } elseif ($proxyType === ProxyTypes::NGINX->value) {
            $proxy_path = $proxy_path.'/nginx';
        }

        return $proxy_path;
    }

    public function proxyType()
    {
        return data_get($this->proxy, 'type');
    }

    public function scopeWithProxy(): Builder
    {
        return $this->proxy->modelScope();
    }

    public function scopeWhereProxyType(Builder $query, string $proxyType): Builder
    {
        return $query->where('proxy->type', $proxyType);
    }

    public function isLocalhost()
    {
        return $this->ip === 'host.docker.internal' || $this->id === 0;
    }

    public static function buildServers($teamId)
    {
        return Server::whereTeamId($teamId)->whereRelation('settings', 'is_reachable', true)->whereRelation('settings', 'is_build_server', true);
    }

    public function isForceDisabled()
    {
        return $this->settings->force_disabled;
    }

    public function forceEnableServer()
    {
        $this->settings->force_disabled = false;
        $this->settings->save();
    }

    public function forceDisableServer()
    {
        $this->settings->force_disabled = true;
        $this->settings->save();
        $sshKeyFileLocation = "id.root@{$this->uuid}";
        Storage::disk('ssh-keys')->delete($sshKeyFileLocation);
        $this->disableSshMux();
    }

    public function sentinelHeartbeat(bool $isReset = false)
    {
        $this->sentinel_updated_at = $isReset ? now()->subMinutes(6000) : now();
        $this->save();
    }

    /**
     * Get the wait time for Sentinel to push before performing an SSH check.
     *
     * @return int The wait time in seconds.
     */
    public function waitBeforeDoingSshCheck(): int
    {
        $wait = $this->settings->sentinel_push_interval_seconds * 3;
        if ($wait < 120) {
            $wait = 120;
        }

        return $wait;
    }

    public function isSentinelLive()
    {
        return Carbon::parse($this->sentinel_updated_at)->isAfter(now()->subSeconds($this->waitBeforeDoingSshCheck()));
    }

    public function isSentinelEnabled()
    {
        return ($this->isMetricsEnabled() || $this->isServerApiEnabled()) && ! $this->isBuildServer();
    }

    public function isMetricsEnabled()
    {
        return $this->settings->is_metrics_enabled;
    }

    public function isServerApiEnabled()
    {
        return $this->settings->is_sentinel_enabled;
    }

    public function checkSentinel()
    {
        CheckAndStartSentinelJob::dispatch($this);
    }

    public function getDiskUsage(): ?string
    {
        return instant_remote_process(['df / --output=pcent | tr -cd 0-9'], $this, false);
        // return instant_remote_process(["df /| tail -1 | awk '{ print $5}' | sed 's/%//g'"], $this, false);
    }

    public function definedResources()
    {
        $applications = $this->applications();
        $databases = $this->databases();
        $services = $this->services();

        return $applications->concat($databases)->concat($services->get());
    }

    public function stopUnmanaged($id)
    {
        return instant_remote_process(["docker stop -t 0 $id"], $this);
    }

    public function restartUnmanaged($id)
    {
        return instant_remote_process(["docker restart $id"], $this);
    }

    public function startUnmanaged($id)
    {
        return instant_remote_process(["docker start $id"], $this);
    }

    public function getContainers()
    {
        $containers = collect([]);
        $containerReplicates = collect([]);
        if ($this->isSwarm()) {
            $containers = instant_remote_process_with_timeout(["docker service inspect $(docker service ls -q) --format '{{json .}}'"], $this, false);
            $containers = format_docker_command_output_to_json($containers);
            $containerReplicates = instant_remote_process_with_timeout(["docker service ls --format '{{json .}}'"], $this, false);
            if ($containerReplicates) {
                $containerReplicates = format_docker_command_output_to_json($containerReplicates);
                foreach ($containerReplicates as $containerReplica) {
                    $name = data_get($containerReplica, 'Name');
                    $containers = $containers->map(function ($container) use ($name, $containerReplica) {
                        if (data_get($container, 'Spec.Name') === $name) {
                            $replicas = data_get($containerReplica, 'Replicas');
                            $running = str($replicas)->explode('/')[0];
                            $total = str($replicas)->explode('/')[1];
                            if ($running === $total) {
                                data_set($container, 'State.Status', 'running');
                                data_set($container, 'State.Health.Status', 'healthy');
                            } else {
                                data_set($container, 'State.Status', 'starting');
                                data_set($container, 'State.Health.Status', 'unhealthy');
                            }
                        }

                        return $container;
                    });
                }
            }
        } else {
            $containers = instant_remote_process_with_timeout(["docker container inspect $(docker container ls -aq) --format '{{json .}}'"], $this, false);
            $containers = format_docker_command_output_to_json($containers);
            $containerReplicates = collect([]);
        }

        return [
            'containers' => collect($containers) ?? collect([]),
            'containerReplicates' => collect($containerReplicates) ?? collect([]),
        ];
    }

    public function loadAllContainers(): Collection
    {
        if ($this->isFunctional()) {
            $containers = instant_remote_process(["docker ps -a --format '{{json .}}'"], $this);
            $containers = format_docker_command_output_to_json($containers);

            return collect($containers);
        }

        return collect([]);
    }

    public function loadUnmanagedContainers(): Collection
    {
        if ($this->isFunctional()) {
            $containers = instant_remote_process(["docker ps -a --format '{{json .}}'"], $this);
            $containers = format_docker_command_output_to_json($containers);
            $containers = $containers->map(function ($container) {
                $labels = data_get($container, 'Labels');
                if (! str($labels)->contains('coolify.managed')) {
                    return $container;
                }

                return null;
            });
            $containers = $containers->filter();

            return collect($containers);
        } else {
            return collect([]);
        }
    }

    public function hasDefinedResources()
    {
        $applications = $this->applications()->count() > 0;
        $databases = $this->databases()->count() > 0;
        $services = $this->services()->count() > 0;
        if ($applications || $databases || $services) {
            return true;
        }

        return false;
    }

    public function databases()
    {
        // Get destination IDs for this server in two efficient queries
        $standaloneDockerIds = StandaloneDocker::where('server_id', $this->id)->pluck('id');
        $swarmDockerIds = SwarmDocker::where('server_id', $this->id)->pluck('id');

        $destinationCondition = function ($query) use ($standaloneDockerIds, $swarmDockerIds) {
            $query->where(function ($q) use ($standaloneDockerIds) {
                $q->where('destination_type', StandaloneDocker::class)
                    ->whereIn('destination_id', $standaloneDockerIds);
            })->orWhere(function ($q) use ($swarmDockerIds) {
                $q->where('destination_type', SwarmDocker::class)
                    ->whereIn('destination_id', $swarmDockerIds);
            });
        };

        // Query each database type with the destination condition
        $postgresqls = StandalonePostgresql::where($destinationCondition)->get();
        $redis = StandaloneRedis::where($destinationCondition)->get();
        $mongodbs = StandaloneMongodb::where($destinationCondition)->get();
        $mysqls = StandaloneMysql::where($destinationCondition)->get();
        $mariadbs = StandaloneMariadb::where($destinationCondition)->get();
        $keydbs = StandaloneKeydb::where($destinationCondition)->get();
        $dragonflies = StandaloneDragonfly::where($destinationCondition)->get();
        $clickhouses = StandaloneClickhouse::where($destinationCondition)->get();

        return $postgresqls
            ->concat($redis)
            ->concat($mongodbs)
            ->concat($mysqls)
            ->concat($mariadbs)
            ->concat($keydbs)
            ->concat($dragonflies)
            ->concat($clickhouses)
            ->filter(fn ($item) => data_get($item, 'name') !== 'coolify-db');
    }

    public function applications()
    {
        // Get destination IDs for this server in two efficient queries
        $standaloneDockerIds = StandaloneDocker::where('server_id', $this->id)->pluck('id');
        $swarmDockerIds = SwarmDocker::where('server_id', $this->id)->pluck('id');

        // Query all applications in a single query using polymorphic conditions
        $applications = Application::where(function ($query) use ($standaloneDockerIds, $swarmDockerIds) {
            $query->where(function ($q) use ($standaloneDockerIds) {
                $q->where('destination_type', StandaloneDocker::class)
                    ->whereIn('destination_id', $standaloneDockerIds);
            })->orWhere(function ($q) use ($swarmDockerIds) {
                $q->where('destination_type', SwarmDocker::class)
                    ->whereIn('destination_id', $swarmDockerIds);
            });
        })->get();

        // Get additional server applications
        $additionalApplicationIds = DB::table('additional_destinations')
            ->where('server_id', $this->id)
            ->pluck('application_id');

        if ($additionalApplicationIds->isNotEmpty()) {
            $additionalApps = Application::whereIn('id', $additionalApplicationIds)->get();
            $applications = $applications->concat($additionalApps);
        }

        return $applications;
    }

    public function dockerComposeBasedApplications()
    {
        return $this->applications()->filter(function ($application) {
            return data_get($application, 'build_pack') === 'dockercompose';
        });
    }

    public function dockerComposeBasedPreviewDeployments()
    {
        return $this->previews()->filter(function ($preview) {
            $applicationId = data_get($preview, 'application_id');
            $application = Application::find($applicationId);
            if (! $application) {
                return false;
            }

            return data_get($application, 'build_pack') === 'dockercompose';
        });
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function port(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return (int) preg_replace('/[^0-9]/', '', $value);
            }
        );
    }

    public function user(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return preg_replace('/[^A-Za-z0-9\-_]/', '', $value);
            }
        );
    }

    public function ip(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                return preg_replace('/[^0-9a-zA-Z.:%-]/', '', $value);
            }
        );
    }

    public function getIp(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (isDev()) {
                    return '127.0.0.1';
                }
                if ($this->isLocalhost()) {
                    return base_ip();
                }

                return $this->ip;
            }
        );
    }

    public function previews()
    {
        return $this->destinations()->map(function ($standaloneDocker) {
            return $standaloneDocker->applications->map(function ($application) {
                return $application->previews;
            })->flatten();
        })->flatten();
    }

    public function destinations()
    {
        $standalone_docker = $this->hasMany(StandaloneDocker::class)->get();
        $swarm_docker = $this->hasMany(SwarmDocker::class)->get();

        return $standalone_docker->concat($swarm_docker);
    }

    public function standaloneDockers()
    {
        return $this->hasMany(StandaloneDocker::class);
    }

    public function swarmDockers()
    {
        return $this->hasMany(SwarmDocker::class);
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function cloudProviderToken()
    {
        return $this->belongsTo(CloudProviderToken::class);
    }

    public function sslCertificates()
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function muxFilename()
    {
        return 'mux_'.$this->uuid;
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isProxyShouldRun()
    {
        // TODO: Do we need "|| $this->proxy->force_stop" here?
        if ($this->proxyType() === ProxyTypes::NONE->value || $this->isBuildServer()) {
            return false;
        }

        return true;
    }

    public function skipServer()
    {
        if ($this->ip === '1.2.3.4') {
            return true;
        }
        if ($this->settings->force_disabled === true) {
            return true;
        }

        return false;
    }

    public function isFunctional()
    {
        $isFunctional = data_get($this->settings, 'is_reachable') && data_get($this->settings, 'is_usable') && data_get($this->settings, 'force_disabled') === false && $this->ip !== '1.2.3.4';

        if ($isFunctional === false) {
            Storage::disk('ssh-mux')->delete($this->muxFilename());
        }

        return $isFunctional;
    }

    public function isLogDrainEnabled()
    {
        return $this->settings->is_logdrain_newrelic_enabled || $this->settings->is_logdrain_highlight_enabled || $this->settings->is_logdrain_axiom_enabled || $this->settings->is_logdrain_custom_enabled;
    }

    public function validateOS(): bool|Stringable
    {
        $os_release = instant_remote_process(['cat /etc/os-release'], $this);
        $releaseLines = collect(explode("\n", $os_release));
        $collectedData = collect([]);
        foreach ($releaseLines as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1], " \t\n\r\0\x0B\"");
                $collectedData->put($key, strtolower($value));
            }
        }
        $ID = data_get($collectedData, 'ID');
        $ID_LIKE = data_get($collectedData, 'ID_LIKE', '');

        $idsToCheck = collect([$ID])->concat(explode(' ', $ID_LIKE))->filter()->unique();

        $supported = collect(SUPPORTED_OS)->filter(function ($supportedOsGroup) use ($idsToCheck) {
            return $idsToCheck->contains(function ($id) use ($supportedOsGroup) {
                return str_contains($supportedOsGroup, $id);
            });
        });

        if ($supported->isNotEmpty()) {
            return str($supported->first());
        } else {
            return false;
        }
    }

    public function isTerminalEnabled()
    {
        return $this->settings->is_terminal_enabled ?? false;
    }

    public function isSwarm()
    {
        return data_get($this, 'settings.is_swarm_manager') || data_get($this, 'settings.is_swarm_worker');
    }

    public function isSwarmManager()
    {
        return data_get($this, 'settings.is_swarm_manager');
    }

    public function isSwarmWorker()
    {
        return data_get($this, 'settings.is_swarm_worker');
    }

    public function serverStatus(): bool
    {
        if ($this->status() === false) {
            return false;
        }
        if ($this->isFunctional() === false) {
            return false;
        }

        return true;
    }

    public function status(): bool
    {
        ['uptime' => $uptime] = $this->validateConnection();
        if ($uptime === false) {
            foreach ($this->applications() as $application) {
                $application->status = 'exited';
                $application->save();
            }
            foreach ($this->databases() as $database) {
                $database->status = 'exited';
                $database->save();
            }
            foreach ($this->services() as $service) {
                $apps = $service->applications()->get();
                $dbs = $service->databases()->get();
                foreach ($apps as $app) {
                    $app->status = 'exited';
                    $app->save();
                }
                foreach ($dbs as $db) {
                    $db->status = 'exited';
                    $db->save();
                }
            }

            return false;
        }

        return true;
    }

    public function isReachableChanged()
    {
        $this->refresh();
        $unreachableNotificationSent = (bool) $this->unreachable_notification_sent;
        $isReachable = (bool) $this->settings->is_reachable;
        if ($isReachable === true) {
            $this->unreachable_count = 0;
            $this->save();

            if ($unreachableNotificationSent === true) {
                $this->sendReachableNotification();
            }

            return;
        }

        $this->increment('unreachable_count');

        if ($this->unreachable_count === 1) {
            $this->settings->is_reachable = true;
            $this->settings->save();

            return;
        }

        if ($this->unreachable_count >= 2 && ! $unreachableNotificationSent) {
            $failedChecks = 0;
            for ($i = 0; $i < 3; $i++) {
                $status = $this->serverStatus();
                sleep(5);
                if (! $status) {
                    $failedChecks++;
                }
            }

            if ($failedChecks === 3 && ! $unreachableNotificationSent) {
                $this->sendUnreachableNotification();
            }
        }
    }

    public function sendReachableNotification()
    {
        $this->unreachable_notification_sent = false;
        $this->save();
        $this->refresh();
        $this->team->notify(new Reachable($this));
    }

    public function sendUnreachableNotification()
    {
        $this->unreachable_notification_sent = true;
        $this->save();
        $this->refresh();
        $this->team->notify(new Unreachable($this));
    }

    public function validateConnection(bool $justCheckingNewKey = false)
    {
        $this->disableSshMux();

        if ($this->skipServer()) {
            return ['uptime' => false, 'error' => 'Server skipped.'];
        }
        try {
            instant_remote_process(['ls /'], $this);
            if ($this->settings->is_reachable === false) {
                $this->settings->is_reachable = true;
                $this->settings->save();
                ServerReachabilityChanged::dispatch($this);
            }

            return ['uptime' => true, 'error' => null];
        } catch (\Throwable $e) {
            if ($justCheckingNewKey) {
                return ['uptime' => false, 'error' => 'This key is not valid for this server.'];
            }
            if ($this->settings->is_reachable === true) {
                $this->settings->is_reachable = false;
                $this->settings->save();
                ServerReachabilityChanged::dispatch($this);
            }

            return ['uptime' => false, 'error' => $e->getMessage()];
        }
    }

    public function installDocker()
    {
        return InstallDocker::run($this);
    }

    /**
     * Validate that required commands are available on the server.
     *
     * @return array{success: bool, missing: array<string>, found: array<string>}
     */
    public function validatePrerequisites(): array
    {
        return ValidatePrerequisites::run($this);
    }

    public function installPrerequisites()
    {
        return InstallPrerequisites::run($this);
    }

    public function validateDockerEngine($throwError = false)
    {
        $dockerBinary = instant_remote_process(['command -v docker'], $this, false, no_sudo: true);
        if (is_null($dockerBinary)) {
            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable. Docker Engine is not installed.');
            }

            return false;
        }
        try {
            instant_remote_process(['docker version'], $this);
        } catch (\Throwable $e) {
            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable. Docker Engine is not running.');
            }

            return false;
        }
        $this->settings->is_usable = true;
        $this->settings->save();
        $this->validateCoolifyNetwork(isSwarm: false, isBuildServer: $this->settings->is_build_server);

        return true;
    }

    public function validateDockerCompose($throwError = false)
    {
        $dockerCompose = instant_remote_process(['docker compose version'], $this, false);
        if (is_null($dockerCompose)) {
            $this->settings->is_usable = false;
            $this->settings->save();
            if ($throwError) {
                throw new \Exception('Server is not usable. Docker Compose is not installed.');
            }

            return false;
        }
        $this->settings->is_usable = true;
        $this->settings->save();

        return true;
    }

    public function validateDockerSwarm()
    {
        $swarmStatus = instant_remote_process(['docker info|grep -i swarm'], $this, false);
        $swarmStatus = str($swarmStatus)->trim()->after(':')->trim();
        if ($swarmStatus === 'inactive') {
            throw new \Exception('Docker Swarm is not initiated. Please join the server to a swarm before continuing.');

            return false;
        }
        $this->settings->is_usable = true;
        $this->settings->save();
        $this->validateCoolifyNetwork(isSwarm: true);

        return true;
    }

    public function validateDockerEngineVersion()
    {
        $dockerVersionRaw = instant_remote_process(['docker version --format json'], $this, false);
        $dockerVersionJson = json_decode($dockerVersionRaw, true);
        $dockerVersion = data_get($dockerVersionJson, 'Server.Version', '0.0.0');
        $dockerVersion = checkMinimumDockerEngineVersion($dockerVersion);
        if (is_null($dockerVersion)) {
            $this->settings->is_usable = false;
            $this->settings->save();

            return false;
        }
        $this->settings->is_reachable = true;
        $this->settings->is_usable = true;
        $this->settings->save();
        ServerReachabilityChanged::dispatch($this);

        return true;
    }

    public function validateCoolifyNetwork($isSwarm = false, $isBuildServer = false)
    {
        if ($isBuildServer) {
            return;
        }
        if ($isSwarm) {
            return instant_remote_process(['docker network create --attachable --driver overlay coolify-overlay >/dev/null 2>&1 || true'], $this, false);
        } else {
            return instant_remote_process(['docker network create coolify --attachable >/dev/null 2>&1 || true'], $this, false);
        }
    }

    public function isNonRoot()
    {
        if ($this->user instanceof Stringable) {
            return $this->user->value() !== 'root';
        }

        return $this->user !== 'root';
    }

    public function isBuildServer()
    {
        return $this->settings->is_build_server;
    }

    public static function createWithPrivateKey(array $data, PrivateKey $privateKey)
    {
        $server = new self($data);
        $server->privateKey()->associate($privateKey);
        $server->save();

        return $server;
    }

    public function updateWithPrivateKey(array $data, ?PrivateKey $privateKey = null)
    {
        $this->update($data);
        if ($privateKey) {
            $this->privateKey()->associate($privateKey);
            $this->save();
        }

        return $this;
    }

    public function storageCheck(): ?string
    {
        $commands = [
            'df / --output=pcent | tr -cd 0-9',
        ];

        return instant_remote_process($commands, $this, false);
    }

    public function isIpv6(): bool
    {
        return str($this->ip)->contains(':');
    }

    public function restartSentinel(?string $customImage = null, bool $async = true)
    {
        try {
            if ($async) {
                StartSentinel::dispatch($this, true, null, $customImage);
            } else {
                StartSentinel::run($this, true, null, $customImage);
            }
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    public function url()
    {
        return base_url().'/server/'.$this->uuid;
    }

    public function restartContainer(string $containerName)
    {
        return instant_remote_process(['docker restart '.$containerName], $this, false);
    }

    public function changeProxy(string $proxyType, bool $async = true)
    {
        $validProxyTypes = collect(ProxyTypes::cases())->map(function ($proxyType) {
            return str($proxyType->value)->lower();
        });
        if ($validProxyTypes->contains(str($proxyType)->lower())) {
            $this->proxy->set('type', str($proxyType)->upper());
            $this->proxy->set('status', 'exited');
            $this->save();
            if ($this->proxySet()) {
                if ($async) {
                    StartProxy::dispatch($this);
                } else {
                    StartProxy::run($this);
                }
            }
        } else {
            throw new \Exception('Invalid proxy type.');
        }
    }

    public function isEmpty()
    {
        return $this->applications()->count() == 0 &&
            $this->databases()->count() == 0 &&
            $this->services()->count() == 0;
    }

    private function disableSshMux(): void
    {
        $configRepository = app(ConfigurationRepository::class);
        $configRepository->disableSshMux();
    }

    public function generateCaCertificate()
    {
        try {
            ray('Generating CA certificate for server', $this->id);
            SslHelper::generateSslCertificate(
                commonName: 'Coolify CA Certificate',
                serverId: $this->id,
                isCaCertificate: true,
                validityDays: 10 * 365
            );
            $caCertificate = $this->sslCertificates()->where('is_ca_certificate', true)->first();
            ray('CA certificate generated', $caCertificate);
            if ($caCertificate) {
                $certificateContent = $caCertificate->ssl_certificate;
                $caCertPath = config('constants.coolify.base_config_path').'/ssl/';

                $commands = collect([
                    "mkdir -p $caCertPath",
                    "chown -R 9999:root $caCertPath",
                    "chmod -R 700 $caCertPath",
                    "rm -rf $caCertPath/coolify-ca.crt",
                    "echo '{$certificateContent}' > $caCertPath/coolify-ca.crt",
                    "chmod 644 $caCertPath/coolify-ca.crt",
                ]);

                instant_remote_process($commands, $this, false);

                dispatch(new RegenerateSslCertJob(
                    server_id: $this->id,
                    force_regeneration: true
                ));
            }
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
