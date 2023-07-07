<?php

use App\Models\Server;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

function getProxyConfiguration(Server $server)
{
    $proxy_path = config('coolify.proxy_config_path');
    if (isDev()) {
        $proxy_path = $proxy_path . '/testing-host-1/';
    }
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
                "restart" => "always",
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
                    "--api.insecure=true",
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
    $traefik_dynamic_conf_path = '/data/coolify/proxy/dynamic';
    $traefik_default_redirect_file = "$traefik_dynamic_conf_path/default_redirect_404.yaml";
    if (empty($redirect_url)) {
        remote_process([
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
        remote_process([
            "mkdir -p $traefik_dynamic_conf_path",
            "echo '$base64' | base64 -d > $traefik_default_redirect_file",
        ], $server);

        if (config('app.env') == 'local') {
            ray($yaml);
        }
    }
}
