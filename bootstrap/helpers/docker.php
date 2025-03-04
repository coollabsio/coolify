<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServiceApplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

function getCurrentApplicationContainerStatus(Server $server, int $id, ?int $pullRequestId = null, ?bool $includePullrequests = false): Collection
{
    $containers = collect([]);
    if (! $server->isSwarm()) {
        $containers = instant_remote_process(["docker ps -a --filter='label=coolify.applicationId={$id}' --format '{{json .}}' "], $server);
        $containers = format_docker_command_output_to_json($containers);
        $containers = $containers->map(function ($container) use ($pullRequestId, $includePullrequests) {
            $labels = data_get($container, 'Labels');
            if (! str($labels)->contains('coolify.pullRequestId=')) {
                data_set($container, 'Labels', $labels.",coolify.pullRequestId={$pullRequestId}");

                return $container;
            }
            if ($includePullrequests) {
                return $container;
            }
            if (str($labels)->contains("coolify.pullRequestId=$pullRequestId")) {
                return $container;
            }

            return null;
        });

        return $containers->filter();
    }

    return $containers;
}

function getCurrentServiceContainerStatus(Server $server, int $id): Collection
{
    $containers = collect([]);
    if (! $server->isSwarm()) {
        $containers = instant_remote_process(["docker ps -a --filter='label=coolify.serviceId={$id}' --format '{{json .}}' "], $server);
        $containers = format_docker_command_output_to_json($containers);

        return $containers->filter();
    }

    return $containers;
}

function format_docker_command_output_to_json($rawOutput): Collection
{
    $outputLines = explode(PHP_EOL, $rawOutput);
    if (count($outputLines) === 1) {
        $outputLines = collect($outputLines[0]);
    } else {
        $outputLines = collect($outputLines);
    }

    try {
        return $outputLines
            ->reject(fn ($line) => empty($line))
            ->map(fn ($outputLine) => json_decode($outputLine, true, flags: JSON_THROW_ON_ERROR));
    } catch (\Throwable) {
        return collect([]);
    }
}

function format_docker_labels_to_json(string|array $rawOutput): Collection
{
    if (is_array($rawOutput)) {
        return collect($rawOutput);
    }
    $outputLines = explode(PHP_EOL, $rawOutput);

    return collect($outputLines)
        ->reject(fn ($line) => empty($line))
        ->map(function ($outputLine) {
            $outputArray = explode(',', $outputLine);

            return collect($outputArray)
                ->map(function ($outputLine) {
                    return explode('=', $outputLine);
                })
                ->mapWithKeys(function ($outputLine) {
                    return [$outputLine[0] => $outputLine[1]];
                });
        })[0];
}

function format_docker_envs_to_json($rawOutput)
{
    try {
        $outputLines = json_decode($rawOutput, true, flags: JSON_THROW_ON_ERROR);

        return collect(data_get($outputLines[0], 'Config.Env', []))->mapWithKeys(function ($env) {
            $env = explode('=', $env);

            return [$env[0] => $env[1]];
        });
    } catch (\Throwable) {
        return collect([]);
    }
}
function checkMinimumDockerEngineVersion($dockerVersion)
{
    $majorDockerVersion = str($dockerVersion)->before('.')->value();
    $requiredDockerVersion = str(config('constants.docker.minimum_required_version'))->before('.')->value();
    if ($majorDockerVersion < $requiredDockerVersion) {
        $dockerVersion = null;
    }

    return $dockerVersion;
}
function executeInDocker(string $containerId, string $command)
{
    return "docker exec {$containerId} bash -c '{$command}'";
    // return "docker exec {$this->deployment_uuid} bash -c '{$command} |& tee -a /proc/1/fd/1; [ \$PIPESTATUS -eq 0 ] || exit \$PIPESTATUS'";
}

