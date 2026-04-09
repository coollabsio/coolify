<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Traits\ResolvesEdgeProxyServer;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

class EdgeProxyRemotePortForwardService
{
    use ResolvesEdgeProxyServer;

    private const array RESERVED_EDGE_PORTS = [80, 443];

    public function syncService(Service $service): array
    {
        $deploymentServer = $this->resolveServiceDeploymentServer($service);
        if (! $deploymentServer instanceof Server) {
            return [];
        }

        return $this->syncServiceWithServers(
            $service,
            $this->resolveEdgeProxyServerByTeamId($this->extractServiceTeamId($service)),
            $deploymentServer
        );
    }

    public function syncServiceWithServers(Service $service, ?Server $edgeProxyServer, Server $deploymentServer): array
    {
        return $this->syncResourcePortProxy(
            resourceType: 'service',
            resourceUuid: $service->uuid,
            edgeProxyServer: $edgeProxyServer,
            deploymentServer: $deploymentServer,
            publishedPortMappings: $this->servicePublishedPortMappings($service)
        );
    }

    public function syncApplication(Application $application): array
    {
        $deploymentServer = $this->resolveApplicationDeploymentServer($application);
        if (! $deploymentServer instanceof Server) {
            return [];
        }

        return $this->syncApplicationWithServers(
            $application,
            $this->resolveEdgeProxyServerByTeamId($this->extractApplicationTeamId($application)),
            $deploymentServer
        );
    }

    public function syncApplicationOnDeploymentServer(Application $application, Server $deploymentServer): array
    {
        return $this->syncApplicationWithServers(
            $application,
            $this->resolveEdgeProxyServerByTeamId($this->extractApplicationTeamId($application)),
            $deploymentServer
        );
    }

    public function syncApplicationWithServers(Application $application, ?Server $edgeProxyServer, Server $deploymentServer): array
    {
        return $this->syncResourcePortProxy(
            resourceType: 'application',
            resourceUuid: $application->uuid,
            edgeProxyServer: $edgeProxyServer,
            deploymentServer: $deploymentServer,
            publishedPortMappings: $this->applicationPublishedPortMappings($application)
        );
    }

    public function deleteService(Service $service): array
    {
        return $this->resolveEdgeProxyServersByTeamId($this->extractServiceTeamId($service))
            ->flatMap(fn (Server $edgeProxyServer) => $this->deleteServiceWithServer($service, $edgeProxyServer))
            ->values()
            ->all();
    }

    public function deleteServiceWithServer(Service $service, Server $edgeProxyServer): array
    {
        return $this->deleteResourcePortProxyWithResult($edgeProxyServer, 'service', $service->uuid);
    }

    public function deleteApplication(Application $application): array
    {
        return $this->resolveEdgeProxyServersByTeamId($this->extractApplicationTeamId($application))
            ->flatMap(fn (Server $edgeProxyServer) => $this->deleteApplicationWithServer($application, $edgeProxyServer))
            ->values()
            ->all();
    }

    public function deleteApplicationWithServer(Application $application, Server $edgeProxyServer): array
    {
        return $this->deleteResourcePortProxyWithResult($edgeProxyServer, 'application', $application->uuid);
    }

    protected function runRemoteCommands(Server $server, array $commands, bool $throwError = true): ?string
    {
        return instant_remote_process($commands, $server, $throwError);
    }

    protected function logWarning(string $message): void
    {
        if (app()->bound('log')) {
            app('log')->warning($message);

            return;
        }

        error_log($message);
    }

