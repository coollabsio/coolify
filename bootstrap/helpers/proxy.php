<?php

use App\Actions\Proxy\SaveConfiguration;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Server;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

function get_proxy_path()
{
    $base_path = config('coolify.base_config_path');
    $proxy_path = "$base_path/proxy";
    return $proxy_path;
}
function connectProxyToNetworks(Server $server)
{
    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        });
    } else {
        // Standalone networks
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        });
    }
    // Service networks
    foreach ($server->services()->get() as $service) {
        $networks->push($service->networks());
    }
    // Docker compose based apps
    $docker_compose_apps = $server->dockerComposeBasedApplications();
    foreach ($docker_compose_apps as $app) {
        $networks->push($app->uuid);
    }
    // Docker compose based preview deployments
    $docker_compose_previews = $server->dockerComposeBasedPreviewDeployments();
    foreach ($docker_compose_previews as $preview) {
        $pullRequestId = $preview->pull_request_id;
        $applicationId = $preview->application_id;
        $application = Application::find($applicationId);
        if (!$application) {
            continue;
        }
        $network = "{$application->uuid}-{$pullRequestId}";
        $networks->push($network);
    }
    $networks = collect($networks)->flatten()->unique();
    if ($server->isSwarm()) {
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
        }
        $commands = $networks->map(function ($network) {
            return [
                "echo 'Connecting coolify-proxy to $network network...'",
                "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null || docker network create --driver overlay --attachable $network >/dev/null",
                "docker network connect $network coolify-proxy >/dev/null 2>&1 || true",
            ];
        });
    } else {
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
        $commands = $networks->map(function ($network) {
            return [
                "echo 'Connecting coolify-proxy to $network network...'",
                "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null || docker network create --attachable $network >/dev/null",
                "docker network connect $network coolify-proxy >/dev/null 2>&1 || true",
            ];
        });
    }

    return $commands->flatten();
}
function generate_default_proxy_configuration(Server $server)
{
    $proxy_path = get_proxy_path();
    if ($server->isSwarm()) {
        $networks = collect($server->swarmDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
        }
    } else {
        $networks = collect($server->standaloneDockers)->map(function ($docker) {
            return $docker['network'];
        })->unique();
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
        }
    }

    $array_of_networks = collect([]);
    $networks->map(function ($network) use ($array_of_networks) {
        $array_of_networks[$network] = [
            "external" => true,
        ];
    });
    $labels = [
        "traefik.enable=true",
        "traefik.http.routers.traefik.entrypoints=http",
        "traefik.http.routers.traefik.service=api@internal",
        "traefik.http.services.traefik.loadbalancer.server.port=8080",
    ];
    $config = [
        "version" => "3.8",
        "networks" => $array_of_networks->toArray(),
        "services" => [
            "traefik" => [
                "container_name" => "coolify-proxy",
                "image" => "traefik:v2.10",
                "restart" => RESTART_MODE,
                "extra_hosts" => [
                    "host.docker.internal:host-gateway",
                ],
                "networks" => $networks->toArray(),
                "ports" => [
                    "80:80",
                    "443:443",
                    "8080:8080",
                ],
                "healthcheck" => [
                    "test" => "wget -qO- http://localhost:80/ping || exit 1",
                    "interval" => "4s",
                    "timeout" => "2s",
                    "retries" => 5,
                ],
                "volumes" => [
                    "/var/run/docker.sock:/var/run/docker.sock:ro",
                    "{$proxy_path}:/traefik",
                ],
                "command" => [
                    "--ping=true",
                    "--ping.entrypoint=http",
                    "--api.dashboard=true",
                    "--api.insecure=false",
                    "--entrypoints.http.address=:80",
                    "--entrypoints.https.address=:443",
                    "--entrypoints.http.http.encodequerysemicolons=true",
                    "--entrypoints.https.http.encodequerysemicolons=true",
                    "--providers.docker.exposedbydefault=false",
                    "--providers.file.directory=/traefik/dynamic/",
                    "--providers.file.watch=true",
                    "--certificatesresolvers.letsencrypt.acme.httpchallenge=true",
                    "--certificatesresolvers.letsencrypt.acme.storage=/traefik/acme.json",
                    "--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=http",
                ],
                "labels" => $labels,
            ],
        ],
    ];
    if (isDev()) {
        // $config['services']['traefik']['command'][] = "--log.level=debug";
        $config['services']['traefik']['command'][] = "--accesslog.filepath=/traefik/access.log";
        $config['services']['traefik']['command'][] = "--accesslog.bufferingsize=100";
    }
    if ($server->isSwarm()) {
        data_forget($config, 'services.traefik.container_name');
        data_forget($config, 'services.traefik.restart');
        data_forget($config, 'services.traefik.labels');

        $config['services']['traefik']['command'][] = "--providers.docker.swarmMode=true";
        $config['services']['traefik']['deploy'] = [
            "labels" => $labels,
            "placement" => [
                "constraints" => [
                    "node.role==manager",
                ],
            ],
        ];
    } else {
        $config['services']['traefik']['command'][] = "--providers.docker=true";
    }
    $config = Yaml::dump($config, 12, 2);
    SaveConfiguration::run($server, $config);
    return $config;
}
function setup_dynamic_configuration()
{
    $dynamic_config_path = get_proxy_path() . "/dynamic";
    $settings = InstanceSettings::get();
    $server = Server::find(0);
    if ($server) {
        $file = "$dynamic_config_path/coolify.yaml";
        if (empty($settings->fqdn)) {
            instant_remote_process([
                "rm -f $file",
            ], $server);
        } else {
            $url = Url::fromString($settings->fqdn);
            $host = $url->getHost();
            $schema = $url->getScheme();
            $traefik_dynamic_conf = [
                'http' =>
                [
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
                    'routers' =>
                    [
                        'coolify-http' =>
                        [
                            'middlewares' => [
                                0 => 'gzip',
                            ],
                            'entryPoints' => [
                                0 => 'http',
                            ],
                            'service' => 'coolify',
                            'rule' => "Host(`{$host}`)",
                        ],
                        'coolify-realtime-ws' =>
                        [
                            'entryPoints' => [
                                0 => 'http',
                            ],
                            'service' => 'coolify-realtime',
                            'rule' => "Host(`{$host}`) && PathPrefix(`/app`)",
                        ],
                    ],
                    'services' =>
                    [
                        'coolify' =>
                        [
                            'loadBalancer' =>
                            [
                                'servers' =>
                                [
                                    0 =>
                                    [
                                        'url' => 'http://coolify:80',
                                    ],
                                ],
                            ],
                        ],
                        'coolify-realtime' =>
                        [
                            'loadBalancer' =>
                            [
                                'servers' =>
                                [
                                    0 =>
                                    [
                                        'url' => 'http://coolify-realtime:6001',
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
            }
            $yaml = Yaml::dump($traefik_dynamic_conf, 12, 2);
            $yaml =
                "# This file is automatically generated by Coolify.\n" .
                "# Do not edit it manually (only if you know what are you doing).\n\n" .
                $yaml;

            $base64 = base64_encode($yaml);
            instant_remote_process([
                "mkdir -p $dynamic_config_path",
                "echo '$base64' | base64 -d > $file",
            ], $server);

            if (config('app.env') == 'local') {
                // ray($yaml);
            }
        }
    }
}
function setup_default_redirect_404(string|null $redirect_url, Server $server)
{
    $traefik_dynamic_conf_path = get_proxy_path() . "/dynamic";
    $traefik_default_redirect_file = "$traefik_dynamic_conf_path/default_redirect_404.yaml";
    if (empty($redirect_url)) {
        instant_remote_process([
            "mkdir -p $traefik_dynamic_conf_path",
            "rm -f $traefik_default_redirect_file",
        ], $server);
    } else {
        $traefik_dynamic_conf = [
            'http' =>
            [
                'routers' =>
                [
                    'catchall' =>
                    [
                        'entryPoints' => [
                            0 => 'http',
                            1 => 'https',
                        ],
                        'service' => 'noop',
                        'rule' => "HostRegexp(`{catchall:.*}`)",
                        'priority' => 1,
                        'middlewares' => [
                            0 => 'redirect-regexp@file',
                        ],
                    ],
                ],
                'services' =>
                [
                    'noop' =>
                    [
                        'loadBalancer' =>
                        [
                            'servers' =>
                            [
                                0 =>
                                [
                                    'url' => '',
                                ],
                            ],
                        ],
                    ],
                ],
                'middlewares' =>
                [
                    'redirect-regexp' =>
                    [
                        'redirectRegex' =>
                        [
                            'regex' => '(.*)',
                            'replacement' => $redirect_url,
                            'permanent' => false,
                        ],
                    ],
                ],
            ],
        ];
        $yaml = Yaml::dump($traefik_dynamic_conf, 12, 2);
        $yaml =
            "# This file is automatically generated by Coolify.\n" .
            "# Do not edit it manually (only if you know what are you doing).\n\n" .
            $yaml;

        $base64 = base64_encode($yaml);
        instant_remote_process([
            "mkdir -p $traefik_dynamic_conf_path",
            "echo '$base64' | base64 -d > $traefik_default_redirect_file",
        ], $server);

        if (config('app.env') == 'local') {
            ray($yaml);
        }
    }
}
