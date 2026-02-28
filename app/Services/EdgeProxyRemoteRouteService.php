<?php

namespace App\Services;

use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

class EdgeProxyRemoteRouteService
{
    private const string ROUTE_FILE_PREFIX = 'service-remote-';

    public function syncService(Service $service): array
    {
        $edgeProxyServer = $this->resolveEdgeProxyServer($service);
        $deploymentServer = $this->resolveDeploymentServer($service);

        if (! $deploymentServer instanceof Server) {
            return [];
        }

        if (! $edgeProxyServer instanceof Server) {
            if ($deploymentServer->id !== 0) {
                $warning = sprintf(
                    'Edge proxy route skipped for service %s: edge proxy server (id=0) was not found for the current team.',
                    $service->uuid
                );
                $this->logWarning($warning);

                return [$warning];
            }

            return [];
        }

        return $this->syncServiceWithServers($service, $edgeProxyServer, $deploymentServer);
    }

    public function syncServiceWithServers(Service $service, Server $edgeProxyServer, Server $deploymentServer): array
    {
        if ($edgeProxyServer->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return [];
        }

        if ($deploymentServer->id === $edgeProxyServer->id) {
            $this->deleteRouteFile($edgeProxyServer, $service->uuid);

            return [];
        }

        $applications = $this->getServiceApplicationsWithDomains($service);
        if ($applications->isEmpty()) {
            $this->deleteRouteFile($edgeProxyServer, $service->uuid);

            return [];
        }

        $tunnelHost = $this->resolveTunnelHost($deploymentServer);
        if (blank($tunnelHost)) {
            $warning = sprintf(
                'Edge proxy route skipped for service %s: remote host is missing. Configure a tunnel host (proxy.wireguard_ip/proxy.wg_ip/proxy.tunnel_ip/proxy.tunnel_host) or set the server IP/domain.',
                $service->uuid
            );

            $this->logWarning($warning);
            $this->deleteRouteFile($edgeProxyServer, $service->uuid);

            return [$warning];
        }

        $compose = $this->parseServiceCompose($service);
        $environmentMap = $this->serviceEnvironmentMap($service);

        $routes = [];
        $warnings = [];

        foreach ($applications as $application) {
            $domains = collect(explode(',', (string) $application->fqdn))
                ->map(fn (string $domain) => trim($domain))
                ->filter();

            foreach ($domains as $domain) {
                $unsupportedProtocol = $this->detectUnsupportedDomainProtocol($domain);
                if (! is_null($unsupportedProtocol)) {
                    $warnings[] = sprintf(
                        'Edge proxy route skipped for service %s (%s, domain %s): protocol "%s" is not supported for edge remote routing. Only http:// and https:// domains are currently supported.',
                        $service->uuid,
                        $application->name,
                        $domain,
                        $unsupportedProtocol
                    );

                    continue;
                }

                $url = $this->parseDomainUrl($domain);
                if (! $url instanceof Url) {
                    $warnings[] = sprintf(
                        'Edge proxy route skipped for service %s (%s, domain %s): domain format is invalid. Use a valid hostname/domain with optional scheme, port and path.',
                        $service->uuid,
                        $application->name,
                        $domain
                    );

                    continue;
                }

                $requestedInternalPort = $url->getPort() ?? $application->getRequiredPort();
                $publishedPort = $this->resolvePublishedPort($compose, $application->name, $requestedInternalPort, $environmentMap);

                if (is_null($publishedPort)) {
                    $warnings[] = sprintf(
                        'Edge proxy route skipped for service %s (%s, domain %s): published host port could not be resolved. Expose the container port in docker-compose "ports:" and/or include an explicit port in the domain.',
                        $service->uuid,
                        $application->name,
                        $domain
                    );

                    continue;
                }

                $routes[] = [
                    'host' => $url->getHost(),
                    'path' => $url->getPath(),
                    'upstream_url' => sprintf('http://%s:%d', $tunnelHost, $publishedPort),
                ];
            }
        }

        if (! empty($warnings)) {
            foreach ($warnings as $warning) {
                $this->logWarning($warning);
            }
        }

        if (empty($routes)) {
            $this->deleteRouteFile($edgeProxyServer, $service->uuid);

            return $warnings;
        }

        $config = $this->generateTraefikConfig($service->uuid, $routes);
        try {
            $this->writeRouteFile($edgeProxyServer, $service->uuid, $config);
        } catch (\Throwable $exception) {
            $warning = sprintf(
                'Edge proxy route partially applied for service %s: failed to write dynamic route configuration on edge proxy (%s).',
                $service->uuid,
                $exception->getMessage()
            );
            $this->logWarning($warning);
            $warnings[] = $warning;
        }

        return $warnings;
    }