function getContainerStatus(Server $server, string $container_id, bool $all_data = false, bool $throwError = false)
{
    if ($server->isSwarm()) {
        $container = instant_remote_process(["docker service ls --filter 'name={$container_id}' --format '{{json .}}' "], $server, $throwError);
    } else {
        $container = instant_remote_process(["docker inspect --format '{{json .}}' {$container_id}"], $server, $throwError);
    }
    if (! $container) {
        return 'exited';
    }
    $container = format_docker_command_output_to_json($container);
    if ($container->isEmpty()) {
        return 'exited';
    }
    if ($all_data) {
        return $container[0];
    }
    if ($server->isSwarm()) {
        $replicas = data_get($container[0], 'Replicas');
        $replicas = explode('/', $replicas);
        $active = (int) $replicas[0];
        $total = (int) $replicas[1];
        if ($active === $total) {
            return 'running';
        } else {
            return 'starting';
        }
    } else {
        return data_get($container[0], 'State.Status', 'exited');
    }
}

function generateApplicationContainerName(Application $application, $pull_request_id = 0)
{
    // TODO: refactor generateApplicationContainerName, we do not need $application and $pull_request_id

    $consistent_container_name = $application->settings->is_consistent_container_name_enabled;
    $now = now()->format('Hisu');
    if ($pull_request_id !== 0 && $pull_request_id !== null) {
        return $application->uuid.'-pr-'.$pull_request_id;
    } else {
        if ($consistent_container_name) {
            return $application->uuid;
        }

        return $application->uuid.'-'.$now;
    }
}
function get_port_from_dockerfile($dockerfile): ?int
{
    $dockerfile_array = explode("\n", $dockerfile);
    $found_exposed_port = null;
    foreach ($dockerfile_array as $line) {
        $line_str = str($line)->trim();
        if ($line_str->startsWith('EXPOSE')) {
            $found_exposed_port = $line_str->replace('EXPOSE', '')->trim();
            break;
        }
    }
    if ($found_exposed_port) {
        return (int) $found_exposed_port->value();
    }

    return null;
}

function defaultDatabaseLabels($database)
{
    $labels = collect([]);
    $labels->push('coolify.managed=true');
    $labels->push('coolify.type=database');
    $labels->push('coolify.databaseId='.$database->id);
    $labels->push('coolify.resourceName='.Str::slug($database->name));
    $labels->push('coolify.serviceName='.Str::slug($database->name));
    $labels->push('coolify.projectName='.Str::slug($database->project()->name));
    $labels->push('coolify.environmentName='.Str::slug($database->environment->name));
    $labels->push('coolify.database.subType='.$database->type());

    return $labels;
}

function defaultLabels($id, $name, string $projectName, string $resourceName, string $environment, $pull_request_id = 0, string $type = 'application', $subType = null, $subId = null, $subName = null)
{
    $labels = collect([]);
    $labels->push('coolify.managed=true');
    $labels->push('coolify.version='.config('constants.coolify.version'));
    $labels->push('coolify.'.$type.'Id='.$id);
    $labels->push("coolify.type=$type");
    $labels->push('coolify.name='.$name);
    $labels->push('coolify.resourceName='.Str::slug($resourceName));
    $labels->push('coolify.projectName='.Str::slug($projectName));
    $labels->push('coolify.serviceName='.Str::slug($subName ?? $resourceName));
    $labels->push('coolify.environmentName='.Str::slug($environment));

    $labels->push('coolify.pullRequestId='.$pull_request_id);
    if ($type === 'service') {
        $subId && $labels->push('coolify.service.subId='.$subId);
        $subType && $labels->push('coolify.service.subType='.$subType);
        $subName && $labels->push('coolify.service.subName='.Str::slug($subName));
    }

    return $labels;
}

