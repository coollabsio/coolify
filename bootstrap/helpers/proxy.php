<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Server\StartSentinel;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

function traefikAccessLogCommands(bool $enabled): array
{
    if (! $enabled) {
        return [];
    }

    return [
        '--accesslog=true',
        '--accesslog.filepath=/traefik/access.log',
        '--accesslog.format=json',
        '--accesslog.fields.headers.names.Cf-Connecting-Ip=keep',
        '--accesslog.fields.headers.names.Cf-Ipcountry=keep',
        '--accesslog.fields.headers.names.Cf-Cache-Status=keep',
        '--accesslog.fields.headers.names.Cf-Verified-Bot=keep',
        '--accesslog.fields.headers.names.Cf-Ray=keep',
        // Kept so Sentinel can resolve the real client IP behind a non-Cloudflare
        // reverse proxy (leftmost X-Forwarded-For entry) and report User-Agents/referrers.
        '--accesslog.fields.headers.names.X-Forwarded-For=keep',
        '--accesslog.fields.headers.names.User-Agent=keep',
        '--accesslog.fields.headers.names.Referer=keep',
    ];
}

function applyTrafficAnalyticsToProxyConfiguration(Server $server, string $configuration): string
{
    $config = Yaml::parse($configuration);

    if (! is_array($config)) {
        throw new RuntimeException('Proxy configuration must be a YAML mapping.');
    }

    $config = applyTrafficAnalyticsToProxyConfigArray($server, $config);

    return Yaml::dump($config, 12, 2);
}

/**
 * Server proxy attribute that remembers the user's own `--accesslog*` Traefik flags while
 * traffic analytics replaces them with the managed set, so disabling can restore them exactly.
 */
const TRAEFIK_USER_ACCESSLOG_COMMANDS_KEY = 'traffic_analytics_user_accesslog_commands';

function isTraefikAccessLogCommand(mixed $command): bool
{
    if (! is_string($command)) {
        return false;
    }

    $flag = strtolower(explode('=', $command, 2)[0]);

    return $flag === '--accesslog' || str_starts_with($flag, '--accesslog.');
}

/**
 * Rotates the Traefik access log with BusyBox tools from the Alpine base image only (no package
 * install, no network). Uses copytruncate semantics: Traefik keeps its file handle and Sentinel's
 * tailer handles the truncation. Keeps at most 5 gzip-compressed rotations (access.log.1.gz..5.gz).
 * The environment overrides exist for tests; the sidecar does not set them.
 */
function traefikAccessLogRotationScript(): string
{
    return <<<'SH'
log="${TRAEFIK_ACCESS_LOG:-/traefik/access.log}"
max_bytes="${TRAEFIK_ACCESS_LOG_MAX_BYTES:-20971520}"
interval="${TRAEFIK_ACCESS_LOG_ROTATE_INTERVAL:-60}"
keep=5
while true; do
  size=$(stat -c %s "$log" 2>/dev/null || echo 0)
  if [ "$size" -gt "$max_bytes" ]; then
    rm -f "$log.$keep.gz"
    i=$((keep - 1))
    while [ "$i" -ge 1 ]; do
      if [ -f "$log.$i.gz" ]; then mv -f "$log.$i.gz" "$log.$((i + 1)).gz"; fi
      i=$((i - 1))
    done
    if cp "$log" "$log.1"; then
      : > "$log"
      gzip -f "$log.1"
    fi
  fi
  sleep "$interval"
done
SH;
}

/**
 * Replace the user's own access log flags with the managed set while analytics is enabled, and
 * restore them exactly when it is disabled. Flags that were never added by Coolify are kept.
 *
 * @param  array<int, mixed>  $commands
 * @return array<int, mixed>
 */