    public function deleteService(Service $service): void
    {
        $edgeProxyServer = $this->resolveEdgeProxyServer($service);
        if (! $edgeProxyServer instanceof Server || $edgeProxyServer->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return;
        }

        $this->deleteServiceWithServer($service, $edgeProxyServer);
    }

    public function deleteServiceWithServer(Service $service, Server $edgeProxyServer): void
    {
        $this->deleteRouteFile($edgeProxyServer, $service->uuid);
    }

    public function generateTraefikConfig(string $serviceUuid, array $routes): array
    {
        $serviceKey = Str::slug($serviceUuid);
        if ($serviceKey === '') {
            $serviceKey = 'service';
        }

        $redirectMiddlewareName = "edge-{$serviceKey}-redirect-to-https";

        $config = [
            'http' => [
                'middlewares' => [
                    $redirectMiddlewareName => [
                        'redirectScheme' => [
                            'scheme' => 'https',
                        ],
                    ],
                ],
                'routers' => [],
                'services' => [],
            ],
        ];

        foreach ($routes as $index => $route) {
            $suffix = $index + 1;

            $httpRouterName = "edge-{$serviceKey}-http-{$suffix}";
            $httpsRouterName = "edge-{$serviceKey}-https-{$suffix}";
            $serviceName = "edge-{$serviceKey}-svc-{$suffix}";
            $rule = $this->buildTraefikRule($route['host'], $route['path']);

            $config['http']['routers'][$httpRouterName] = [
                'rule' => $rule,
                'entryPoints' => ['http'],
                'middlewares' => [$redirectMiddlewareName],
                'service' => $serviceName,
            ];

            $config['http']['routers'][$httpsRouterName] = [
                'rule' => $rule,
                'entryPoints' => ['https'],
                'service' => $serviceName,
                'tls' => [
                    'certResolver' => 'letsencrypt',
                ],
            ];

            $config['http']['services'][$serviceName] = [
                'loadBalancer' => [
                    'servers' => [
                        ['url' => $route['upstream_url']],
                    ],
                ],
            ];
        }

        return $config;
    }

    public function routeFilePath(Server $edgeProxyServer, string $serviceUuid): string
    {
        return sprintf(
            '%s/%s%s.yaml',
            $this->routeDirectoryPath($edgeProxyServer),
            self::ROUTE_FILE_PREFIX,
            $serviceUuid
        );
    }

    protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $server, $throwError);
    }

    private function routeDirectoryPath(Server $edgeProxyServer): string
    {
        return rtrim($edgeProxyServer->proxyPath(), '/').'/dynamic';
    }

    private function writeRouteFile(Server $edgeProxyServer, string $serviceUuid, array $config): void
    {
        $yaml = Yaml::dump($config, 12, 2);
        $banner = "# This file is generated by Coolify, do not edit it manually.\n\n";
        $payload = base64_encode($banner.$yaml);

        $routeFilePath = $this->routeFilePath($edgeProxyServer, $serviceUuid);
        $temporaryRouteFilePath = $routeFilePath.'.tmp';

        $escapedDirectory = escapeshellarg($this->routeDirectoryPath($edgeProxyServer));
        $escapedFilePath = escapeshellarg($routeFilePath);
        $escapedTemporaryFilePath = escapeshellarg($temporaryRouteFilePath);

        $this->runRemoteCommands($edgeProxyServer, [
            "mkdir -p $escapedDirectory",
            "echo '$payload' | base64 -d | tee $escapedTemporaryFilePath > /dev/null",
            "mv $escapedTemporaryFilePath $escapedFilePath",
        ]);
    }

    private function deleteRouteFile(Server $edgeProxyServer, string $serviceUuid): void
    {
        $escapedFilePath = escapeshellarg($this->routeFilePath($edgeProxyServer, $serviceUuid));
        $escapedTemporaryFilePath = escapeshellarg($this->routeFilePath($edgeProxyServer, $serviceUuid).'.tmp');

        $this->runRemoteCommands($edgeProxyServer, [
            "rm -f $escapedFilePath $escapedTemporaryFilePath",
        ], false);
    }

    private function buildTraefikRule(string $host, ?string $path): string
    {
        $rule = sprintf('Host(`%s`)', $host);

        if (! is_null($path) && $path !== '' && $path !== '/') {
            $rule .= sprintf(' && PathPrefix(`%s`)', $path);
        }

        return $rule;
    }

    private function resolveEdgeProxyServer(Service $service): ?Server
    {
        $teamId = $this->extractTeamId($service);
        if (is_null($teamId)) {
            return null;
        }

        return Server::query()
            ->where('team_id', $teamId)
            ->where('id', 0)
            ->first();
    }

    private function resolveDeploymentServer(Service $service): ?Server
    {
        $server = data_get($service, 'server');
        if ($server instanceof Server) {
            return $server;
        }

        $server = data_get($service, 'destination.server');
        if ($server instanceof Server) {
            return $server;
        }

        if ($service->exists && ! is_null($service->server_id)) {
            return Server::query()->find($service->server_id);
        }

        return null;
    }

    private function extractTeamId(Service $service): ?int
    {
        $teamId = data_get($service, 'environment.project.team_id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        if ($service->exists) {
            $service->loadMissing('environment.project');
            $teamId = data_get($service, 'environment.project.team_id');
            if (! is_null($teamId)) {
                return (int) $teamId;
            }
        }

        return null;
    }

    private function getServiceApplicationsWithDomains(Service $service): Collection
    {
        $applications = collect([]);

        if ($service->relationLoaded('applications')) {
            $applications = $service->applications;
        } elseif ($service->exists) {
            $applications = $service->applications()->get();
        }

        return $applications
            ->filter(fn (ServiceApplication $application) => filled($application->fqdn))
            ->values();
    }

    private function resolveTunnelHost(Server $deploymentServer): ?string
    {
        $candidates = [
            data_get($deploymentServer, 'proxy.wireguard_ip'),
            data_get($deploymentServer, 'proxy.wg_ip'),
            data_get($deploymentServer, 'proxy.tunnel_ip'),
            data_get($deploymentServer, 'proxy.tunnel_host'),
            data_get($deploymentServer, 'proxy.tunnel_domain'),
            data_get($deploymentServer, 'ip'),
        ];

        foreach ($candidates as $candidate) {
            $normalizedHost = $this->normalizeRemoteHost((string) $candidate);
            if (! is_null($normalizedHost)) {
                return $normalizedHost;
            }
        }

        return null;
    }

    private function parseServiceCompose(Service $service): array
    {
        if (blank($service->docker_compose_raw)) {
            return [];
        }

        try {
            $parsedCompose = Yaml::parse($service->docker_compose_raw);

            return is_array($parsedCompose) ? $parsedCompose : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function serviceEnvironmentMap(Service $service): array
    {
        if ($service->relationLoaded('environment_variables')) {
            return $service->environment_variables
                ->mapWithKeys(fn ($environmentVariable) => [$environmentVariable->key => (string) $environmentVariable->value])
                ->all();
        }

        if (! $service->exists) {
            return [];
        }

        return $service->environment_variables()
            ->get()
            ->mapWithKeys(fn ($environmentVariable) => [$environmentVariable->key => (string) $environmentVariable->value])
            ->all();
    }

    private function detectUnsupportedDomainProtocol(string $domain): ?string
    {
        $trimmedDomain = trim($domain);
        if ($trimmedDomain === '' || ! preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $trimmedDomain)) {
            return null;
        }

        $protocol = strtolower((string) parse_url($trimmedDomain, PHP_URL_SCHEME));
        if ($protocol === '' || in_array($protocol, ['http', 'https'], true)) {
            return null;
        }

        return $protocol;
    }

    private function parseDomainUrl(string $domain): ?Url
    {
        $normalizedDomain = trim($domain);
        if ($normalizedDomain === '') {
            return null;
        }

        if (! Str::startsWith($normalizedDomain, ['http://', 'https://'])) {
            $normalizedDomain = 'https://'.$normalizedDomain;
        }

        try {
            $url = Url::fromString($normalizedDomain, ['http', 'https']);
            if ($url->getHost() === '') {
                return null;
            }

            return $url;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePublishedPort(array $compose, string $serviceName, ?int $requestedInternalPort, array $environmentMap): ?int
    {
        $ports = $this->resolveComposeServicePorts($compose, $serviceName);
        if (! is_array($ports)) {
            return null;
        }

        $portMappings = $this->parsePortMappings($ports, $environmentMap)
            ->filter(fn (array $mapping) => ! is_null($mapping['published']))
            ->values();

        if ($portMappings->isEmpty()) {
            return null;
        }

        if (! is_null($requestedInternalPort)) {
            $matchingTarget = $portMappings->first(fn (array $mapping) => $mapping['target'] === $requestedInternalPort);
            if ($matchingTarget) {
                return $matchingTarget['published'];
            }

            $matchingPublished = $portMappings->first(fn (array $mapping) => $mapping['published'] === $requestedInternalPort);
            if ($matchingPublished) {
                return $matchingPublished['published'];
            }
        }

        if ($portMappings->count() === 1) {
            return $portMappings->first()['published'];
        }

        return null;
    }

    private function resolveComposeServicePorts(array $compose, string $serviceName): ?array
    {
        $services = data_get($compose, 'services', []);
        if (! is_array($services) || empty($services)) {
            return null;
        }

        if (array_key_exists($serviceName, $services)) {
            $ports = data_get($services[$serviceName], 'ports');

            return is_array($ports) ? $ports : null;
        }

        // Defensive fallback for templates where application name does not match compose key.
        $servicesWithPorts = collect($services)
            ->filter(fn (mixed $serviceConfig) => is_array($serviceConfig) && is_array(data_get($serviceConfig, 'ports')) && ! empty(data_get($serviceConfig, 'ports')))
            ->values();

        if ($servicesWithPorts->count() === 1) {
            return data_get($servicesWithPorts->first(), 'ports');
        }

        return null;
    }

    private function parsePortMappings(array $ports, array $environmentMap): Collection
    {
        $mappings = collect();

        foreach ($ports as $portDefinition) {
            if (is_array($portDefinition)) {
                $protocol = strtolower((string) data_get($portDefinition, 'protocol', 'tcp'));
                if ($protocol === 'udp') {
                    continue;
                }

                $target = $this->resolvePortValue(data_get($portDefinition, 'target'), $environmentMap);
                $published = $this->resolvePortValue(data_get($portDefinition, 'published'), $environmentMap);

                if (! is_null($target) || ! is_null($published)) {
                    $mappings->push([
                        'target' => $target,
                        'published' => $published,
                    ]);
                }

                continue;
            }

            if (is_string($portDefinition) || is_int($portDefinition)) {
                $mapping = $this->parsePortMappingFromString((string) $portDefinition, $environmentMap);
                if (! is_null($mapping)) {
                    $mappings->push($mapping);
                }
            }
        }

        return $mappings;
    }

    private function parsePortMappingFromString(string $portDefinition, array $environmentMap): ?array
    {
        $normalizedPortDefinition = trim($portDefinition);
        if ($normalizedPortDefinition === '') {
            return null;
        }

        if (preg_match('/\/(tcp|udp)$/i', $normalizedPortDefinition, $protocolMatches)) {
            if (strtolower($protocolMatches[1]) === 'udp') {
                return null;
            }

            $normalizedPortDefinition = preg_replace('/\/(tcp|udp)$/i', '', $normalizedPortDefinition) ?? $normalizedPortDefinition;
        }

        if (str_contains($normalizedPortDefinition, ':')) {
            $segments = explode(':', $normalizedPortDefinition);
            if (count($segments) < 2) {
                return null;
            }

            $containerPort = $this->resolvePortValue(array_pop($segments), $environmentMap);
            $hostPort = $this->resolvePortValue(array_pop($segments), $environmentMap);

            return [
                'target' => $containerPort,
                'published' => $hostPort,
            ];
        }

        return [
            'target' => $this->resolvePortValue($normalizedPortDefinition, $environmentMap),
            'published' => null,
        ];
    }

    private function resolvePortValue(mixed $rawPortValue, array $environmentMap): ?int
    {
        if (is_int($rawPortValue)) {
            return $rawPortValue;
        }

        $normalizedPortValue = trim((string) $rawPortValue);
        if ($normalizedPortValue === '' || str_contains($normalizedPortValue, '-')) {
            return null;
        }

        if (preg_match('/^\d+$/', $normalizedPortValue)) {
            return (int) $normalizedPortValue;
        }

        if (
            preg_match(
                '/^\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?])([^}]*))?\}$/',
                $normalizedPortValue,
                $matches
            )
        ) {
            $environmentKey = $matches[1];
            $defaultPort = trim((string) ($matches[3] ?? ''));

            $resolvedEnvironmentPort = $environmentMap[$environmentKey] ?? null;
            if (! is_null($resolvedEnvironmentPort) && is_numeric(trim((string) $resolvedEnvironmentPort))) {
                return (int) trim((string) $resolvedEnvironmentPort);
            }

            if ($defaultPort !== '' && is_numeric($defaultPort)) {
                return (int) $defaultPort;
            }

            return null;
        }

        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $normalizedPortValue, $matches)) {
            $environmentKey = $matches[1];
            $resolvedEnvironmentPort = $environmentMap[$environmentKey] ?? null;
            if (! is_null($resolvedEnvironmentPort) && is_numeric(trim((string) $resolvedEnvironmentPort))) {
                return (int) trim((string) $resolvedEnvironmentPort);
            }

            return null;
        }

        if (array_key_exists($normalizedPortValue, $environmentMap) && is_numeric(trim((string) $environmentMap[$normalizedPortValue]))) {
            return (int) trim((string) $environmentMap[$normalizedPortValue]);
        }

        return null;
    }

    private function logWarning(string $message): void
    {
        $container = Container::getInstance();
        if ($container instanceof Container && $container->bound('log')) {
            $container->make('log')->warning($message);

            return;
        }

        error_log($message);
    }

    private function normalizeRemoteHost(string $rawHost): ?string
    {
        $host = trim($rawHost);
        if ($host === '') {
            return null;
        }

        // Allow values like https://10.8.0.15:8080/path and extract only host.
        if (Str::startsWith($host, ['http://', 'https://'])) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        } elseif (str_contains($host, '/')) {
            $parsedHost = parse_url('http://'.$host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        }

        $host = trim($host, '[]');
        if ($host === '') {
            return null;
        }

        // Drop accidental host:port values so published compose port remains authoritative.
        if (str_contains($host, ':') && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parsedHost = parse_url('http://'.$host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : '';
        }

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.$host.']';
        }

        return $host;
    }
}
