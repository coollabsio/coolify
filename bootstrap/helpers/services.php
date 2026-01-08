<?php

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

function replaceRegex(?string $name = null)
{
    return "/\\\${?{$name}[^}]*}?|\\\${$name}\w+/";
}
function collectRegex(string $name)
{
    return "/{$name}\w+/";
}
function replaceVariables(string $variable): Stringable
{
    return str($variable)->before('}')->replaceFirst('$', '')->replaceFirst('{', '');
}

function getFilesystemVolumesFromServer(ServiceApplication|ServiceDatabase|Application $oneService, bool $isInit = false)
{
    try {
        if ($oneService->getMorphClass() === \App\Models\Application::class) {
            $workdir = $oneService->workdir();
            $server = $oneService->destination->server;
        } else {
            $workdir = $oneService->service->workdir();
            $server = $oneService->service->server;
        }
        $fileVolumes = $oneService->fileStorages()->get();
        $commands = collect([
            "mkdir -p $workdir > /dev/null 2>&1 || true",
            "cd $workdir",
        ]);
        instant_remote_process($commands, $server);
        foreach ($fileVolumes as $fileVolume) {
            $path = str(data_get($fileVolume, 'fs_path'));
            $content = data_get($fileVolume, 'content');
            if ($path->startsWith('.')) {
                $path = $path->after('.');
                $fileLocation = $workdir.$path;
            } else {
                $fileLocation = $path;
            }
            // Exists and is a file
            $isFile = instant_remote_process(["test -f $fileLocation && echo OK || echo NOK"], $server);
            // Exists and is a directory
            $isDir = instant_remote_process(["test -d $fileLocation && echo OK || echo NOK"], $server);

            if ($isFile === 'OK') {
                // If its a file & exists
                $filesystemContent = instant_remote_process(["cat $fileLocation"], $server);
                if ($fileVolume->is_based_on_git) {
                    $fileVolume->content = $filesystemContent;
                }
                $fileVolume->is_directory = false;
                $fileVolume->save();
            } elseif ($isDir === 'OK') {
                // If its a directory & exists
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && ! $fileVolume->is_directory && $isInit && $content) {
                // Does not exists (no dir or file), not flagged as directory, is init, has content
                $fileVolume->content = $content;
                $fileVolume->is_directory = false;
                $fileVolume->save();
                $content = base64_encode($content);
                $dir = str($fileLocation)->dirname();
                instant_remote_process([
                    "mkdir -p $dir",
                    "echo '$content' | base64 -d | tee $fileLocation",
                ], $server);
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && $fileVolume->is_directory && $isInit) {
                // Does not exists (no dir or file), flagged as directory, is init
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
                instant_remote_process(["mkdir -p $fileLocation"], $server);
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && ! $fileVolume->is_directory && $isInit && is_null($content)) {
                // Does not exists (no dir or file), not flagged as directory, is init, has no content => create directory
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
                instant_remote_process(["mkdir -p $fileLocation"], $server);
            }
        }
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
function updateCompose(ServiceApplication|ServiceDatabase $resource)
{
    try {
        $name = data_get($resource, 'name');
        $dockerComposeRaw = data_get($resource, 'service.docker_compose_raw');
        if (! $dockerComposeRaw) {
            throw new \Exception('No compose file found or not a valid YAML file.');
        }
        $dockerCompose = Yaml::parse($dockerComposeRaw);

        // Switch Image
        $updatedImage = data_get_str($resource, 'image');
        $currentImage = data_get_str($dockerCompose, "services.{$name}.image");
        if ($currentImage !== $updatedImage) {
            data_set($dockerCompose, "services.{$name}.image", $updatedImage->value());
            $dockerComposeRaw = Yaml::dump($dockerCompose, 10, 2);
            $resource->service->docker_compose_raw = $dockerComposeRaw;
            $resource->service->save();
            $resource->image = $updatedImage;
            $resource->save();
        }

        // Extract SERVICE_URL and SERVICE_FQDN variable names from the compose template
        // to ensure we use the exact names defined in the template (which may be abbreviated)
        // IMPORTANT: Only extract variables that are DIRECTLY DECLARED for this service,
        // not variables that are merely referenced from other services
        $serviceConfig = data_get($dockerCompose, "services.{$name}");
        $environment = data_get($serviceConfig, 'environment', []);
        $templateVariableNames = [];

        foreach ($environment as $key => $value) {
            if (is_int($key) && is_string($value)) {
                // List-style: "- SERVICE_URL_APP_3000" or "- SERVICE_URL_APP_3000=value"
                // Extract variable name (before '=' if present)
                $envVarName = str($value)->before('=')->trim();
                // Only include if it's a direct declaration (not a reference like ${VAR})
                // Direct declarations look like: SERVICE_URL_APP or SERVICE_URL_APP_3000
                // References look like: NEXT_PUBLIC_URL=${SERVICE_URL_APP}
                if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                    $templateVariableNames[] = $envVarName->value();
                }
            } elseif (is_string($key)) {
                // Map-style: "SERVICE_URL_APP_3000: value" or "SERVICE_FQDN_DB: localhost"
                $envVarName = str($key);
                if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                    $templateVariableNames[] = $envVarName->value();
                }
            }
            // DO NOT extract variables that are only referenced with ${VAR_NAME} syntax
            // Those belong to other services and will be updated when THOSE services are updated
        }

        // Remove duplicates
        $templateVariableNames = array_unique($templateVariableNames);

        // Extract unique service names to process (preserving the original case from template)
        // This allows us to create both URL and FQDN pairs regardless of which one is in the template
        $serviceNamesToProcess = [];
        foreach ($templateVariableNames as $templateVarName) {
            $parsed = parseServiceEnvironmentVariable($templateVarName);

            // Extract the original service name with case preserved from the template
            $strKey = str($templateVarName);
            if ($parsed['has_port']) {
                // For port-specific variables, get the name between SERVICE_URL_/SERVICE_FQDN_ and the last underscore
                if ($strKey->startsWith('SERVICE_URL_')) {
                    $serviceName = $strKey->after('SERVICE_URL_')->beforeLast('_')->value();
                } elseif ($strKey->startsWith('SERVICE_FQDN_')) {
                    $serviceName = $strKey->after('SERVICE_FQDN_')->beforeLast('_')->value();
                } else {
                    continue;
                }
            } else {
                // For base variables, get everything after SERVICE_URL_/SERVICE_FQDN_
                if ($strKey->startsWith('SERVICE_URL_')) {
                    $serviceName = $strKey->after('SERVICE_URL_')->value();
                } elseif ($strKey->startsWith('SERVICE_FQDN_')) {
                    $serviceName = $strKey->after('SERVICE_FQDN_')->value();
                } else {
                    continue;
                }
            }

            // Use lowercase key for array indexing (to group case variations together)
            $serviceKey = str($serviceName)->lower()->value();

            // Track both base service name and port-specific variant
            if (! isset($serviceNamesToProcess[$serviceKey])) {
                $serviceNamesToProcess[$serviceKey] = [
                    'base' => $serviceName,  // Preserve original case
                    'ports' => [],
                ];
            }

            // If this variable has a port, track it
            if ($parsed['has_port'] && $parsed['port']) {
                $serviceNamesToProcess[$serviceKey]['ports'][] = $parsed['port'];
            }
        }

        // Delete all existing SERVICE_URL and SERVICE_FQDN variables for these service names
        // We need to delete both URL and FQDN variants, with and without ports
        foreach ($serviceNamesToProcess as $serviceInfo) {
            $serviceName = $serviceInfo['base'];

            // Delete base variables
            $resource->service->environment_variables()->where('key', "SERVICE_URL_{$serviceName}")->delete();
            $resource->service->environment_variables()->where('key', "SERVICE_FQDN_{$serviceName}")->delete();

            // Delete port-specific variables
            foreach ($serviceInfo['ports'] as $port) {
                $resource->service->environment_variables()->where('key', "SERVICE_URL_{$serviceName}_{$port}")->delete();
                $resource->service->environment_variables()->where('key', "SERVICE_FQDN_{$serviceName}_{$port}")->delete();
            }
        }

        if ($resource->fqdn) {
            $resourceFqdns = str($resource->fqdn)->explode(',');
            $resourceFqdns = $resourceFqdns->first();
            $url = Url::fromString($resourceFqdns);
            $port = $url->getPort();
            $path = $url->getPath();

            // Prepare URL value (with scheme and host)
            $urlValue = $url->getScheme().'://'.$url->getHost();
            $urlValue = ($path === '/') ? $urlValue : $urlValue.$path;

            // Prepare FQDN value (host only, no scheme)
            $fqdnHost = $url->getHost();
            $fqdnValue = str($fqdnHost)->after('://');
            if ($path !== '/') {
                $fqdnValue = $fqdnValue.$path;
            }

            // For each service name found in template, create BOTH SERVICE_URL and SERVICE_FQDN pairs
            foreach ($serviceNamesToProcess as $serviceInfo) {
                $serviceName = $serviceInfo['base'];
                $ports = array_unique($serviceInfo['ports']);

                // ALWAYS create base pair (without port)
                $resource->service->environment_variables()->updateOrCreate([
                    'resourceable_type' => Service::class,
                    'resourceable_id' => $resource->service_id,
                    'key' => "SERVICE_URL_{$serviceName}",
                ], [
                    'value' => $urlValue,
                    'is_preview' => false,
                ]);

                $resource->service->environment_variables()->updateOrCreate([
                    'resourceable_type' => Service::class,
                    'resourceable_id' => $resource->service_id,
                    'key' => "SERVICE_FQDN_{$serviceName}",
                ], [
                    'value' => $fqdnValue,
                    'is_preview' => false,
                ]);

                // Create port-specific pairs for each port found in template or FQDN
                $allPorts = $ports;
                if ($port && ! in_array($port, $allPorts)) {
                    $allPorts[] = $port;
                }

                foreach ($allPorts as $portNum) {
                    $urlWithPort = $urlValue.':'.$portNum;
                    $fqdnWithPort = $fqdnValue.':'.$portNum;

                    $resource->service->environment_variables()->updateOrCreate([
                        'resourceable_type' => Service::class,
                        'resourceable_id' => $resource->service_id,
                        'key' => "SERVICE_URL_{$serviceName}_{$portNum}",
                    ], [
                        'value' => $urlWithPort,
                        'is_preview' => false,
                    ]);

                    $resource->service->environment_variables()->updateOrCreate([
                        'resourceable_type' => Service::class,
                        'resourceable_id' => $resource->service_id,
                        'key' => "SERVICE_FQDN_{$serviceName}_{$portNum}",
                    ], [
                        'value' => $fqdnWithPort,
                        'is_preview' => false,
                    ]);
                }
            }
        }
    } catch (\Throwable $e) {
        return handleError($e);
    }
}
function serviceKeys()
{
    return get_service_templates()->keys();
}