function applyTraefikAccessLogCommands(Server $server, array $commands, bool $enabled): array
{
    $managedCommands = traefikAccessLogCommands(true);
    $storedUserCommands = $server->proxy->get(TRAEFIK_USER_ACCESSLOG_COMMANDS_KEY);
    $hasStoredUserCommands = is_array($storedUserCommands);
    $userCommands = $hasStoredUserCommands ? array_values($storedUserCommands) : [];

    // Managed flags are Coolify's when their user flags were remembered on enable, or (analytics
    // enabled before flags were remembered) when the complete managed set is present. Otherwise a
    // matching flag such as `--accesslog=true` belongs to the user and is kept.
    $managedCommandsAddedByCoolify = $hasStoredUserCommands || array_diff($managedCommands, $commands) === [];
    if ($managedCommandsAddedByCoolify) {
        $commands = array_values(array_filter(
            $commands,
            fn (mixed $command): bool => ! in_array($command, $managedCommands, true)
        ));
    }

    if ($enabled) {
        foreach ($commands as $command) {
            if (isTraefikAccessLogCommand($command) && ! in_array($command, $userCommands, true)) {
                $userCommands[] = $command;
            }
        }

        $commands = [
            ...array_filter($commands, fn (mixed $command): bool => ! isTraefikAccessLogCommand($command)),
            ...$managedCommands,
        ];

        if ($storedUserCommands !== $userCommands) {
            $server->proxy->set(TRAEFIK_USER_ACCESSLOG_COMMANDS_KEY, $userCommands);
            $server->save();
        }
    } elseif ($hasStoredUserCommands) {
        foreach ($userCommands as $command) {
            if (! in_array($command, $commands, true)) {
                $commands[] = $command;
            }
        }

        $server->proxy->forget(TRAEFIK_USER_ACCESSLOG_COMMANDS_KEY);
        $server->save();
    }

    return array_values($commands);
}

function applyTrafficAnalyticsToProxyConfigArray(Server $server, array $config): array
{
    $enabled = $server->isTrafficAnalyticsEnabled();

    if ($server->proxyType() === ProxyTypes::TRAEFIK->value) {
        $commands = data_get($config, 'services.traefik.command', []);

        if (! is_array($commands)) {
            throw new RuntimeException('Traefik commands must be a YAML list.');
        }

        data_set($config, 'services.traefik.command', applyTraefikAccessLogCommands($server, $commands, $enabled));
        unset($config['services']['traefik-logrotate']);

        if ($enabled && ! $server->isSwarm() && ! isDev()) {
            $proxyPath = $server->proxyPath();
            $config['services']['traefik-logrotate'] = [
                'container_name' => 'coolify-proxy-logrotate',
                'image' => 'alpine:3.24',
                'restart' => RESTART_MODE,
                'network_mode' => 'none',
                'volumes' => [
                    "{$proxyPath}:/traefik",
                ],
                'labels' => [
                    'coolify.managed=true',
                ],
                // Docker Compose interpolates `$VAR`, so every `$` is escaped as `$$`.
                'entrypoint' => ['/bin/sh', '-c', str_replace('$', '$$', traefikAccessLogRotationScript())],
            ];
        }
    } elseif ($server->proxyType() === ProxyTypes::CADDY->value) {
        // Caddy writes /traffic/access.log and Sentinel reads <trafficLogDirectory>/access.log, so both use one path.
        $trafficVolume = StartSentinel::trafficLogDirectory($server).':/traffic';
        $volumes = data_get($config, 'services.caddy.volumes', []);

        if (! is_array($volumes)) {
            throw new RuntimeException('Caddy volumes must be a YAML list.');
        }

        // Coolify owns /traffic: replace an older mount with a different source path.
        $volumes = array_values(array_filter($volumes, fn (mixed $volume): bool => ! isCaddyTrafficVolume($volume)));
        if ($enabled) {
            $volumes[] = $trafficVolume;
        }

        data_set($config, 'services.caddy.volumes', $volumes);
    }

    return $config;
}

/**
 * True for a Caddy volume that mounts to the /traffic access-log directory (short or long syntax).
 */
