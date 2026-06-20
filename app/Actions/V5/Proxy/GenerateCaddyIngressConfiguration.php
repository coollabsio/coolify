<?php

namespace App\Actions\V5\Proxy;

use App\Models\V5\Application;
use App\Models\V5\ApplicationDomain;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class GenerateCaddyIngressConfiguration
{
    use AsAction;

    /**
     * @param  Collection<int, Application>|null  $applications
     * @return array{compose: string, caddyfile: string, apps: array<int, array{name: string, caddyfile: string}>}
     */
    public function handle(?Collection $applications = null): array
    {
        return [
            'compose' => $this->compose(),
            'caddyfile' => $this->rootCaddyfile(),
            'apps' => $this->appCaddyfiles($applications ?? collect()),
        ];
    }

    private function compose(): string
    {
        return Yaml::dump([
            'services' => [
                'caddy' => [
                    'image' => 'docker.io/library/caddy:2-alpine',
                    'container_name' => 'coolify-v5-caddy',
                    'restart' => 'unless-stopped',
                    'ports' => [
                        '80:80',
                    ],
                    'volumes' => [
                        './Caddyfile:/etc/caddy/Caddyfile:ro',
                        './apps:/etc/caddy/apps:ro',
                        './data:/data',
                        './config:/config',
                    ],
                ],
            ],
        ], 8, 2);
    }

    private function rootCaddyfile(): string
    {
        return <<<'CADDY'
:80 {
    respond /coolify-health 200
    respond 404
}

import apps/*.caddy
CADDY;
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return array<int, array{name: string, caddyfile: string}>
     */
    private function appCaddyfiles(Collection $applications): array
    {
        return $applications
            ->each(fn (Application $application) => $application->loadMissing('domains'))
            ->map(fn (Application $application) => [
                'name' => $this->appFileName($application),
                'caddyfile' => $this->applicationCaddyfile($application),
            ])
            ->filter(fn (array $file) => $file['caddyfile'] !== '')
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function applicationCaddyfile(Application $application): string
    {
        if (! $application->ingress_enabled || ! $application->internal_port) {
            return '';
        }

        return $application->domains
            ->map(fn (ApplicationDomain $domain) => $this->applicationRoute($application, $domain))
            ->filter()
            ->sort()
            ->implode("\n\n");
    }

    private function applicationRoute(Application $application, ApplicationDomain $domain): ?string
    {
        if ($domain->domain === '') {
            return null;
        }

        $namespace = $application->mesh_namespace ?: 'default';
        $upstream = "{$application->container_name}.{$namespace}.coolify.internal:{$application->internal_port}";

        return implode("\n", [
            "http://{$domain->domain} {",
            "    reverse_proxy {$upstream}",
            '}',
        ]);
    }

    private function appFileName(Application $application): string
    {
        return 'app_'.$application->getKey();
    }
}