/**
 * Parse a SERVICE_URL_* or SERVICE_FQDN_* variable to extract the service name and port.
 *
 * This function detects if a service environment variable has a port suffix by checking
 * if the last segment after the underscore is numeric.
 *
 * Examples:
 *   - SERVICE_URL_APP_3000 → ['service_name' => 'app', 'port' => '3000', 'has_port' => true]
 *   - SERVICE_URL_MY_API_8080 → ['service_name' => 'my_api', 'port' => '8080', 'has_port' => true]
 *   - SERVICE_URL_MY_APP → ['service_name' => 'my_app', 'port' => null, 'has_port' => false]
 *   - SERVICE_FQDN_REDIS_CACHE_6379 → ['service_name' => 'redis_cache', 'port' => '6379', 'has_port' => true]
 *
 * @param  string  $key  The environment variable key (e.g., SERVICE_URL_APP_3000)
 * @return array{service_name: string, port: string|null, has_port: bool} Parsed service information
 */
function parseServiceEnvironmentVariable(string $key): array
{
    $strKey = str($key);
    $lastSegment = $strKey->afterLast('_')->value();
    $hasPort = is_numeric($lastSegment) && ctype_digit($lastSegment);

    if ($hasPort) {
        // Port-specific variable (e.g., SERVICE_URL_APP_3000)
        if ($strKey->startsWith('SERVICE_URL_')) {
            $serviceName = $strKey->after('SERVICE_URL_')->beforeLast('_')->lower()->value();
        } elseif ($strKey->startsWith('SERVICE_FQDN_')) {
            $serviceName = $strKey->after('SERVICE_FQDN_')->beforeLast('_')->lower()->value();
        } else {
            $serviceName = '';
        }
        $port = $lastSegment;
    } else {
        // Base variable without port (e.g., SERVICE_URL_APP)
        if ($strKey->startsWith('SERVICE_URL_')) {
            $serviceName = $strKey->after('SERVICE_URL_')->lower()->value();
        } elseif ($strKey->startsWith('SERVICE_FQDN_')) {
            $serviceName = $strKey->after('SERVICE_FQDN_')->lower()->value();
        } else {
            $serviceName = '';
        }
        $port = null;
    }

    return [
        'service_name' => $serviceName,
        'port' => $port,
        'has_port' => $hasPort,
    ];
}