function isCaddyTrafficVolume(mixed $volume): bool
{
    if (is_array($volume)) {
        return rtrim((string) data_get($volume, 'target'), '/') === '/traffic';
    }

    if (! is_string($volume)) {
        return false;
    }

    $parts = explode(':', $volume);

    return count($parts) >= 2 && rtrim($parts[1], '/') === '/traffic';
}

/**
 * Check if a network name is a Docker predefined system network.
 * These networks cannot be created, modified, or managed by docker network commands.
 *
 * @param  string  $network  Network name to check
 * @return bool True if it's a predefined network that should be skipped
 */
function isDockerPredefinedNetwork(string $network): bool
{
    // Only filter 'default' and 'host' to match existing codebase patterns
    // See: bootstrap/helpers/parsers.php:891, bootstrap/helpers/shared.php:689,748
    return in_array($network, ['default', 'host'], true);
}

function isUsableDockerNetworkName(mixed $network): bool
{
    return is_string($network)
        && $network !== ''
        && ! isDockerPredefinedNetwork($network)
        && ValidationPatterns::isValidDockerNetwork($network);
}

/**
 * Create a Docker network when it does not exist. The network name is always a single escaped argument.
 */
function dockerNetworkEnsureCommand(string $network, bool $overlay = false, bool $quietCreate = false): string
{
    $safe = escapeshellarg($network);
    $createFlags = $overlay
        ? '--driver overlay --attachable'
        : '--attachable';
    $quiet = $quietCreate ? ' >/dev/null' : '';

    return "docker network inspect {$safe} >/dev/null 2>&1 || docker network create {$createFlags} {$safe}{$quiet}";
}

function collectProxyDockerNetworksByServer(Server $server)
{
    if (! $server->isFunctional()) {
        return collect();
    }
    $proxyType = $server->proxyType();
    if (is_null($proxyType) || $proxyType === 'NONE') {
        return collect();
    }
    $networks = instant_remote_process(['docker inspect --format="{{json .NetworkSettings.Networks }}" coolify-proxy'], $server, false);

    return collect($networks)->map(function ($network) {
        return collect(json_decode($network))->keys();
    })->flatten()->unique();
}
function collectDockerNetworksByServer(Server $server)
{
    $allNetworks = collect([]);
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
    $allNetworks = $allNetworks->merge($networks);
    // Service networks
    foreach ($server->services()->get() as $service) {
        if ($service->isRunning()) {
            $networks->push($service->networks());
        }
        $allNetworks->push($service->networks());
    }
    // Docker compose based apps
    $docker_compose_apps = $server->dockerComposeBasedApplications();
    foreach ($docker_compose_apps as $app) {
        if ($app->isRunning()) {
            $networks->push($app->uuid);
        }
        $allNetworks->push($app->uuid);
    }
    // Docker compose based preview deployments
    $docker_compose_previews = $server->dockerComposeBasedPreviewDeployments();
    foreach ($docker_compose_previews as $preview) {
        if (! $preview->isRunning()) {
            continue;
        }
        $pullRequestId = $preview->pull_request_id;
        $applicationId = $preview->application_id;
        $application = Application::find($applicationId);
        if (! $application) {
            continue;
        }
        $network = "{$application->uuid}-{$pullRequestId}";
        $networks->push($network);
        $allNetworks->push($network);
    }
    $networks = collect($networks)->flatten()->unique()->filter(fn ($network) => isUsableDockerNetworkName($network));
    $allNetworks = $allNetworks->flatten()->unique()->filter(fn ($network) => isUsableDockerNetworkName($network));
    if ($server->isSwarm()) {
        if ($networks->count() === 0) {
            $networks = collect(['coolify-overlay']);
            $allNetworks = collect(['coolify-overlay']);
        }
    } else {
        if ($networks->count() === 0) {
            $networks = collect(['coolify']);
            $allNetworks = collect(['coolify']);
        }
    }

    return [
        'networks' => $networks,
        'allNetworks' => $allNetworks,
    ];
}
function connectProxyToNetworks(Server $server)
{
    if ($server->isSwarm()) {
        ['networks' => $networks] = collectDockerNetworksByServer($server);
        $commands = $networks->map(function ($network) {
            $safe = escapeshellarg($network);

            return [
                dockerNetworkEnsureCommand($network, overlay: true, quietCreate: true),
                "docker network connect {$safe} coolify-proxy >/dev/null 2>&1 || true",
                "echo 'Successfully connected coolify-proxy to {$safe} network.'",
            ];
        });

        return $commands->flatten();
    }

    return collect([
        'for network in $(docker inspect $(docker ps -a --filter label=coolify.managed=true --format "{{.ID}}") --format=\'{{range $network, $_ := .NetworkSettings.Networks}}{{println $network}}{{end}}\' 2>/dev/null | sort -u); do',
        '    if [ -z "$network" ] || [ "$network" = "bridge" ] || [ "$network" = "host" ] || [ "$network" = "none" ] || [ "$network" = "default" ]; then',
        '        continue',
        '    fi',
        '    if docker network inspect "$network" >/dev/null 2>&1; then',
        '        docker network connect "$network" coolify-proxy >/dev/null 2>&1 || true',
        '    fi',
        'done',
    ]);
}