    private function syncResourcePortProxy(
        string $resourceType,
        string $resourceUuid,
        ?Server $edgeProxyServer,
        Server $deploymentServer,
        Collection $publishedPortMappings
    ): array {
        if (! $edgeProxyServer instanceof Server) {
            return [];
        }

        if ($edgeProxyServer->id === $deploymentServer->id) {
            $this->deleteResourcePortProxy($edgeProxyServer, $resourceType, $resourceUuid);

            return [];
        }

        if ($publishedPortMappings->isEmpty()) {
            $this->deleteResourcePortProxy($edgeProxyServer, $resourceType, $resourceUuid);

            return [];
        }

        $warnings = [];
        $publishedPortMappings = $this->filterReservedEdgePorts($resourceType, $resourceUuid, $publishedPortMappings, $warnings);
        if ($publishedPortMappings->isEmpty()) {
            $this->deleteResourcePortProxy($edgeProxyServer, $resourceType, $resourceUuid);

            foreach ($warnings as $warning) {
                $this->logWarning($warning);
            }

            return $warnings;
        }

        $remoteHost = $this->resolveRemoteHost($deploymentServer);
        if (is_null($remoteHost)) {
            $warning = sprintf(
                'Edge port forwarding skipped for %s %s: remote host is missing. Configure a tunnel host (proxy.wireguard_ip/proxy.wg_ip/proxy.tunnel_ip/proxy.tunnel_host) or set the server IP/domain.',
                $resourceType,
                $resourceUuid
            );
            $this->logWarning($warning);
            $this->deleteResourcePortProxy($edgeProxyServer, $resourceType, $resourceUuid);
            $warnings[] = $warning;

            return $warnings;
        }

        try {
            $this->writeResourcePortProxy($edgeProxyServer, $resourceType, $resourceUuid, $remoteHost, $publishedPortMappings);
        } catch (\Throwable $exception) {
            $warning = sprintf(
                'Edge port forwarding warning for %s %s: failed to start edge port proxy (%s). Check whether one of the published ports is already in use on the edge server.',
                $resourceType,
                $resourceUuid,
                $exception->getMessage()
            );
            $this->logWarning($warning);
            $warnings[] = $warning;
        }

        foreach ($warnings as $warning) {
            $this->logWarning($warning);
        }

        return $warnings;
    }

    private function servicePublishedPortMappings(Service $service): Collection
    {
        return $this->composePublishedPortMappings(
            $this->parseServiceCompose($service),
            $this->serviceEnvironmentMap($service)
        );
    }

    private function applicationPublishedPortMappings(Application $application): Collection
    {
        $environmentMap = $this->applicationEnvironmentMap($application);

        if ($application->build_pack === 'dockercompose') {
            return $this->composePublishedPortMappings(
                $this->parseApplicationCompose($application),
                $environmentMap
            );
        }

        return $this->parsePublishedPortMappings($application->ports_mappings_array ?? [], $environmentMap);
    }

    private function composePublishedPortMappings(array $compose, array $environmentMap): Collection
    {
        $services = data_get($compose, 'services', []);
        if (! is_array($services) || empty($services)) {
            return collect();
        }

        return collect($services)
            ->filter(fn (mixed $serviceConfig) => is_array($serviceConfig))
            ->flatMap(function (array $serviceConfig) use ($environmentMap) {
                return $this->parsePublishedPortMappings(
                    (array) data_get($serviceConfig, 'ports', []),
                    $this->mergeComposeEnvironmentMap($serviceConfig, $environmentMap)
                );
            })
            ->unique(fn (array $mapping) => $mapping['protocol'].':'.$mapping['published'])
            ->sortBy(fn (array $mapping) => sprintf('%05d:%s', $mapping['published'], $mapping['protocol']))
            ->values();
    }

    private function parsePublishedPortMappings(array $ports, array $environmentMap): Collection
    {
        $mappings = collect();

        foreach ($ports as $portDefinition) {
            if (is_array($portDefinition)) {
                $protocol = strtolower((string) data_get($portDefinition, 'protocol', 'tcp'));
                if (! in_array($protocol, ['tcp', 'udp'], true)) {
                    continue;
                }

                $published = $this->resolvePortValue(data_get($portDefinition, 'published'), $environmentMap);
                if (! is_null($published)) {
                    $mappings->push([
                        'published' => $published,
                        'protocol' => $protocol,
                    ]);
                }

                continue;
            }

            if (is_string($portDefinition) || is_int($portDefinition)) {
                $mapping = $this->parsePublishedPortMappingFromString((string) $portDefinition, $environmentMap);
                if (! is_null($mapping) && ! is_null($mapping['published'])) {
                    $mappings->push($mapping);
                }
            }
        }

        return $mappings->values();
    }

