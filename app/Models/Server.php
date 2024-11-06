<?php

namespace App\Models;

use App\Actions\Server\InstallDocker;
use App\Actions\Server\StartSentinel;
use App\Enums\ProxyTypes;
use App\Jobs\CheckAndStartSentinelJob;
use App\Notifications\Server\Reachable;
use App\Notifications\Server\Unreachable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

#[OA\Schema(
    description: 'Server model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer'],
        'uuid' => ['type' => 'string'],
        'name' => ['type' => 'string'],
        'description' => ['type' => 'string'],
        'ip' => ['type' => 'string'],
        'user' => ['type' => 'string'],
        'port' => ['type' => 'integer'],
        'proxy' => ['type' => 'object'],
        'high_disk_usage_notification_sent' => ['type' => 'boolean'],
        'unreachable_notification_sent' => ['type' => 'boolean'],
        'unreachable_count' => ['type' => 'integer'],
        'validation_logs' => ['type' => 'string'],
        'log_drain_notification_sent' => ['type' => 'boolean'],
        'swarm_cluster' => ['type' => 'string'],
        'delete_unused_volumes' => ['type' => 'boolean'],
        'delete_unused_networks' => ['type' => 'boolean'],
    ]
)]

class Server extends BaseModel
{
    use SchemalessAttributesTrait, SoftDeletes;

    public static $batch_counter = 0;

    protected static function booted()
    {
        static::saving(function ($server) {
            $payload = [];
            if ($server->user) {
                $payload['user'] = str($server->user)->trim();
            }
            if ($server->ip) {
                $payload['ip'] = str($server->ip)->trim();
            }
            $server->forceFill($payload);
        });
        static::saved(function ($server) {
            if ($server->privateKey->isDirty()) {
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
                    StandaloneDocker::create([
                        'name' => 'coolify',
                        'network' => 'coolify',
                        'server_id' => $server->id,
                    ]);
                }
            }
        });