/**
 * Ensures all required networks exist before docker compose up.
 * This must be called BEFORE docker compose up since the compose file declares networks as external.
 *
 * @param  Server  $server  The server to ensure networks on
 * @return Collection Commands to create networks if they don't exist
 */
function ensureProxyNetworksExist(Server $server)
{
    ['allNetworks' => $networks] = collectDockerNetworksByServer($server);

    $commands = $networks->map(function ($network) use ($server) {
        $safe = escapeshellarg($network);

        return [
            "echo 'Ensuring network {$safe} exists...'",
            dockerNetworkEnsureCommand($network, overlay: $server->isSwarm()),
        ];
    });

    return $commands->flatten();
}

function extractCustomProxyCommands(Server $server, string $existing_config): array
{
    $custom_commands = [];
    $proxy_type = $server->proxyType();

    if ($proxy_type !== ProxyTypes::TRAEFIK->value || empty($existing_config)) {
        return $custom_commands;
    }

    try {
        $yaml = Yaml::parse($existing_config);
        $existing_commands = data_get($yaml, 'services.traefik.command', []);

        if (empty($existing_commands)) {
            return $custom_commands;
        }

        // Define default commands that Coolify generates
        $default_command_prefixes = [
            '--ping=',
            '--api.',
            '--entrypoints.http.address=',
            '--entrypoints.https.address=',
            '--entrypoints.http.http.encodequerysemicolons=',
            '--entryPoints.http.http2.maxConcurrentStreams=',
            '--entrypoints.https.http.encodequerysemicolons=',
            '--entryPoints.https.http2.maxConcurrentStreams=',
            '--entrypoints.https.http3',
            '--providers.file.',
            '--certificatesresolvers.',
            '--providers.docker',
            '--providers.swarm',
            '--log.level=',
            '--accesslog.',
        ];

        // Extract commands that don't match default prefixes (these are custom)
        foreach ($existing_commands as $command) {
            $is_default = false;
            foreach ($default_command_prefixes as $prefix) {
                if (str_starts_with($command, $prefix)) {
                    $is_default = true;
                    break;
                }
            }
            if (! $is_default) {
                $custom_commands[] = $command;
            }
        }
    } catch (Exception $e) {
        // If we can't parse the config, return empty array
        // Silently fail to avoid breaking the proxy regeneration
    }

    return $custom_commands;
}

/**
 * Removes the dashboard router labels that older Coolify versions generated for Traefik.
 * These labels route requests with the proxy container name as Host header to the Traefik API and dashboard.
 * Comments and formatting are kept. A router or label set that the user changed is not touched.
 */
