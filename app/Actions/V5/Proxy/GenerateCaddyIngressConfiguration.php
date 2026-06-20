<?php

namespace App\Actions\V5\Proxy;

use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class GenerateCaddyIngressConfiguration
{
    use AsAction;

    /**
     * @return array{compose: string, caddyfile: string, commands: array<int, string>}
     */
    public function handle(string $basePath = '/data/coolify/v5/ingress/caddy'): array
    {
        $compose = Yaml::dump([
            'services' => [
                'caddy' => [
                    'image' => 'docker.io/library/caddy:2-alpine',
                    'container_name' => 'coolify-v5-caddy',
                    'restart' => 'unless-stopped',
                    'ports' => [
                        '80:80',
                        '443:443',
                        '443:443/udp',
                    ],
                    'volumes' => [
                        './Caddyfile:/etc/caddy/Caddyfile:ro',
                        './data:/data',
                        './config:/config',
                    ],
                ],
            ],
        ], 8, 2);

        $caddyfile = <<<'CADDY'
:80 {
    respond /coolify-health 200
    respond 404
}
CADDY;

        return [
            'compose' => $compose,
            'caddyfile' => $caddyfile,
            'commands' => [
                sprintf('if [ "$(id -u)" = "0" ]; then mkdir -p %1$s/data %1$s/config; else sudo mkdir -p %1$s/data %1$s/config; fi', $basePath),
                sprintf("printf '%%s' '%s' | base64 -d | if [ \"\$(id -u)\" = \"0\" ]; then tee %s/docker-compose.yml > /dev/null; else sudo tee %s/docker-compose.yml > /dev/null; fi", base64_encode($compose), $basePath, $basePath),
                sprintf("printf '%%s' '%s' | base64 -d | if [ \"\$(id -u)\" = \"0\" ]; then tee %s/Caddyfile > /dev/null; else sudo tee %s/Caddyfile > /dev/null; fi", base64_encode($caddyfile), $basePath, $basePath),
                'if command -v podman >/dev/null 2>&1; then runtime="sudo podman"; elif command -v docker >/dev/null 2>&1; then runtime=docker; else echo "Neither podman nor docker is installed" >&2; exit 1; fi; $runtime pull docker.io/library/caddy:2-alpine',
                'if command -v podman >/dev/null 2>&1; then runtime="sudo podman"; elif command -v docker >/dev/null 2>&1; then runtime=docker; else echo "Neither podman nor docker is installed" >&2; exit 1; fi; $runtime rm -f coolify-v5-caddy 2>/dev/null || true',
                "if command -v podman >/dev/null 2>&1; then runtime=\"sudo podman\"; elif command -v docker >/dev/null 2>&1; then runtime=docker; else echo \"Neither podman nor docker is installed\" >&2; exit 1; fi; \$runtime run -d --name coolify-v5-caddy --restart unless-stopped -p 80:80 -p 443:443 -p 443:443/udp -v {$basePath}/Caddyfile:/etc/caddy/Caddyfile:ro -v {$basePath}/data:/data -v {$basePath}/config:/config docker.io/library/caddy:2-alpine",
            ],
        ];
    }
}