    private function parsePublishedPortMappingFromString(string $portDefinition, array $environmentMap): ?array
    {
        $normalizedPortDefinition = trim($portDefinition);
        if ($normalizedPortDefinition === '') {
            return null;
        }

        $protocol = 'tcp';
        if (preg_match('/\/(tcp|udp)$/i', $normalizedPortDefinition, $protocolMatches)) {
            $protocol = strtolower($protocolMatches[1]);
            $normalizedPortDefinition = preg_replace('/\/(tcp|udp)$/i', '', $normalizedPortDefinition) ?? $normalizedPortDefinition;
        }

        if (! str_contains($normalizedPortDefinition, ':')) {
            return [
                'published' => null,
                'protocol' => $protocol,
            ];
        }

        $segments = $this->splitPortDefinitionSegments($normalizedPortDefinition);
        if (count($segments) < 2) {
            return null;
        }

        array_pop($segments);
        $publishedPort = $this->resolvePortValue(array_pop($segments), $environmentMap);

        return [
            'published' => $publishedPort,
            'protocol' => $protocol,
        ];
    }

    private function filterReservedEdgePorts(string $resourceType, string $resourceUuid, Collection $publishedPortMappings, array &$warnings): Collection
    {
        return $publishedPortMappings
            ->reject(function (array $mapping) use ($resourceType, $resourceUuid, &$warnings) {
                if (! in_array($mapping['published'], self::RESERVED_EDGE_PORTS, true)) {
                    return false;
                }

                $warnings[] = sprintf(
                    'Edge port forwarding skipped for %s %s on port %d/%s: this port is reserved on the edge server for HTTP/HTTPS proxying.',
                    $resourceType,
                    $resourceUuid,
                    $mapping['published'],
                    $mapping['protocol']
                );

                return true;
            })
            ->values();
    }

    private function writeResourcePortProxy(
        Server $edgeProxyServer,
        string $resourceType,
        string $resourceUuid,
        string $remoteHost,
        Collection $publishedPortMappings
    ): void {
        $containerName = $this->proxyContainerName($resourceType, $resourceUuid);
        $configurationDirectory = $this->configurationDirectory($resourceType, $resourceUuid);
        $escapedConfigurationDirectory = escapeshellarg($configurationDirectory);
        $escapedNginxPath = escapeshellarg($configurationDirectory.'/nginx.conf');
        $escapedComposePath = escapeshellarg($configurationDirectory.'/docker-compose.yaml');
        $escapedContainerName = escapeshellarg($containerName);

        $nginxConfig = base64_encode($this->generateNginxStreamConfig($remoteHost, $publishedPortMappings));
        $dockerCompose = base64_encode(Yaml::dump(
            $this->generateDockerCompose($containerName, $configurationDirectory, $publishedPortMappings),
            6,
            2
        ));

        $this->runRemoteCommands($edgeProxyServer, [
            "docker rm -f $escapedContainerName >/dev/null 2>&1 || true",
            "mkdir -p $escapedConfigurationDirectory",
            "echo '{$nginxConfig}' | base64 -d | tee $escapedNginxPath > /dev/null",
            "echo '{$dockerCompose}' | base64 -d | tee $escapedComposePath > /dev/null",
            "docker compose --project-directory $escapedConfigurationDirectory pull",
            "docker compose --project-directory $escapedConfigurationDirectory up -d",
        ]);
    }

    private function deleteResourcePortProxy(Server $edgeProxyServer, string $resourceType, string $resourceUuid, bool $throwError = true): void
    {
        $escapedContainerName = escapeshellarg($this->proxyContainerName($resourceType, $resourceUuid));

        $this->runRemoteCommands($edgeProxyServer, [
            "docker rm -f $escapedContainerName",
        ], $throwError);
    }

    private function deleteResourcePortProxyWithResult(Server $edgeProxyServer, string $resourceType, string $resourceUuid): array
    {
        try {
            $this->deleteResourcePortProxy($edgeProxyServer, $resourceType, $resourceUuid);

            return [];
        } catch (\Throwable $exception) {
            if ($this->isMissingContainerError($exception)) {
                return [];
            }

            return [sprintf(
                'Failed to delete edge port proxy for %s %s on edge server %s (%d): %s',
                $resourceType,
                $resourceUuid,
                $edgeProxyServer->name,
                $edgeProxyServer->id,
                $exception->getMessage()
            )];
        }
    }