function removeLegacyTraefikDashboardLabels(string $configuration): string
{
    $legacyLabels = [
        'traefik.enable=true',
        'traefik.http.routers.traefik.entrypoints=http',
        'traefik.http.routers.traefik.service=api@internal',
        'traefik.http.services.traefik.loadbalancer.server.port=8080',
    ];

    try {
        $yaml = Yaml::parse($configuration);
    } catch (Throwable) {
        return $configuration;
    }

    $expected = $yaml;
    $changed = false;
    foreach (['services.traefik.labels', 'services.traefik.deploy.labels'] as $path) {
        $labels = data_get($yaml, $path);
        if (! is_array($labels) || ! array_is_list($labels) || array_filter($labels, 'is_string') !== $labels) {
            continue;
        }

        $traefikLabels = array_filter($labels, fn (string $label) => str_starts_with($label, 'traefik.'));
        if (! in_array('traefik.http.routers.traefik.service=api@internal', $traefikLabels, true) || array_diff($traefikLabels, $legacyLabels) !== []) {
            continue;
        }

        $newLabels = [];
        foreach ($labels as $label) {
            if ($label === 'traefik.enable=true') {
                $newLabels[] = 'traefik.enable=false';
            } elseif (! in_array($label, $legacyLabels, true)) {
                $newLabels[] = $label;
            }
        }
        data_set($expected, $path, $newLabels);
        $changed = true;
    }

    if (! $changed) {
        return $configuration;
    }

    $fixed = preg_replace('/^([ \t]*-[ \t]*([\'"]?))traefik\.enable=true(\2[ \t]*)(?=\R|\z)/m', '$1traefik.enable=false$3', $configuration);
    foreach (array_slice($legacyLabels, 1) as $label) {
        $fixed = preg_replace('/^[ \t]*-[ \t]*([\'"]?)'.preg_quote($label, '/').'\1[ \t]*(?:\R|\z)/m', '', $fixed);
    }

    // Keep the original when the line edit also changed other parts of the file.
    try {
        return Yaml::parse($fixed) === $expected ? $fixed : $configuration;
    } catch (Throwable) {
        return $configuration;
    }
}

/**
 * Saves the Traefik configuration without the legacy dashboard labels. The next proxy restart applies it.
 */
function removeLegacyTraefikDashboardExposure(Server $server): bool
{
    $configuration = $server->proxy->get('last_saved_proxy_configuration');
    if ($server->proxyType() !== ProxyTypes::TRAEFIK->value || ! is_string($configuration) || blank($configuration)) {
        return false;
    }

    $fixed = removeLegacyTraefikDashboardLabels($configuration);
    if ($fixed === $configuration) {
        return false;
    }

    // The running proxy still uses the old configuration, so the UI must ask for a restart.
    if (blank($server->proxy->get('last_applied_settings'))) {
        $server->proxy->last_applied_settings = md5(base64_encode($configuration));
    }
    $server->proxy->last_saved_proxy_configuration = $fixed;
    $server->proxy->last_saved_settings = md5(base64_encode($fixed));
    $server->save();

    Log::info('Removed legacy Traefik dashboard labels from the proxy configuration', ['server_id' => $server->id]);

    return true;
}