        static::forceDeleting(function ($server) {
            $server->destinations()->each(function ($destination) {
                $destination->delete();
            });
            $server->settings()->delete();
        });
    }

    protected $casts = [
        'proxy' => SchemalessAttributes::class,
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
        'team_id',
    ];

    protected $guarded = [];

    public function type()
    {
        return 'server';
    }

    public static function isReachable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true);
    }

    public static function ownedByCurrentTeam(array $select = ['*'])
    {
        $teamId = currentTeam()->id;
        $selectArray = collect($select)->concat(['id']);

        return Server::whereTeamId($teamId)->with('settings', 'swarmDockers', 'standaloneDockers')->select($selectArray->all())->orderBy('name');
    }

    public static function isUsable()
    {
        return Server::ownedByCurrentTeam()->whereRelation('settings', 'is_reachable', true)->whereRelation('settings', 'is_usable', true)->whereRelation('settings', 'is_swarm_worker', false)->whereRelation('settings', 'is_build_server', false)->whereRelation('settings', 'force_disabled', false);
    }

    public static function destinationsByServer(string $server_id)
    {
        $server = Server::ownedByCurrentTeam()->get()->where('id', $server_id)->firstOrFail();
        $standaloneDocker = collect($server->standaloneDockers->all());
        $swarmDocker = collect($server->swarmDockers->all());

        return $standaloneDocker->concat($swarmDocker);
    }

    public function settings()
    {
        return $this->hasOne(ServerSetting::class);
    }

    public function proxySet()
    {
        return $this->proxyType() && $this->proxyType() !== 'NONE' && $this->isFunctional() && ! $this->isSwarmWorker() && ! $this->settings->is_build_server;
    }

    public function setupDefault404Redirect()
    {
        $dynamic_conf_path = $this->proxyPath().'/dynamic';
        $proxy_type = $this->proxyType();
        $redirect_url = $this->proxy->redirect_url;
        if ($proxy_type === ProxyTypes::TRAEFIK->value) {
            $default_redirect_file = "$dynamic_conf_path/default_redirect_404.yaml";
        } elseif ($proxy_type === ProxyTypes::CADDY->value) {
            $default_redirect_file = "$dynamic_conf_path/default_redirect_404.caddy";
        }
        if (empty($redirect_url)) {
            if ($proxy_type === ProxyTypes::CADDY->value) {
                $conf = ':80, :443 {
respond 404
}';
                $conf =
                    "# This file is automatically generated by Coolify.\n".
                    "# Do not edit it manually (only if you know what are you doing).\n\n".
                    $conf;
                $base64 = base64_encode($conf);
                instant_remote_process([
                    "mkdir -p $dynamic_conf_path",
                    "echo '$base64' | base64 -d | tee $default_redirect_file > /dev/null",
                ], $this);
                $this->reloadCaddy();

                return;
            }
            instant_remote_process([
                "mkdir -p $dynamic_conf_path",
                "rm -f $default_redirect_file",
            ], $this);

            return;
        }
        if ($proxy_type === ProxyTypes::TRAEFIK->value) {
            $dynamic_conf = [
                'http' => [
                    'routers' => [
                        'catchall' => [
                            'entryPoints' => [
                                0 => 'http',
                                1 => 'https',
                            ],
                            'service' => 'noop',
                            'rule' => 'HostRegexp(`.+`)',
                            'tls' => [
                                'certResolver' => 'letsencrypt',
                            ],
                            'priority' => 1,
                            'middlewares' => [
                                0 => 'redirect-regexp',
                            ],
                        ],
                    ],
                    'services' => [
                        'noop' => [
                            'loadBalancer' => [
                                'servers' => [
                                    0 => [
                                        'url' => '',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'middlewares' => [
                        'redirect-regexp' => [
                            'redirectRegex' => [
                                'regex' => '(.*)',
                                'replacement' => $redirect_url,
                                'permanent' => false,
                            ],
                        ],
                    ],
                ],
            ];
            $conf = Yaml::dump($dynamic_conf, 12, 2);
            $conf =
                "# This file is automatically generated by Coolify.\n".
                "# Do not edit it manually (only if you know what are you doing).\n\n".
                $conf;

            $base64 = base64_encode($conf);
        } elseif ($proxy_type === ProxyTypes::CADDY->value) {
            $conf = ":80, :443 {
    redir $redirect_url
}";
            $conf =
                "# This file is automatically generated by Coolify.\n".
                "# Do not edit it manually (only if you know what are you doing).\n\n".
                $conf;
            $base64 = base64_encode($conf);
        }

        instant_remote_process([
            "mkdir -p $dynamic_conf_path",
            "echo '$base64' | base64 -d | tee $default_redirect_file > /dev/null",
        ], $this);

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
                                            'url' => 'http://coolify:80',
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

                if (config('app.env') === 'local') {
                    // ray($yaml);
                }
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
    reverse_proxy coolify:80
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
        $base_path = config('coolify.base_config_path');
        $proxyType = $this->proxyType();
        $proxy_path = "$base_path/proxy";
        // TODO: should use /traefik for already exisiting configurations?
        // Should move everything except /caddy and /nginx to /traefik
        // The code needs to be modified as well, so maybe it does not worth it
        if ($proxyType === ProxyTypes::TRAEFIK->value) {
            // Do nothing
        } elseif ($proxyType === ProxyTypes::CADDY->value) {
            if (isDev()) {
                $proxy_path = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy/caddy';
            } else {
                $proxy_path = $proxy_path.'/caddy';
            }
        } elseif ($proxyType === ProxyTypes::NGINX->value) {
            if (isDev()) {
                $proxy_path = '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/proxy/nginx';
            } else {
                $proxy_path = $proxy_path.'/nginx';
            }
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
        Storage::disk('ssh-mux')->delete($this->muxFilename());
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

    public function getCpuMetrics(int $mins = 5)
    {
        if ($this->isMetricsEnabled()) {
            $from = now()->subMinutes($mins)->toIso8601ZuluString();
            $cpu = instant_remote_process(["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$this->settings->sentinel_token}\" http://localhost:8888/api/cpu/history?from=$from'"], $this, false);
            if (str($cpu)->contains('error')) {
                $error = json_decode($cpu, true);
                $error = data_get($error, 'error', 'Something is not okay, are you okay?');
                if ($error === 'Unauthorized') {
                    $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
                }
                throw new \Exception($error);
            }
            $cpu = json_decode($cpu, true);

            return collect($cpu)->map(function ($metric) {
                return [(int) $metric['time'], (float) $metric['percent']];
            });
        }
    }

    public function getMemoryMetrics(int $mins = 5)
    {
        if ($this->isMetricsEnabled()) {
            $from = now()->subMinutes($mins)->toIso8601ZuluString();
            $memory = instant_remote_process(["docker exec coolify-sentinel sh -c 'curl -H \"Authorization: Bearer {$this->settings->sentinel_token}\" http://localhost:8888/api/memory/history?from=$from'"], $this, false);
            if (str($memory)->contains('error')) {
                $error = json_decode($memory, true);
                $error = data_get($error, 'error', 'Something is not okay, are you okay?');
                if ($error === 'Unauthorized') {
                    $error = 'Unauthorized, please check your metrics token or restart Sentinel to set a new token.';
                }
                throw new \Exception($error);
            }
            $memory = json_decode($memory, true);
            $parsedCollection = collect($memory)->map(function ($metric) {
                return [(int) $metric['time'], (float) $metric['usedPercent']];
            });

            return $parsedCollection->toArray();
        }
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
            $containers = instant_remote_process(["docker service inspect $(docker service ls -q) --format '{{json .}}'"], $this, false);
            $containers = format_docker_command_output_to_json($containers);
            $containerReplicates = instant_remote_process(["docker service ls --format '{{json .}}'"], $this, false);
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
            $containers = instant_remote_process(["docker container inspect $(docker container ls -aq) --format '{{json .}}'"], $this, false);
            $containers = format_docker_command_output_to_json($containers);
            $containerReplicates = collect([]);
        }

        return [
            'containers' => collect($containers) ?? collect([]),
            'containerReplicates' => collect($containerReplicates) ?? collect([]),
        ];
    }

    public function getContainersWithSentinel(): Collection
    {
        $sentinel_found = instant_remote_process(['docker inspect coolify-sentinel'], $this, false);
        $sentinel_found = json_decode($sentinel_found, true);
        $status = data_get($sentinel_found, '0.State.Status', 'exited');
        if ($status === 'running') {
            $containers = instant_remote_process(['docker exec coolify-sentinel sh -c "curl http://127.0.0.1:8888/api/containers"'], $this, false);
            if (is_null($containers)) {
                return collect([]);
            }
            $containers = data_get(json_decode($containers, true), 'containers', []);

            return collect($containers);
        }
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
        return $this->destinations()->map(function ($standaloneDocker) {
            $postgresqls = data_get($standaloneDocker, 'postgresqls', collect([]));
            $redis = data_get($standaloneDocker, 'redis', collect([]));
            $mongodbs = data_get($standaloneDocker, 'mongodbs', collect([]));
            $mysqls = data_get($standaloneDocker, 'mysqls', collect([]));
            $mariadbs = data_get($standaloneDocker, 'mariadbs', collect([]));
            $keydbs = data_get($standaloneDocker, 'keydbs', collect([]));
            $dragonflies = data_get($standaloneDocker, 'dragonflies', collect([]));
            $clickhouses = data_get($standaloneDocker, 'clickhouses', collect([]));

            return $postgresqls->concat($redis)->concat($mongodbs)->concat($mysqls)->concat($mariadbs)->concat($keydbs)->concat($dragonflies)->concat($clickhouses);
        })->flatten()->filter(function ($item) {
            return data_get($item, 'name') !== 'coolify-db';
        });
    }

    public function applications()
    {
        $applications = $this->destinations()->map(function ($standaloneDocker) {
            return $standaloneDocker->applications;
        })->flatten();
        $additionalApplicationIds = DB::table('additional_destinations')->where('server_id', $this->id)->get('application_id');
        $additionalApplicationIds = collect($additionalApplicationIds)->map(function ($item) {
            return $item->application_id;
        });
        Application::whereIn('id', $additionalApplicationIds)->get()->each(function ($application) use ($applications) {
            $applications->push($application);
        });

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
                return preg_replace('/[^0-9]/', '', $value);
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

    public function muxFilename()
    {
        return $this->uuid;
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
        $isFunctional = $this->settings->is_reachable && $this->settings->is_usable && $this->settings->force_disabled === false && $this->ip !== '1.2.3.4';

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
            $item = str($line)->trim();
            $collectedData->put($item->before('=')->value(), $item->after('=')->lower()->replace('"', '')->value());
        }
        $ID = data_get($collectedData, 'ID');
        // $ID_LIKE = data_get($collectedData, 'ID_LIKE');
        // $VERSION_ID = data_get($collectedData, 'VERSION_ID');
        $supported = collect(SUPPORTED_OS)->filter(function ($supportedOs) use ($ID) {
            if (str($supportedOs)->contains($ID)) {
                return str($ID);
            }
        });
        if ($supported->count() === 1) {
            // ray('supported');
            return str($supported->first());
        } else {
            // ray('not supported');
            return false;
        }
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
        ['uptime' => $uptime] = $this->validateConnection(false);
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
        // If the server is reachable, send the reachable notification if it was sent before
        if ($isReachable === true) {
            if ($unreachableNotificationSent === true) {
                $this->sendReachableNotification();
            }
        } else {
            // If the server is unreachable, send the unreachable notification if it was not sent before
            if ($unreachableNotificationSent === false) {
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

    public function validateConnection(bool $isManualCheck = true, bool $justCheckingNewKey = false)
    {
        config()->set('constants.ssh.mux_enabled', ! $isManualCheck);

        if ($this->skipServer()) {
            return ['uptime' => false, 'error' => 'Server skipped.'];
        }
        try {
            // Make sure the private key is stored
            if ($this->privateKey) {
                $this->privateKey->storeInFileSystem();
            }
            instant_remote_process(['ls /'], $this);
            if ($this->settings->is_reachable === false) {
                $this->settings->is_reachable = true;
                $this->settings->save();
            }

            return ['uptime' => true, 'error' => null];
        } catch (\Throwable $e) {
            if ($justCheckingNewKey) {
                return ['uptime' => false, 'error' => 'This key is not valid for this server.'];
            }
            if ($this->settings->is_reachable === true) {
                $this->settings->is_reachable = false;
                $this->settings->save();
            }

            return ['uptime' => false, 'error' => $e->getMessage()];
        }
    }

    public function installDocker()
    {
        return InstallDocker::run($this);
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

    public function restartSentinel(bool $async = true)
    {
        try {
            if ($async) {
                StartSentinel::dispatch($this, true);
            } else {
                StartSentinel::run($this, true);
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
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
}
