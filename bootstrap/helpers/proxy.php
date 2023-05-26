<?php

use App\Models\Server;
use Symfony\Component\Yaml\Yaml;

if (!function_exists('getProxyConfiguration')) {
    function getProxyConfiguration(Server $server)
    {
        $proxy_path = config('coolify.proxy_config_path');
        if (config('app.env') === 'local') {
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
                    "oom_kill_disable" => true,
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
                    "volumes" => [
                        "/var/run/docker.sock:/var/run/docker.sock:ro",
                        "{$proxy_path}:/traefik",
                    ],
                    "command" => [
                        "--api.dashboard=true",
                        "--api.insecure=true",
                        "--entrypoints.http.address=:80",
                        "--entrypoints.https.address=:443",
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
        if (config('app.env') === 'local') {
            $config['services']['traefik']['command'][] = "--log.level=debug";
        }
        return Yaml::dump($config, 4, 2);
    }
}