function generateServiceSpecificFqdns(ServiceApplication|Application $resource)
{
    if ($resource->getMorphClass() === \App\Models\ServiceApplication::class) {
        $uuid = data_get($resource, 'uuid');
        $server = data_get($resource, 'service.server');
        $environment_variables = data_get($resource, 'service.environment_variables');
        $type = $resource->serviceType();
    } elseif ($resource->getMorphClass() === \App\Models\Application::class) {
        $uuid = data_get($resource, 'uuid');
        $server = data_get($resource, 'destination.server');
        $environment_variables = data_get($resource, 'environment_variables');
        $type = $resource->serviceType();
    }
    if (is_null($server) || is_null($type)) {
        return collect([]);
    }
    $variables = collect($environment_variables);
    $payload = collect([]);
    switch ($type) {
        case $type?->contains('minio'):
            $MINIO_BROWSER_REDIRECT_URL = $variables->where('key', 'MINIO_BROWSER_REDIRECT_URL')->first();
            $MINIO_SERVER_URL = $variables->where('key', 'MINIO_SERVER_URL')->first();

            if (is_null($MINIO_BROWSER_REDIRECT_URL) || is_null($MINIO_SERVER_URL)) {
                return collect([]);
            }

            if (str($MINIO_BROWSER_REDIRECT_URL->value ?? '')->isEmpty()) {
                $MINIO_BROWSER_REDIRECT_URL->update([
                    'value' => generateFqdn($server, 'console-'.$uuid, true),
                ]);
            }
            if (str($MINIO_SERVER_URL->value ?? '')->isEmpty()) {
                $MINIO_SERVER_URL->update([
                    'value' => generateFqdn($server, 'minio-'.$uuid, true),
                ]);
            }
            $payload = collect([
                $MINIO_BROWSER_REDIRECT_URL->value.':9001',
                $MINIO_SERVER_URL->value.':9000',
            ]);
            break;
        case $type?->contains('logto'):
            $LOGTO_ENDPOINT = $variables->where('key', 'LOGTO_ENDPOINT')->first();
            $LOGTO_ADMIN_ENDPOINT = $variables->where('key', 'LOGTO_ADMIN_ENDPOINT')->first();

            if (is_null($LOGTO_ENDPOINT) || is_null($LOGTO_ADMIN_ENDPOINT)) {
                return collect([]);
            }

            if (str($LOGTO_ENDPOINT->value ?? '')->isEmpty()) {
                $LOGTO_ENDPOINT->update([
                    'value' => generateFqdn($server, 'logto-'.$uuid),
                ]);
            }
            if (str($LOGTO_ADMIN_ENDPOINT->value ?? '')->isEmpty()) {
                $LOGTO_ADMIN_ENDPOINT->update([
                    'value' => generateFqdn($server, 'logto-admin-'.$uuid),
                ]);
            }
            $payload = collect([
                $LOGTO_ENDPOINT->value.':3001',
                $LOGTO_ADMIN_ENDPOINT->value.':3002',
            ]);
            break;
    }

    return $payload;
}
function fqdnLabelsForCaddy(string $network, string $uuid, Collection $domains, bool $is_force_https_enabled = false, $onlyPort = null, ?Collection $serviceLabels = null, ?bool $is_gzip_enabled = true, ?bool $is_stripprefix_enabled = true, ?string $service_name = null, ?string $image = null, string $redirect_direction = 'both', ?string $predefinedPort = null)
{
    $labels = collect([]);
    if ($serviceLabels) {
        $labels->push("caddy_ingress_network={$uuid}");
    } else {
        $labels->push("caddy_ingress_network={$network}");
    }
    foreach ($domains as $loop => $domain) {
        $url = Url::fromString($domain);
        $host = $url->getHost();
        $path = $url->getPath();
        $host_without_www = str($host)->replace('www.', '');
        $schema = $url->getScheme();
        $port = $url->getPort();
        $handle = 'handle_path';
        if (! $is_stripprefix_enabled) {
            $handle = 'handle';
        }
        if (is_null($port) && ! is_null($onlyPort)) {
            $port = $onlyPort;
        }
        if (is_null($port) && $predefinedPort) {
            $port = $predefinedPort;
        }
        $labels->push("caddy_{$loop}={$schema}://{$host}");
        $labels->push("caddy_{$loop}.header=-Server");
        $labels->push("caddy_{$loop}.try_files={path} /index.html /index.php");

        if ($port) {
            $labels->push("caddy_{$loop}.{$handle}.{$loop}_reverse_proxy={{upstreams $port}}");
        } else {
            $labels->push("caddy_{$loop}.{$handle}.{$loop}_reverse_proxy={{upstreams}}");
        }
        $labels->push("caddy_{$loop}.{$handle}={$path}*");
        if ($is_gzip_enabled) {
            $labels->push("caddy_{$loop}.encode=zstd gzip");
        }
        if ($redirect_direction === 'www' && ! str($host)->startsWith('www.')) {
            $labels->push("caddy_{$loop}.redir={$schema}://www.{$host}{uri}");
        }
        if ($redirect_direction === 'non-www' && str($host)->startsWith('www.')) {
            $labels->push("caddy_{$loop}.redir={$schema}://{$host_without_www}{uri}");
        }
        if (isDev()) {
            // $labels->push("caddy_{$loop}.tls=internal");
        }
    }

    return $labels->sort();
}
function fqdnLabelsForTraefik(string $uuid, Collection $domains, bool $is_force_https_enabled = false, $onlyPort = null, ?Collection $serviceLabels = null, ?bool $is_gzip_enabled = true, ?bool $is_stripprefix_enabled = true, ?string $service_name = null, bool $generate_unique_uuid = false, ?string $image = null, string $redirect_direction = 'both')
{
    $labels = collect([]);
    $labels->push('traefik.enable=true');
    $labels->push('traefik.http.middlewares.gzip.compress=true');
    $labels->push('traefik.http.middlewares.redirect-to-https.redirectscheme.scheme=https');

    $middlewares_from_labels = collect([]);

    if ($serviceLabels) {
        $middlewares_from_labels = $serviceLabels->map(function ($item) {
            if (preg_match('/traefik\.http\.middlewares\.(.*?)(\.|$)/', $item, $matches)) {
                return $matches[1];
            }
            if (preg_match('/coolify\.traefik\.middlewares=(.*)/', $item, $matches)) {
                return explode(',', $matches[1]);
            }

            return null;
        })->flatten()
            ->filter()
            ->unique();
    }
    foreach ($domains as $loop => $domain) {
        try {
            if ($generate_unique_uuid) {
                $uuid = new Cuid2;
            }

            $url = Url::fromString($domain);
            $host = $url->getHost();
            $path = $url->getPath();
            $schema = $url->getScheme();
            $port = $url->getPort();
            if (is_null($port) && ! is_null($onlyPort)) {
                $port = $onlyPort;
            }
            $http_label = "http-{$loop}-{$uuid}";
            $https_label = "https-{$loop}-{$uuid}";
            if ($service_name) {
                $http_label = "http-{$loop}-{$uuid}-{$service_name}";
                $https_label = "https-{$loop}-{$uuid}-{$service_name}";
            }
            if (str($image)->contains('ghost')) {
                $labels->push("traefik.http.middlewares.redir-ghost-{$uuid}.redirectregex.regex=^{$path}/(.*)");
                $labels->push("traefik.http.middlewares.redir-ghost-{$uuid}.redirectregex.replacement=/$1");
                $labels->push("caddy_{$loop}.handle_path.{$loop}_redir-ghost-{$uuid}.handler=rewrite");
                $labels->push("caddy_{$loop}.handle_path.{$loop}_redir-ghost-{$uuid}.rewrite.regexp=^{$path}/(.*)");
                $labels->push("caddy_{$loop}.handle_path.{$loop}_redir-ghost-{$uuid}.rewrite.replacement=/$1");
            }

            $to_www_name = "{$loop}-{$uuid}-to-www";
            $to_non_www_name = "{$loop}-{$uuid}-to-non-www";
            $redirect_to_non_www = [
                "traefik.http.middlewares.{$to_non_www_name}.redirectregex.regex=^(http|https)://www\.(.+)",
                "traefik.http.middlewares.{$to_non_www_name}.redirectregex.replacement=\${1}://\${2}",
                "traefik.http.middlewares.{$to_non_www_name}.redirectregex.permanent=false",
            ];
            $redirect_to_www = [
                "traefik.http.middlewares.{$to_www_name}.redirectregex.regex=^(http|https)://(?:www\.)?(.+)",
                "traefik.http.middlewares.{$to_www_name}.redirectregex.replacement=\${1}://www.\${2}",
                "traefik.http.middlewares.{$to_www_name}.redirectregex.permanent=false",
            ];
            if ($schema === 'https') {
                // Set labels for https
                $labels->push("traefik.http.routers.{$https_label}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)");
                $labels->push("traefik.http.routers.{$https_label}.entryPoints=https");
                if ($port) {
                    $labels->push("traefik.http.routers.{$https_label}.service={$https_label}");
                    $labels->push("traefik.http.services.{$https_label}.loadbalancer.server.port=$port");
                }
                if ($path !== '/') {
                    // Middleware handling
                    $middlewares = collect([]);
                    if ($is_stripprefix_enabled && ! str($image)->contains('ghost')) {
                        $labels->push("traefik.http.middlewares.{$https_label}-stripprefix.stripprefix.prefixes={$path}");
                        $middlewares->push("{$https_label}-stripprefix");
                    }
                    if ($is_gzip_enabled) {
                        $middlewares->push('gzip');
                    }
                    if (str($image)->contains('ghost')) {
                        $middlewares->push("redir-ghost-{$uuid}");
                    }
                    if ($redirect_direction === 'non-www' && str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_non_www);
                        $middlewares->push($to_non_www_name);
                    }
                    if ($redirect_direction === 'www' && ! str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_www);
                        $middlewares->push($to_www_name);
                    }
                    $middlewares_from_labels->each(function ($middleware_name) use ($middlewares) {
                        $middlewares->push($middleware_name);
                    });
                    if ($middlewares->isNotEmpty()) {
                        $middlewares = $middlewares->join(',');
                        $labels->push("traefik.http.routers.{$https_label}.middlewares={$middlewares}");
                    }
                } else {
                    $middlewares = collect([]);
                    if ($is_gzip_enabled) {
                        $middlewares->push('gzip');
                    }
                    if (str($image)->contains('ghost')) {
                        $middlewares->push("redir-ghost-{$uuid}");
                    }
                    if ($redirect_direction === 'non-www' && str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_non_www);
                        $middlewares->push($to_non_www_name);
                    }
                    if ($redirect_direction === 'www' && ! str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_www);
                        $middlewares->push($to_www_name);
                    }
                    $middlewares_from_labels->each(function ($middleware_name) use ($middlewares) {
                        $middlewares->push($middleware_name);
                    });
                    if ($middlewares->isNotEmpty()) {
                        $middlewares = $middlewares->join(',');
                        $labels->push("traefik.http.routers.{$https_label}.middlewares={$middlewares}");
                    }
                }
                $labels->push("traefik.http.routers.{$https_label}.tls=true");
                $labels->push("traefik.http.routers.{$https_label}.tls.certresolver=letsencrypt");

                // Set labels for http (redirect to https)
                $labels->push("traefik.http.routers.{$http_label}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)");
                $labels->push("traefik.http.routers.{$http_label}.entryPoints=http");
                if ($port) {
                    $labels->push("traefik.http.services.{$http_label}.loadbalancer.server.port=$port");
                    $labels->push("traefik.http.routers.{$http_label}.service={$http_label}");
                }
                if ($is_force_https_enabled) {
                    $labels->push("traefik.http.routers.{$http_label}.middlewares=redirect-to-https");
                }
            } else {
                // Set labels for http
                $labels->push("traefik.http.routers.{$http_label}.rule=Host(`{$host}`) && PathPrefix(`{$path}`)");
                $labels->push("traefik.http.routers.{$http_label}.entryPoints=http");
                if ($port) {
                    $labels->push("traefik.http.services.{$http_label}.loadbalancer.server.port=$port");
                    $labels->push("traefik.http.routers.{$http_label}.service={$http_label}");
                }
                if ($path !== '/') {
                    $middlewares = collect([]);
                    if ($is_stripprefix_enabled && ! str($image)->contains('ghost')) {
                        $labels->push("traefik.http.middlewares.{$http_label}-stripprefix.stripprefix.prefixes={$path}");
                        $middlewares->push("{$http_label}-stripprefix");
                    }
                    if ($is_gzip_enabled) {
                        $middlewares->push('gzip');
                    }
                    if (str($image)->contains('ghost')) {
                        $middlewares->push("redir-ghost-{$uuid}");
                    }
                    if ($redirect_direction === 'non-www' && str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_non_www);
                        $middlewares->push($to_non_www_name);
                    }
                    if ($redirect_direction === 'www' && ! str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_www);
                        $middlewares->push($to_www_name);
                    }
                    $middlewares_from_labels->each(function ($middleware_name) use ($middlewares) {
                        $middlewares->push($middleware_name);
                    });
                    if ($middlewares->isNotEmpty()) {
                        $middlewares = $middlewares->join(',');
                        $labels->push("traefik.http.routers.{$http_label}.middlewares={$middlewares}");
                    }
                } else {
                    $middlewares = collect([]);
                    if ($is_gzip_enabled) {
                        $middlewares->push('gzip');
                    }
                    if (str($image)->contains('ghost')) {
                        $middlewares->push("redir-ghost-{$uuid}");
                    }
                    if ($redirect_direction === 'non-www' && str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_non_www);
                        $middlewares->push($to_non_www_name);
                    }
                    if ($redirect_direction === 'www' && ! str($host)->startsWith('www.')) {
                        $labels = $labels->merge($redirect_to_www);
                        $middlewares->push($to_www_name);
                    }
                    $middlewares_from_labels->each(function ($middleware_name) use ($middlewares) {
                        $middlewares->push($middleware_name);
                    });
                    if ($middlewares->isNotEmpty()) {
                        $middlewares = $middlewares->join(',');
                        $labels->push("traefik.http.routers.{$http_label}.middlewares={$middlewares}");
                    }
                }
            }
        } catch (\Throwable) {
            continue;
        }
    }

    return $labels->sort();
}
function generateLabelsApplication(Application $application, ?ApplicationPreview $preview = null): array
{
    $ports = $application->settings->is_static ? [80] : $application->ports_exposes_array;
    $onlyPort = null;
    if (count($ports) > 0) {
        $onlyPort = $ports[0];
    }
    $pull_request_id = data_get($preview, 'pull_request_id', 0);
    $appUuid = $application->uuid;
    if ($pull_request_id !== 0) {
        $appUuid = $appUuid.'-pr-'.$pull_request_id;
    }
    $labels = collect([]);
    if ($pull_request_id === 0) {
        if ($application->fqdn) {
            $domains = str(data_get($application, 'fqdn'))->explode(',');
            $shouldGenerateLabelsExactly = $application->destination->server->settings->generate_exact_labels;
            if ($shouldGenerateLabelsExactly) {
                switch ($application->destination->server->proxyType()) {
                    case ProxyTypes::TRAEFIK->value:
                        $labels = $labels->merge(fqdnLabelsForTraefik(
                            uuid: $appUuid,
                            domains: $domains,
                            onlyPort: $onlyPort,
                            is_force_https_enabled: $application->isForceHttpsEnabled(),
                            is_gzip_enabled: $application->isGzipEnabled(),
                            is_stripprefix_enabled: $application->isStripprefixEnabled(),
                            redirect_direction: $application->redirect
                        ));
                        break;
                    case ProxyTypes::CADDY->value:
                        $labels = $labels->merge(fqdnLabelsForCaddy(
                            network: $application->destination->network,
                            uuid: $appUuid,
                            domains: $domains,
                            onlyPort: $onlyPort,
                            is_force_https_enabled: $application->isForceHttpsEnabled(),
                            is_gzip_enabled: $application->isGzipEnabled(),
                            is_stripprefix_enabled: $application->isStripprefixEnabled(),
                            redirect_direction: $application->redirect
                        ));
                        break;
                }
            } else {
                $labels = $labels->merge(fqdnLabelsForTraefik(
                    uuid: $appUuid,
                    domains: $domains,
                    onlyPort: $onlyPort,
                    is_force_https_enabled: $application->isForceHttpsEnabled(),
                    is_gzip_enabled: $application->isGzipEnabled(),
                    is_stripprefix_enabled: $application->isStripprefixEnabled(),
                    redirect_direction: $application->redirect
                ));
                $labels = $labels->merge(fqdnLabelsForCaddy(
                    network: $application->destination->network,
                    uuid: $appUuid,
                    domains: $domains,
                    onlyPort: $onlyPort,
                    is_force_https_enabled: $application->isForceHttpsEnabled(),
                    is_gzip_enabled: $application->isGzipEnabled(),
                    is_stripprefix_enabled: $application->isStripprefixEnabled(),
                    redirect_direction: $application->redirect
                ));
            }
        }
    } else {
        if (data_get($preview, 'fqdn')) {
            $domains = str(data_get($preview, 'fqdn'))->explode(',');
        } else {
            $domains = collect([]);
        }
        $shouldGenerateLabelsExactly = $application->destination->server->settings->generate_exact_labels;
        if ($shouldGenerateLabelsExactly) {
            switch ($application->destination->server->proxyType()) {
                case ProxyTypes::TRAEFIK->value:
                    $labels = $labels->merge(fqdnLabelsForTraefik(
                        uuid: $appUuid,
                        domains: $domains,
                        onlyPort: $onlyPort,
                        is_force_https_enabled: $application->isForceHttpsEnabled(),
                        is_gzip_enabled: $application->isGzipEnabled(),
                        is_stripprefix_enabled: $application->isStripprefixEnabled()
                    ));
                    break;
                case ProxyTypes::CADDY->value:
                    $labels = $labels->merge(fqdnLabelsForCaddy(
                        network: $application->destination->network,
                        uuid: $appUuid,
                        domains: $domains,
                        onlyPort: $onlyPort,
                        is_force_https_enabled: $application->isForceHttpsEnabled(),
                        is_gzip_enabled: $application->isGzipEnabled(),
                        is_stripprefix_enabled: $application->isStripprefixEnabled()
                    ));
                    break;
            }
        } else {
            $labels = $labels->merge(fqdnLabelsForTraefik(
                uuid: $appUuid,
                domains: $domains,
                onlyPort: $onlyPort,
                is_force_https_enabled: $application->isForceHttpsEnabled(),
                is_gzip_enabled: $application->isGzipEnabled(),
                is_stripprefix_enabled: $application->isStripprefixEnabled()
            ));
            $labels = $labels->merge(fqdnLabelsForCaddy(
                network: $application->destination->network,
                uuid: $appUuid,
                domains: $domains,
                onlyPort: $onlyPort,
                is_force_https_enabled: $application->isForceHttpsEnabled(),
                is_gzip_enabled: $application->isGzipEnabled(),
                is_stripprefix_enabled: $application->isStripprefixEnabled()
            ));
        }
    }

    return $labels->all();
}