function generateDefaultProxyConfiguration(Server $server, array $custom_commands = [])
{
    Log::info('Generating default proxy configuration', [
        'server_id' => $server->id,
        'server_name' => $server->name,
        'custom_commands_count' => count($custom_commands),
        'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1]['class'] ?? 'unknown',
    ]);

    $proxy_path = $server->proxyPath();
    $proxy_type = $server->proxyType();

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
    $filtered_networks = collect([]);
    $networks->map(function ($network) use ($array_of_networks, $filtered_networks) {
        if (isDockerPredefinedNetwork($network)) {
            return; // Predefined networks cannot be used in network configuration
        }

        $array_of_networks[$network] = [
            'external' => true,
        ];
        $filtered_networks->push($network);
    });
    if ($proxy_type === ProxyTypes::TRAEFIK->value) {
        $labels = [
            'traefik.enable=false',
            'coolify.managed=true',
            'coolify.proxy=true',
        ];
        $config = [
            'name' => 'coolify-proxy',
            'networks' => $array_of_networks->toArray(),
            'services' => [
                'traefik' => [
                    'container_name' => 'coolify-proxy',
                    'image' => 'traefik:v3.7',
                    'restart' => RESTART_MODE,
                    'extra_hosts' => [
                        'host.docker.internal:host-gateway',
                    ],
                    'networks' => $filtered_networks->toArray(),
                    'ports' => [
                        '80:80',
                        '443:443',
                        '443:443/udp',
                        '8080:8080',
                    ],
                    'healthcheck' => [
                        'test' => 'wget -qO- http://localhost:80/ping || exit 1',
                        'interval' => '4s',
                        'timeout' => '2s',
                        'retries' => 5,
                    ],
                    'volumes' => [
                        '/var/run/docker.sock:/var/run/docker.sock:ro',

                    ],
                    'command' => [
                        '--ping=true',
                        '--ping.entrypoint=http',
                        '--api.dashboard=true',
                        '--entrypoints.http.address=:80',
                        '--entrypoints.https.address=:443',
                        '--entrypoints.http.http.encodequerysemicolons=true',
                        '--entryPoints.http.http2.maxConcurrentStreams=250',
                        '--entrypoints.https.http.encodequerysemicolons=true',
                        '--entryPoints.https.http2.maxConcurrentStreams=250',
                        '--entrypoints.https.http3',
                        '--providers.file.directory=/traefik/dynamic/',
                        '--providers.file.watch=true',
                        '--certificatesresolvers.letsencrypt.acme.httpchallenge=true',
                        '--certificatesresolvers.letsencrypt.acme.httpchallenge.entrypoint=http',
                        '--certificatesresolvers.letsencrypt.acme.storage=/traefik/acme.json',
                    ],
                    'labels' => $labels,
                ],
            ],
        ];
        if (isDev()) {
            $config['services']['traefik']['command'][] = '--api.insecure=true';
            $config['services']['traefik']['command'][] = '--log.level=debug';
            $config['services']['traefik']['command'][] = '--accesslog.bufferingsize=100';
            $config['services']['traefik']['volumes'][] = devCoolifyDataPath().'/proxy/:/traefik';
        } else {
            $config['services']['traefik']['command'][] = '--api.insecure=false';
            $config['services']['traefik']['volumes'][] = "{$proxy_path}:/traefik";
        }
        if ($server->isSwarm()) {
            data_forget($config, 'services.traefik.container_name');
            data_forget($config, 'services.traefik.restart');
            data_forget($config, 'services.traefik.labels');

            $config['services']['traefik']['command'][] = '--providers.swarm.endpoint=unix:///var/run/docker.sock';
            $config['services']['traefik']['command'][] = '--providers.swarm.exposedbydefault=false';
            $config['services']['traefik']['deploy'] = [
                'labels' => $labels,
                'placement' => [
                    'constraints' => [
                        'node.role==manager',
                    ],
                ],
            ];
        } else {
            $config['services']['traefik']['command'][] = '--providers.docker=true';
            $config['services']['traefik']['command'][] = '--providers.docker.exposedbydefault=false';
        }

        // Append custom commands (e.g., trustedIPs for Cloudflare)
        if (! empty($custom_commands)) {
            foreach ($custom_commands as $custom_command) {
                $config['services']['traefik']['command'][] = $custom_command;
            }
        }

    } elseif ($proxy_type === 'CADDY') {
        $config = [
            'networks' => $array_of_networks->toArray(),
            'services' => [
                'caddy' => [
                    'container_name' => 'coolify-proxy',
                    'image' => Server::RECOMMENDED_CADDY_PROXY_IMAGE,
                    'restart' => RESTART_MODE,
                    'extra_hosts' => [
                        'host.docker.internal:host-gateway',
                    ],
                    'environment' => [
                        'CADDY_DOCKER_POLLING_INTERVAL=5s',
                        'CADDY_DOCKER_CADDYFILE_PATH=/dynamic/Caddyfile',
                    ],
                    'networks' => $filtered_networks->toArray(),
                    'ports' => [
                        '80:80',
                        '443:443',
                        '443:443/udp',
                    ],
                    'labels' => [
                        'coolify.managed=true',
                        'coolify.proxy=true',
                    ],
                    'volumes' => [
                        '/var/run/docker.sock:/var/run/docker.sock:ro',
                        "{$proxy_path}/dynamic:/dynamic",
                        "{$proxy_path}/config:/config",
                        "{$proxy_path}/data:/data",
                    ],
                ],
            ],
        ];
    } else {
        return null;
    }

    $config = applyTrafficAnalyticsToProxyConfigArray($server, $config);
    $config = Yaml::dump($config, 12, 2);
    SaveProxyConfiguration::run($server, $config);

    return $config;
}