/**
 * Apply service-specific application prerequisites after service parse.
 *
 * This function configures application-level settings that are required for
 * specific one-click services to work correctly (e.g., disabling gzip for Beszel,
 * disabling strip prefix for Appwrite services).
 *
 * Must be called AFTER $service->parse() since it requires applications to exist.
 *
 * @param  Service  $service  The service to apply prerequisites to
 */
function applyServiceApplicationPrerequisites(Service $service): void
{
    try {
        // Extract service name from service name (format: "servicename-uuid")
        $serviceName = str($service->name)->beforeLast('-')->value();

        // Apply gzip disabling if needed
        if (array_key_exists($serviceName, NEEDS_TO_DISABLE_GZIP)) {
            $applicationNames = NEEDS_TO_DISABLE_GZIP[$serviceName];
            foreach ($applicationNames as $applicationName) {
                $application = $service->applications()->whereName($applicationName)->first();
                if ($application) {
                    $application->is_gzip_enabled = false;
                    $application->save();
                }
            }
        }

        // Apply stripprefix disabling if needed
        if (array_key_exists($serviceName, NEEDS_TO_DISABLE_STRIPPREFIX)) {
            $applicationNames = NEEDS_TO_DISABLE_STRIPPREFIX[$serviceName];
            foreach ($applicationNames as $applicationName) {
                $application = $service->applications()->whereName($applicationName)->first();
                if ($application) {
                    $application->is_stripprefix_enabled = false;
                    $application->save();
                }
            }
        }
    } catch (\Throwable $e) {
        // Log error but don't throw - prerequisites are nice-to-have, not critical
        Log::error('Failed to apply service application prerequisites', [
            'service_id' => $service->id,
            'service_name' => $service->name,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