function isDatabaseImage(?string $image = null)
{
    if (is_null($image)) {
        return false;
    }
    $image = str($image);
    if ($image->contains(':')) {
        $image = str($image);
    } else {
        $image = str($image)->append(':latest');
    }
    $imageName = $image->before(':');
    if (collect(DATABASE_DOCKER_IMAGES)->contains($imageName)) {
        return true;
    }

    return false;
}

function convertDockerRunToCompose(?string $custom_docker_run_options = null)
{
    $options = [];
    $compose_options = collect([]);
    preg_match_all('/(--\w+(?:-\w+)*)(?:\s|=)?([^\s-]+)?/', $custom_docker_run_options, $matches, PREG_SET_ORDER);
    $list_options = collect([
        '--cap-add',
        '--cap-drop',
        '--security-opt',
        '--sysctl',
        '--ulimit',
        '--device',
        '--shm-size',
    ]);
    $mapping = collect([
        '--cap-add' => 'cap_add',
        '--cap-drop' => 'cap_drop',
        '--security-opt' => 'security_opt',
        '--sysctl' => 'sysctls',
        '--device' => 'devices',
        '--init' => 'init',
        '--ulimit' => 'ulimits',
        '--privileged' => 'privileged',
        '--ip' => 'ip',
        '--shm-size' => 'shm_size',
        '--gpus' => 'gpus',
    ]);
    foreach ($matches as $match) {
        $option = $match[1];
        if ($option === '--gpus') {
            $regexForParsingDeviceIds = '/device=([0-9A-Za-z-,]+)/';
            preg_match($regexForParsingDeviceIds, $custom_docker_run_options, $device_matches);
            $value = $device_matches[1] ?? 'all';
            $options[$option][] = $value;
            $options[$option] = array_unique($options[$option]);
        }
        if (isset($match[2]) && $match[2] !== '') {
            $value = $match[2];
            $options[$option][] = $value;
            $options[$option] = array_unique($options[$option]);
        } else {
            $value = true;
            $options[$option] = $value;
        }
    }
    $options = collect($options);
    // Easily get mappings from https://github.com/composerize/composerize/blob/master/packages/composerize/src/mappings.js
    foreach ($options as $option => $value) {
        if (! data_get($mapping, $option)) {
            continue;
        }
        if ($option === '--ulimit') {
            $ulimits = collect([]);
            collect($value)->map(function ($ulimit) use ($ulimits) {
                $ulimit = explode('=', $ulimit);
                $type = $ulimit[0];
                $limits = explode(':', $ulimit[1]);
                if (count($limits) == 2) {
                    $soft_limit = $limits[0];
                    $hard_limit = $limits[1];
                    $ulimits->put($type, [
                        'soft' => $soft_limit,
                        'hard' => $hard_limit,
                    ]);
                } else {
                    $soft_limit = $ulimit[1];
                    $ulimits->put($type, [
                        'soft' => $soft_limit,
                    ]);
                }
            });
            $compose_options->put($mapping[$option], $ulimits);
        } elseif ($option === '--shm-size') {
            if (! is_null($value) && is_array($value) && count($value) > 0) {
                $compose_options->put($mapping[$option], $value[0]);
            }
        } elseif ($option === '--gpus') {
            $payload = [
                'driver' => 'nvidia',
                'capabilities' => ['gpu'],
            ];
            if (! is_null($value) && is_array($value) && count($value) > 0) {
                if (str($value[0]) != 'all') {
                    if (str($value[0])->contains(',')) {
                        $payload['device_ids'] = str($value[0])->explode(',')->toArray();
                    } else {
                        $payload['device_ids'] = [$value[0]];
                    }
                }
            }
            $compose_options->put('deploy', [
                'resources' => [
                    'reservations' => [
                        'devices' => [$payload],
                    ],
                ],
            ]);
        } else {
            if ($list_options->contains($option)) {
                if ($compose_options->has($mapping[$option])) {
                    $compose_options->put($mapping[$option], $options->get($mapping[$option]).','.$value);
                } else {
                    $compose_options->put($mapping[$option], $value);
                }

                continue;
            } else {
                $compose_options->put($mapping[$option], $value);

                continue;
            }
            $compose_options->forget($option);
        }
    }

    return $compose_options->toArray();
}

function generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $network)
{
    $ipv4 = data_get($docker_run_options, 'ip.0');
    $ipv6 = data_get($docker_run_options, 'ip6.0');
    data_forget($docker_run_options, 'ip');
    data_forget($docker_run_options, 'ip6');
    if ($ipv4 || $ipv6) {
        data_forget($docker_compose['services'][$container_name], 'networks');
    }
    if ($ipv4) {
        $docker_compose['services'][$container_name]['networks'][$network]['ipv4_address'] = $ipv4;
    }
    if ($ipv6) {
        $docker_compose['services'][$container_name]['networks'][$network]['ipv6_address'] = $ipv6;
    }
    $docker_compose['services'][$container_name] = array_merge_recursive($docker_compose['services'][$container_name], $docker_run_options);

    return $docker_compose;
}

function validateComposeFile(string $compose, int $server_id): string|Throwable
{
    $uuid = Str::random(18);
    $server = Server::ownedByCurrentTeam()->find($server_id);
    try {
        if (! $server) {
            throw new \Exception('Server not found');
        }
        $base64_compose = base64_encode($compose);
        instant_remote_process([
            "echo {$base64_compose} | base64 -d | tee /tmp/{$uuid}.yml > /dev/null",
            "chmod 600 /tmp/{$uuid}.yml",
            "docker compose -f /tmp/{$uuid}.yml config --no-interpolate --no-path-resolution -q",
            "rm /tmp/{$uuid}.yml",
        ], $server);

        return 'OK';
    } catch (\Throwable $e) {
        return $e->getMessage();
    } finally {
        if (filled($server)) {
            instant_remote_process([
                "rm /tmp/{$uuid}.yml",
            ], $server, throwError: false);
        }
    }
}

function getContainerLogs(Server $server, string $container_id, int $lines = 100): string
{
    if ($server->isSwarm()) {
        $output = instant_remote_process([
            "docker service logs -n {$lines} {$container_id}",
        ], $server);
    } else {
        $output = instant_remote_process([
            "docker logs -n {$lines} {$container_id}",
        ], $server);
    }

    $output .= removeAnsiColors($output);

    return $output;
}

function escapeEnvVariables($value)
{
    $search = ['\\', "\r", "\t", "\x0", '"', "'"];
    $replace = ['\\\\', '\\r', '\\t', '\\0', '\"', "\'"];

    return str_replace($search, $replace, $value);
}
function escapeDollarSign($value)
{
    $search = ['$'];
    $replace = ['$$'];

    return str_replace($search, $replace, $value);
}
