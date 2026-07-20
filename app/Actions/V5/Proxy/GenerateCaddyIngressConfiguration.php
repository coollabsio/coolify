<?php

namespace App\Actions\V5\Proxy;

use App\Models\V5\Application;
use App\Models\V5\ApplicationDomain;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class GenerateCaddyIngressConfiguration
{
    use AsAction;

    /**
     * Strict RFC 1123 hostname: dot-separated alphanumeric labels with inner
     * hyphens, max 253 characters. Anchored with \A/\z (never $) so values
     * containing newlines, braces, quotes, whitespace, or control characters
     * can never inject extra directives into the generated Caddyfile.
     */
    private const HOSTNAME_PATTERN = '/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/i';

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
        if ($domain->domain === null || $domain->domain === '') {
            return null;
        }

        $namespace = $application->mesh_namespace ?: 'default';
        $internalPort = (int) $application->internal_port;

        if (! $this->isSafeHostname($domain->domain)) {
            Log::warning('Skipping a caddy ingress route with an unsafe domain.', [
                'application_id' => $application->getKey(),
                'domain' => $domain->domain,
            ]);

            return null;
        }

        if (! $this->isSafeHostname($application->container_name) || ! $this->isSafeHostname($namespace)) {
            Log::warning('Skipping a caddy ingress route with an unsafe container name or namespace.', [
                'application_id' => $application->getKey(),
                'container_name' => $application->container_name,
                'namespace' => $namespace,
            ]);

            return null;
        }

        if ($internalPort < 1 || $internalPort > 65535) {
            Log::warning('Skipping a caddy ingress route with an out-of-range internal port.', [
                'application_id' => $application->getKey(),
                'internal_port' => $application->internal_port,
            ]);

            return null;
        }

        $upstream = "{$application->container_name}.{$namespace}.coolify.internal:{$internalPort}";

        return implode("\n", [
            "http://{$domain->domain} {",
            "    reverse_proxy {$upstream}",
            '}',
        ]);
    }

    private function isSafeHostname(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HOSTNAME_PATTERN, $value) === 1;
    }

    private function appFileName(Application $application): string
    {
        return 'app_'.$application->getKey();
    }
}
