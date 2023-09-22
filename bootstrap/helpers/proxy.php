<?php

use App\Models\Server;
use App\Models\StandalonePostgresql;
use Symfony\Component\Yaml\Yaml;

function get_proxy_path()
{
    $base_path = config('coolify.base_config_path');
    $proxy_path = "$base_path/proxy";
    return $proxy_path;
}
function connectProxyToNetworks(Server $server) {
    $networks = collect($server->standaloneDockers)->map(function ($docker) {
        return $docker['network'];
    })->unique();
    if ($networks->count() === 0) {
        $networks = collect(['coolify']);
    }
    $commands = $networks->map(function ($network) {
        return [
            "echo '####### Connecting coolify-proxy to $network network...'",
            "docker network ls --format '{{.Name}}' | grep '^$network$' >/dev/null 2>&1 || docker network create --attachable $network > /dev/null 2>&1",
            "docker network connect $network coolify-proxy >/dev/null 2>&1 || true",
        ];
    });

    return $commands->flatten();
}
function generate_default_proxy_configuration(Server $server)
{
    $proxy_path = get_proxy_path();
    $networks = collect($server->standaloneDockers)->map(function ($docker) {
        return $docker['network'];
    })->unique();
    if ($networks->count() === 0) {
        $networks = collect(['coolify']);
    }
    $array_of_networks = collect([]);
    $networks->map(function ($network) use ($array_of_networks) {
        $array_of_networks[$network] = [
            "external" => true,
        ];
    });
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
                    "--providers.docker=true",
                    "--providers.docker.exposedbydefault=false",
                    "--providers.file.directory=/traefik/dynamic/",
                    "--providers.file.watch=true",
                    "--certificatesresolvers.letsencrypt.acme.httpchallenge=true",
                    "--certificatesresolvers.letsencrypt.acme.storage=/traefik/acme.json",
                    "--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=http",
                ],
                "labels" => [
                    "traefik.enable=true",
                    "traefik.http.routers.traefik.entrypoints=http",
                    "traefik.http.routers.traefik.middlewares=traefik-basic-auth@file",
                    "traefik.http.routers.traefik.service=api@internal",
                    "traefik.http.services.traefik.loadbalancer.server.port=8080",
                    // Global Middlewares
                    "traefik.http.middlewares.redirect-to-https.redirectscheme.scheme=https",
                    "traefik.http.middlewares.gzip.compress=true",
                ],
            ],
        ],
    ];
    if (isDev()) {
        $config['services']['traefik']['command'][] = "--log.level=debug";
    }
    return Yaml::dump($config, 4, 2);
}

function setup_default_redirect_404(string|null $redirect_url, Server $server)
{
    ray('called');
    $traefik_dynamic_conf_path = get_proxy_path() . "/dynamic";
    $traefik_default_redirect_file = "$traefik_dynamic_conf_path/default_redirect_404.yaml";
    ray($redirect_url);
    if (empty($redirect_url)) {
        instant_remote_process([
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
        ray("mkdir -p $traefik_dynamic_conf_path");
        instant_remote_process([
            "mkdir -p $traefik_dynamic_conf_path",
            "echo '$base64' | base64 -d > $traefik_default_redirect_file",
        ], $server);

        if (config('app.env') == 'local') {
            ray($yaml);
        }
    }
}

function startPostgresProxy(StandalonePostgresql $database)
{
    $containerName = "{$database->uuid}-proxy";
    $configuration_dir = database_proxy_dir($database->uuid);
    $nginxconf = <<<EOF
user  nginx;
worker_processes  auto;

error_log  /var/log/nginx/error.log;

events {
    worker_connections  1024;
}
stream {
   server {
        listen $database->public_port;
        proxy_pass $database->uuid:5432;
   }
}
EOF;
    $docker_compose = [
        'version' => '3.8',
        'services' => [
            $containerName => [
                'image' => "nginx:stable-alpine",
                'container_name' => $containerName,
                'restart' => RESTART_MODE,
                'volumes' => [
                    "$configuration_dir/nginx.conf:/etc/nginx/nginx.conf:ro",
                ],
                'ports' => [
                    "$database->public_port:$database->public_port",
                ],
                'networks' => [
                    $database->destination->network,
                ],
                'healthcheck' => [
                    'test' => [
                        'CMD-SHELL',
                        'stat /etc/nginx/nginx.conf || exit 1',
                    ],
                    'interval' => '5s',
                    'timeout' => '5s',
                    'retries' => 3,
                    'start_period' => '1s'
                ],
            ]
        ],
        'networks' => [
            $database->destination->network => [
                'external' => true,
                'name' => $database->destination->network,
                'attachable' => true,
            ]
        ]
    ];
    $dockercompose_base64 = base64_encode(Yaml::dump($docker_compose, 4, 2));
    $nginxconf_base64 = base64_encode($nginxconf);
    instant_remote_process([
        "mkdir -p $configuration_dir",
        "echo '{$nginxconf_base64}' | base64 -d > $configuration_dir/nginx.conf",
        "echo '{$dockercompose_base64}' | base64 -d > $configuration_dir/docker-compose.yaml",
        "docker compose --project-directory {$configuration_dir} up -d >/dev/null",


    ], $database->destination->server);
}
function stopPostgresProxy(StandalonePostgresql $database)
{
    instant_remote_process(["docker rm -f {$database->uuid}-proxy"], $database->destination->server);
}
