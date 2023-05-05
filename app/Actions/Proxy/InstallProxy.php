<?php

namespace App\Actions\Proxy;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Symfony\Component\Yaml\Yaml;

class InstallProxy
{
    public function __invoke(Server $server)
    {
        $docker_compose_yml_base64 = base64_encode(
            $this->getDockerComposeContents()
        );

        $env_file_base64 = base64_encode(
            $this->getEnvContents()
        );

        $activity = remoteProcess([
            "docker network ls --format '{{.Name}}' | grep '^coolify$' || docker network create coolify",
            'mkdir -p projects',
            'mkdir -p projects/proxy',
            'mkdir -p projects/proxy/letsencrypt',
            'cd projects/proxy',
            "echo '$docker_compose_yml_base64' | base64 -d > docker-compose.yml",
            "echo '$env_file_base64' | base64 -d > .env",
            'docker compose up -d --remove-orphans',
            'docker ps',
        ], $server, ActivityTypes::INLINE->value);

        return $activity;
    }

    protected function getDockerComposeContents()
    {
        return Yaml::dump($this->getComposeData());
    }

    /**
     * @return array
     */
    protected function getComposeData(): array
    {
        $cwd = config('app.env') === 'local'
            ? config('proxy.project_path_on_host') . '/_testing_hosts/host_2_proxy'
            : '.';

        ray($cwd);

        return [
            "version" => "3.7",
            "networks" => [
                "coolify" => [
                    "external" => true,
                ],
            ],
            "services" => [
                "traefik" => [
                    "container_name" => "coolify-proxy",
                    "image" => "traefik:v2.10",
                    "restart" => "always",
                    "extra_hosts" => [
                        "host.docker.internal:host-gateway",
                    ],
                    "networks" => [
                        "coolify",
                    ],
                    "ports" => [
                        "80:80",
                        "443:443",
                        "8080:8080",
                    ],
                    "volumes" => [
                        "/var/run/docker.sock:/var/run/docker.sock:ro",
                        "{$cwd}/letsencrypt:/letsencrypt",
                        "{$cwd}/traefik.auth:/auth/traefik.auth",
                    ],
                    "command" => [
                        "--api.dashboard=true",
                        "--api.insecure=true",
                        "--entrypoints.http.address=:80",
                        "--entrypoints.https.address=:443",
                        "--providers.docker=true",
                        "--providers.docker.exposedbydefault=false",
                    ],
                    "labels" => [
                        "traefik.enable=true",
                        "traefik.http.routers.traefik.entrypoints=http",
                        'traefik.http.routers.traefik.rule=Host(`${TRAEFIK_DASHBOARD_HOST}`)',
                        "traefik.http.routers.traefik.service=api@internal",
                        "traefik.http.services.traefik.loadbalancer.server.port=8080",
                        "traefik.http.middlewares.redirect-to-https.redirectscheme.scheme=https",
                    ],
                ],
            ],
        ];
    }

    protected function getEnvContents()
    {
        $data = [
            'TRAEFIK_DASHBOARD_HOST' => '',
            'LETS_ENCRYPT_EMAIL' => '',
        ];

        return collect($data)
            ->map(fn ($v, $k) => "{$k}={$v}")
            ->push(PHP_EOL)
            ->implode(PHP_EOL);
    }
}