function getExactTraefikVersionFromContainer(Server $server): ?string
{
    try {
        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Checking for exact version");

        // Method A: Execute traefik version command (most reliable)
        $versionCommand = "docker exec coolify-proxy traefik version 2>/dev/null | grep -oP 'Version:\s+\K\d+\.\d+\.\d+'";
        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Running: {$versionCommand}");

        $output = instant_remote_process([$versionCommand], $server, false);

        if (! empty(trim($output))) {
            $version = trim($output);
            Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Detected exact version from command: {$version}");

            return $version;
        }

        // Method B: Try OCI label as fallback
        $labelCommand = "docker inspect coolify-proxy --format '{{index .Config.Labels \"org.opencontainers.image.version\"}}' 2>/dev/null";
        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Trying OCI label");

        $label = instant_remote_process([$labelCommand], $server, false);

        if (! empty(trim($label))) {
            // Extract version number from label (might have 'v' prefix)
            if (preg_match('/(\d+\.\d+\.\d+)/', trim($label), $matches)) {
                Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Detected from OCI label: {$matches[1]}");

                return $matches[1];
            }
        }

        Log::debug("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Could not detect exact version");

        return null;
    } catch (Exception $e) {
        Log::error("getExactTraefikVersionFromContainer: Server '{$server->name}' (ID: {$server->id}) - Error: ".$e->getMessage());

        return null;
    }
}

function getTraefikVersionFromDockerCompose(Server $server): ?string
{
    try {
        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Starting version detection");

        // Try to get exact version from running container (e.g., "3.6.0")
        $exactVersion = getExactTraefikVersionFromContainer($server);
        if ($exactVersion) {
            Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Using exact version: {$exactVersion}");

            return $exactVersion;
        }

        // Fallback: Check image tag (current method)
        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Falling back to image tag detection");

        $containerName = 'coolify-proxy';
        $inspectCommand = "docker inspect {$containerName} --format '{{.Config.Image}}' 2>/dev/null";

        $image = instant_remote_process([$inspectCommand], $server, false);

        if (empty(trim($image))) {
            Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Container '{$containerName}' not found or not running");

            return null;
        }

        $image = trim($image);
        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Running container image: {$image}");

        // Extract version from image string (e.g., "traefik:v3.6" or "traefik:3.6.0" or "traefik:latest")
        if (preg_match('/traefik:(v?\d+\.\d+(?:\.\d+)?|latest)/i', $image, $matches)) {
            Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Extracted version from image tag: {$matches[1]}");

            return $matches[1];
        }

        Log::debug("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Image format doesn't match expected pattern: {$image}");

        return null;
    } catch (Exception $e) {
        Log::error("getTraefikVersionFromDockerCompose: Server '{$server->name}' (ID: {$server->id}) - Error: ".$e->getMessage());

        return null;
    }
}