    private function isMissingContainerError(\Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'no such container');
    }

    private function generateNginxStreamConfig(string $remoteHost, Collection $publishedPortMappings): string
    {
        $serverBlocks = $publishedPortMappings
            ->map(function (array $mapping) use ($remoteHost) {
                $listen = (string) $mapping['published'];
                if ($mapping['protocol'] === 'udp') {
                    $listen .= ' udp';
                }

                return <<<EOF
       server {
            listen {$listen};
            proxy_pass {$remoteHost}:{$mapping['published']};
       }
EOF;
            })
            ->implode("\n");

        return <<<EOF
user  nginx;
worker_processes  auto;

error_log  /var/log/nginx/error.log;

events {
    worker_connections  1024;
}
stream {
{$serverBlocks}
}
EOF;
    }

    private function generateDockerCompose(string $containerName, string $configurationDirectory, Collection $publishedPortMappings): array
    {
        $ports = $publishedPortMappings
            ->map(function (array $mapping) {
                $port = "{$mapping['published']}:{$mapping['published']}";

                return $mapping['protocol'] === 'udp' ? "{$port}/udp" : $port;
            })
            ->values()
            ->all();

        return [
            'services' => [
                $containerName => [
                    'image' => 'nginx:stable-alpine',
                    'container_name' => $containerName,
                    'restart' => RESTART_MODE,
                    'ports' => $ports,
                    'volumes' => [[
                        'type' => 'bind',
                        'source' => $configurationDirectory.'/nginx.conf',
                        'target' => '/etc/nginx/nginx.conf',
                    ]],
                    'healthcheck' => [
                        'test' => [
                            'CMD-SHELL',
                            'stat /etc/nginx/nginx.conf || exit 1',
                        ],
                        'interval' => '5s',
                        'timeout' => '5s',
                        'retries' => 3,
                        'start_period' => '1s',
                    ],
                ],
            ],
        ];
    }

    private function configurationDirectory(string $resourceType, string $resourceUuid): string
    {
        return match ($resourceType) {
            'application' => application_configuration_dir()."/{$resourceUuid}/edge-port-proxy",
            'service' => service_configuration_dir()."/{$resourceUuid}/edge-port-proxy",
            default => base_configuration_dir()."/{$resourceType}/{$resourceUuid}/edge-port-proxy",
        };
    }

    private function proxyContainerName(string $resourceType, string $resourceUuid): string
    {
        return "{$resourceType}-{$resourceUuid}-edge-port-proxy";
    }

    private function resolveServiceDeploymentServer(Service $service): ?Server
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

    private function resolveApplicationDeploymentServer(Application $application): ?Server
    {
        $server = data_get($application, 'server');
        if ($server instanceof Server) {
            return $server;
        }

        $server = data_get($application, 'destination.server');
        if ($server instanceof Server) {
            return $server;
        }

        if ($application->exists) {
            $application->loadMissing('destination.server');

            $server = data_get($application, 'destination.server');
            if ($server instanceof Server) {
                return $server;
            }
        }

        return null;
    }

    private function extractServiceTeamId(Service $service): ?int
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

    private function extractApplicationTeamId(Application $application): ?int
    {
        $teamId = data_get($application, 'environment.project.team_id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        if ($application->exists) {
            $application->loadMissing('environment.project');
            $teamId = data_get($application, 'environment.project.team_id');
            if (! is_null($teamId)) {
                return (int) $teamId;
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

    private function parseApplicationCompose(Application $application): array
    {
        if ($application->build_pack !== 'dockercompose') {
            return [];
        }

        $rawCompose = filled($application->docker_compose_raw)
            ? $application->docker_compose_raw
            : $application->docker_compose;

        if (blank($rawCompose)) {
            return [];
        }

        try {
            $parsedCompose = Yaml::parse($rawCompose);

            return is_array($parsedCompose) ? $parsedCompose : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function applicationEnvironmentMap(Application $application): array
    {
        if ($application->relationLoaded('environment_variables')) {
            return $application->environment_variables
                ->mapWithKeys(fn ($environmentVariable) => [$environmentVariable->key => (string) $environmentVariable->value])
                ->all();
        }

        if (! $application->exists) {
            return [];
        }

        return $application->environment_variables()
            ->get()
            ->mapWithKeys(fn ($environmentVariable) => [$environmentVariable->key => (string) $environmentVariable->value])
            ->all();
    }

    private function mergeComposeEnvironmentMap(array $serviceConfig, array $environmentMap): array
    {
        $resolvedEnvironmentMap = $environmentMap;

        foreach ($this->composeEnvironmentDefinitions($serviceConfig) as $environmentKey => $rawValue) {
            if (
                array_key_exists($environmentKey, $environmentMap) &&
                trim((string) $environmentMap[$environmentKey]) !== ''
            ) {
                continue;
            }

            $resolvedValue = $this->resolveEnvironmentValue($rawValue, $resolvedEnvironmentMap);
            if (! is_null($resolvedValue)) {
                $resolvedEnvironmentMap[$environmentKey] = $resolvedValue;
            }
        }

        return $resolvedEnvironmentMap;
    }

    private function composeEnvironmentDefinitions(array $serviceConfig): array
    {
        $environmentDefinitions = [];
        $environment = data_get($serviceConfig, 'environment', []);

        if (! is_array($environment)) {
            return $environmentDefinitions;
        }

        foreach ($environment as $key => $value) {
            if (is_int($key)) {
                $environmentPair = explode('=', (string) $value, 2);
                if (count($environmentPair) !== 2 || trim($environmentPair[0]) === '') {
                    continue;
                }

                $environmentDefinitions[trim($environmentPair[0])] = $environmentPair[1];

                continue;
            }

            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            if (! is_scalar($value) && ! is_null($value)) {
                continue;
            }

            $environmentDefinitions[trim($key)] = $value;
        }

        return $environmentDefinitions;
    }

    private function resolveEnvironmentValue(mixed $rawValue, array $environmentMap): ?string
    {
        if (is_null($rawValue)) {
            return null;
        }

        if (is_bool($rawValue)) {
            return $rawValue ? 'true' : 'false';
        }

        if (is_int($rawValue) || is_float($rawValue)) {
            return (string) $rawValue;
        }

        $normalizedValue = trim((string) $rawValue);
        if ($normalizedValue === '') {
            return '';
        }

        if (
            preg_match(
                '/^\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-?])([^}]*))?\}$/',
                $normalizedValue,
                $matches
            )
        ) {
            $environmentKey = $matches[1];
            $defaultValue = trim((string) ($matches[3] ?? ''));
            $resolvedEnvironmentValue = $environmentMap[$environmentKey] ?? null;

            if (! is_null($resolvedEnvironmentValue) && trim((string) $resolvedEnvironmentValue) !== '') {
                return trim((string) $resolvedEnvironmentValue);
            }

            return $defaultValue !== '' ? $defaultValue : null;
        }

        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $normalizedValue, $matches)) {
            $environmentKey = $matches[1];
            $resolvedEnvironmentValue = $environmentMap[$environmentKey] ?? null;

            if (! is_null($resolvedEnvironmentValue) && trim((string) $resolvedEnvironmentValue) !== '') {
                return trim((string) $resolvedEnvironmentValue);
            }

            return null;
        }

        if (array_key_exists($normalizedValue, $environmentMap)) {
            $resolvedEnvironmentValue = trim((string) $environmentMap[$normalizedValue]);

            return $resolvedEnvironmentValue !== '' ? $resolvedEnvironmentValue : null;
        }

        return $normalizedValue;
    }

    private function splitPortDefinitionSegments(string $portDefinition): array
    {
        $segments = [];
        $currentSegment = '';
        $braceDepth = 0;
        $length = strlen($portDefinition);

        for ($index = 0; $index < $length; $index++) {
            $character = $portDefinition[$index];

            if ($character === '{') {
                $braceDepth++;
            } elseif ($character === '}' && $braceDepth > 0) {
                $braceDepth--;
            }

            if ($character === ':' && $braceDepth === 0) {
                $segments[] = $currentSegment;
                $currentSegment = '';

                continue;
            }

            $currentSegment .= $character;
        }

        $segments[] = $currentSegment;

        return $segments;
    }

    private function resolvePortValue(mixed $rawPortValue, array $environmentMap): ?int
    {
        if (is_int($rawPortValue)) {
            return $rawPortValue;
        }

        $normalizedPortValue = trim((string) $rawPortValue);
        if ($normalizedPortValue === '') {
            return null;
        }

        if (preg_match('/^\d+\s*-\s*\d+$/', $normalizedPortValue) === 1) {
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

}
