<?php

use App\Enums\ProxyTypes;
use App\Jobs\ServerFilesFromServerJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

/**
 * Validates a Docker Compose YAML string for command injection vulnerabilities.
 * This should be called BEFORE saving to database to prevent malicious data from being stored.
 *
 * @param  string  $composeYaml  The raw Docker Compose YAML content
 *
 * @throws \Exception If the compose file contains command injection attempts
 */
function validateDockerComposeForInjection(string $composeYaml): void
{
    try {
        $parsed = Yaml::parse($composeYaml);
    } catch (\Exception $e) {
        throw new \Exception('Invalid YAML format: '.$e->getMessage(), 0, $e);
    }

    if (! is_array($parsed) || ! isset($parsed['services']) || ! is_array($parsed['services'])) {
        throw new \Exception('Docker Compose file must contain a "services" section');
    }
    // Validate service names
    foreach ($parsed['services'] as $serviceName => $serviceConfig) {
        try {
            validateShellSafePath($serviceName, 'service name');
        } catch (\Exception $e) {
            throw new \Exception(
                'Invalid Docker Compose service name: '.$e->getMessage().
                ' Service names must not contain shell metacharacters.',
                0,
                $e
            );
        }

        // Validate volumes in this service (both string and array formats)
        if (isset($serviceConfig['volumes']) && is_array($serviceConfig['volumes'])) {
            foreach ($serviceConfig['volumes'] as $volume) {
                if (is_string($volume)) {
                    // String format: "source:target" or "source:target:mode"
                    validateVolumeStringForInjection($volume);
                } elseif (is_array($volume)) {
                    // Array format: {type: bind, source: ..., target: ...}
                    if (isset($volume['source'])) {
                        $source = $volume['source'];
                        if (is_string($source)) {
                            // Allow env vars and env vars with defaults (validated in parseDockerVolumeString)
                            // Also allow env vars followed by safe path concatenation (e.g., ${VAR}/path)
                            $isSimpleEnvVar = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}$/', $source);
                            $isEnvVarWithDefault = preg_match('/^\$\{[^}]+:-[^}]*\}$/', $source);
                            $isEnvVarWithPath = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}[\/\w\.\-]*$/', $source);

                            if (! $isSimpleEnvVar && ! $isEnvVarWithDefault && ! $isEnvVarWithPath) {
                                try {
                                    validateShellSafePath($source, 'volume source');
                                } catch (\Exception $e) {
                                    throw new \Exception(
                                        'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                        ' Please use safe path names without shell metacharacters.',
                                        0,
                                        $e
                                    );
                                }
                            }
                        }
                    }
                    if (isset($volume['target'])) {
                        $target = $volume['target'];
                        if (is_string($target)) {
                            try {
                                validateShellSafePath($target, 'volume target');
                            } catch (\Exception $e) {
                                throw new \Exception(
                                    'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                    ' Please use safe path names without shell metacharacters.',
                                    0,
                                    $e
                                );
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Validates a Docker volume string (format: "source:target" or "source:target:mode")
 *
 * @param  string  $volumeString  The volume string to validate
 *
 * @throws \Exception If the volume string contains command injection attempts
 */
function validateVolumeStringForInjection(string $volumeString): void
{
    // Canonical parsing also validates and throws on unsafe input
    parseDockerVolumeString($volumeString);
}

function parseDockerVolumeString(string $volumeString): array
{
    $volumeString = trim($volumeString);
    $source = null;
    $target = null;
    $mode = null;

    // First, check if the source contains an environment variable with default value
    // This needs to be done before counting colons because ${VAR:-value} contains a colon
    $envVarPattern = '/^\$\{[^}]+:-[^}]*\}/';
    $hasEnvVarWithDefault = false;
    $envVarEndPos = 0;

    if (preg_match($envVarPattern, $volumeString, $matches)) {
        $hasEnvVarWithDefault = true;
        $envVarEndPos = strlen($matches[0]);
    }

    // Count colons, but exclude those inside environment variables
    $effectiveVolumeString = $volumeString;
    if ($hasEnvVarWithDefault) {
        // Temporarily replace the env var to count colons correctly
        $effectiveVolumeString = substr($volumeString, $envVarEndPos);
        $colonCount = substr_count($effectiveVolumeString, ':');
    } else {
        $colonCount = substr_count($volumeString, ':');
    }

    if ($colonCount === 0) {
        // Named volume without target (unusual but valid)
        // Example: "myvolume"
        $source = $volumeString;
        $target = $volumeString;
    } elseif ($colonCount === 1) {
        // Simple volume mapping
        // Examples: "gitea:/data" or "./data:/app/data" or "${VAR:-default}:/data"
        if ($hasEnvVarWithDefault) {
            $source = substr($volumeString, 0, $envVarEndPos);
            $remaining = substr($volumeString, $envVarEndPos);
            if (strlen($remaining) > 0 && $remaining[0] === ':') {
                $target = substr($remaining, 1);
            } else {
                $target = $remaining;
            }
        } else {
            $parts = explode(':', $volumeString);
            $source = $parts[0];
            $target = $parts[1];
        }
    } elseif ($colonCount === 2) {
        // Volume with mode OR Windows path OR env var with mode
        // Handle env var with mode first
        if ($hasEnvVarWithDefault) {
            // ${VAR:-default}:/path:mode
            $source = substr($volumeString, 0, $envVarEndPos);
            $remaining = substr($volumeString, $envVarEndPos);

            if (strlen($remaining) > 0 && $remaining[0] === ':') {
                $remaining = substr($remaining, 1);
                $lastColon = strrpos($remaining, ':');

                if ($lastColon !== false) {
                    $possibleMode = substr($remaining, $lastColon + 1);
                    $validModes = ['ro', 'rw', 'z', 'Z', 'rslave', 'rprivate', 'rshared', 'slave', 'private', 'shared', 'cached', 'delegated', 'consistent'];

                    if (in_array($possibleMode, $validModes)) {
                        $mode = $possibleMode;
                        $target = substr($remaining, 0, $lastColon);
                    } else {
                        $target = $remaining;
                    }
                } else {
                    $target = $remaining;
                }
            }
        } elseif (preg_match('/^[A-Za-z]:/', $volumeString)) {
            // Windows path as source (C:/, D:/, etc.)
            // Find the second colon which is the real separator
            $secondColon = strpos($volumeString, ':', 2);
            if ($secondColon !== false) {
                $source = substr($volumeString, 0, $secondColon);
                $target = substr($volumeString, $secondColon + 1);
            } else {
                // Malformed, treat as is
                $source = $volumeString;
                $target = $volumeString;
            }
        } else {
            // Not a Windows path, check for mode
            $lastColon = strrpos($volumeString, ':');
            $possibleMode = substr($volumeString, $lastColon + 1);

            // Check if the last part is a valid Docker volume mode
            $validModes = ['ro', 'rw', 'z', 'Z', 'rslave', 'rprivate', 'rshared', 'slave', 'private', 'shared', 'cached', 'delegated', 'consistent'];

            if (in_array($possibleMode, $validModes)) {
                // It's a mode
                // Examples: "gitea:/data:ro" or "./data:/app/data:rw"
                $mode = $possibleMode;
                $volumeWithoutMode = substr($volumeString, 0, $lastColon);
                $colonPos = strpos($volumeWithoutMode, ':');

                if ($colonPos !== false) {
                    $source = substr($volumeWithoutMode, 0, $colonPos);
                    $target = substr($volumeWithoutMode, $colonPos + 1);
                } else {
                    // Shouldn't happen for valid volume strings
                    $source = $volumeWithoutMode;
                    $target = $volumeWithoutMode;
                }
            } else {
                // The last colon is part of the path
                // For now, treat the first occurrence of : as the separator
                $firstColon = strpos($volumeString, ':');
                $source = substr($volumeString, 0, $firstColon);
                $target = substr($volumeString, $firstColon + 1);
            }
        }
    } else {
        // More than 2 colons - likely Windows paths or complex cases
        // Use a heuristic: find the most likely separator colon
        // Look for patterns like "C:" at the beginning (Windows drive)
        if (preg_match('/^[A-Za-z]:/', $volumeString)) {
            // Windows path as source
            // Find the next colon after the drive letter
            $secondColon = strpos($volumeString, ':', 2);
            if ($secondColon !== false) {
                $source = substr($volumeString, 0, $secondColon);
                $remaining = substr($volumeString, $secondColon + 1);

                // Check if there's a mode at the end
                $lastColon = strrpos($remaining, ':');
                if ($lastColon !== false) {
                    $possibleMode = substr($remaining, $lastColon + 1);
                    $validModes = ['ro', 'rw', 'z', 'Z', 'rslave', 'rprivate', 'rshared', 'slave', 'private', 'shared', 'cached', 'delegated', 'consistent'];

                    if (in_array($possibleMode, $validModes)) {
                        $mode = $possibleMode;
                        $target = substr($remaining, 0, $lastColon);
                    } else {
                        $target = $remaining;
                    }
                } else {
                    $target = $remaining;
                }
            } else {
                // Malformed, treat as is
                $source = $volumeString;
                $target = $volumeString;
            }
        } else {
            // Try to parse normally, treating first : as separator
            $firstColon = strpos($volumeString, ':');
            $source = substr($volumeString, 0, $firstColon);
            $remaining = substr($volumeString, $firstColon + 1);

            // Check for mode at the end
            $lastColon = strrpos($remaining, ':');
            if ($lastColon !== false) {
                $possibleMode = substr($remaining, $lastColon + 1);
                $validModes = ['ro', 'rw', 'z', 'Z', 'rslave', 'rprivate', 'rshared', 'slave', 'private', 'shared', 'cached', 'delegated', 'consistent'];

                if (in_array($possibleMode, $validModes)) {
                    $mode = $possibleMode;
                    $target = substr($remaining, 0, $lastColon);
                } else {
                    $target = $remaining;
                }
            } else {
                $target = $remaining;
            }
        }
    }

    // Handle environment variable expansion in source
    // Example: ${VOLUME_DB_PATH:-db} should extract default value if present
    if ($source && preg_match('/^\$\{([^}]+)\}$/', $source, $matches)) {
        $varContent = $matches[1];

        // Check if there's a default value with :-
        if (strpos($varContent, ':-') !== false) {
            $parts = explode(':-', $varContent, 2);
            $varName = $parts[0];
            $defaultValue = isset($parts[1]) ? $parts[1] : '';

            // If there's a non-empty default value, use it for source
            if ($defaultValue !== '') {
                $source = $defaultValue;
            } else {
                // Empty default value, keep the variable reference for env resolution
                $source = '${'.$varName.'}';
            }
        }
        // Otherwise keep the variable as-is for later expansion (no default value)
    }

    // Validate source path for command injection attempts
    // We validate the final source value after environment variable processing
    if ($source !== null) {
        // Allow environment variables like ${VAR_NAME} or ${VAR}
        // Also allow env vars followed by safe path concatenation (e.g., ${VAR}/path)
        $sourceStr = is_string($source) ? $source : $source;

        // Skip validation for simple environment variable references
        // Pattern 1: ${WORD_CHARS} with no special characters inside
        // Pattern 2: ${WORD_CHARS}/path/to/file (env var with path concatenation)
        $isSimpleEnvVar = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}$/', $sourceStr);
        $isEnvVarWithPath = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}[\/\w\.\-]*$/', $sourceStr);

        if (! $isSimpleEnvVar && ! $isEnvVarWithPath) {
            try {
                validateShellSafePath($sourceStr, 'volume source');
            } catch (\Exception $e) {
                // Re-throw with more context about the volume string
                throw new \Exception(
                    'Invalid Docker volume definition: '.$e->getMessage().
                    ' Please use safe path names without shell metacharacters.'
                );
            }
        }
    }

    // Also validate target path
    if ($target !== null) {
        $targetStr = is_string($target) ? $target : $target;
        // Target paths in containers are typically absolute paths, so we validate them too
        // but they're less likely to be dangerous since they're not used in host commands
        // Still, defense in depth is important
        try {
            validateShellSafePath($targetStr, 'volume target');
        } catch (\Exception $e) {
            throw new \Exception(
                'Invalid Docker volume definition: '.$e->getMessage().
                ' Please use safe path names without shell metacharacters.'
            );
        }
    }

    return [
        'source' => $source !== null ? str($source) : null,
        'target' => $target !== null ? str($target) : null,
        'mode' => $mode !== null ? str($mode) : null,
    ];
}

function applicationParser(Application $resource, int $pull_request_id = 0, ?int $preview_id = null, ?string $commit = null): Collection
{
    $uuid = data_get($resource, 'uuid');
    $compose = data_get($resource, 'docker_compose_raw');
    // Store original compose for later use to update docker_compose_raw with content removed
    $originalCompose = $compose;
    if (! $compose) {
        return collect([]);
    }

    $pullRequestId = $pull_request_id;
    $isPullRequest = $pullRequestId == 0 ? false : true;
    $server = data_get($resource, 'destination.server');
    $fileStorages = $resource->fileStorages();

    try {
        $yaml = Yaml::parse($compose);
    } catch (\Exception) {
        return collect([]);
    }
    $services = data_get($yaml, 'services', collect([]));
    $topLevel = collect([
        'volumes' => collect(data_get($yaml, 'volumes', [])),
        'networks' => collect(data_get($yaml, 'networks', [])),
        'configs' => collect(data_get($yaml, 'configs', [])),
        'secrets' => collect(data_get($yaml, 'secrets', [])),
    ]);
    // If there are predefined volumes, make sure they are not null
    if ($topLevel->get('volumes')->count() > 0) {
        $temp = collect([]);
        foreach ($topLevel['volumes'] as $volumeName => $volume) {
            if (is_null($volume)) {
                continue;
            }
            $temp->put($volumeName, $volume);
        }
        $topLevel['volumes'] = $temp;
    }
    // Get the base docker network
    $baseNetwork = collect([$uuid]);
    if ($isPullRequest) {
        $baseNetwork = collect(["{$uuid}-{$pullRequestId}"]);
    }

    $parsedServices = collect([]);

    $allMagicEnvironments = collect([]);
    foreach ($services as $serviceName => $service) {
        // Validate service name for command injection
        try {
            validateShellSafePath($serviceName, 'service name');
        } catch (\Exception $e) {
            throw new \Exception(
                'Invalid Docker Compose service name: '.$e->getMessage().
                ' Service names must not contain shell metacharacters.'
            );
        }

        $magicEnvironments = collect([]);
        $image = data_get_str($service, 'image');
        $environment = collect(data_get($service, 'environment', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        $environment = collect(data_get($service, 'environment', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        // convert environment variables to one format
        $environment = convertToKeyValueCollection($environment);

        // Add Coolify defined environments
        $allEnvironments = $resource->environment_variables()->get(['key', 'value']);

        $allEnvironments = $allEnvironments->mapWithKeys(function ($item) {
            return [$item['key'] => $item['value']];
        });
        // filter and add magic environments
        foreach ($environment as $key => $value) {
            // Get all SERVICE_ variables from keys and values
            $key = str($key);
            $value = str($value);
            $regex = '/\$(\{?([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\}?)/';
            preg_match_all($regex, $value, $valueMatches);
            if (count($valueMatches[1]) > 0) {
                foreach ($valueMatches[1] as $match) {
                    $match = replaceVariables($match);
                    if ($match->startsWith('SERVICE_')) {
                        if ($magicEnvironments->has($match->value())) {
                            continue;
                        }
                        $magicEnvironments->put($match->value(), '');
                    }
                }
            }
            // Get magic environments where we need to preset the FQDN
            // for example SERVICE_FQDN_APP_3000 (without a value)
            if ($key->startsWith('SERVICE_FQDN_')) {
                // SERVICE_FQDN_APP or SERVICE_FQDN_APP_3000
                $parsed = parseServiceEnvironmentVariable($key->value());
                $fqdnFor = $parsed['service_name'];
                $port = $parsed['port'];
                $fqdn = $resource->fqdn;
                if (blank($resource->fqdn)) {
                    $fqdn = generateFqdn(server: $server, random: "$uuid", parserVersion: $resource->compose_parsing_version);
                }

                if ($value && get_class($value) === \Illuminate\Support\Stringable::class && $value->startsWith('/')) {
                    $path = $value->value();
                    if ($path !== '/') {
                        $fqdn = "$fqdn$path";
                    }
                }
                $fqdnWithPort = $fqdn;
                if ($port) {
                    $fqdnWithPort = "$fqdn:$port";
                }
                if (is_null($resource->fqdn)) {
                    data_forget($resource, 'environment_variables');
                    data_forget($resource, 'environment_variables_preview');
                    $resource->fqdn = $fqdnWithPort;
                    $resource->save();
                }

                if (! $parsed['has_port']) {
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_preview' => false,
                    ]);
                }
                if ($parsed['has_port']) {

                    $newKey = str($key)->beforeLast('_');
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $newKey->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_preview' => false,
                    ]);
                }
            }
        }

        $allMagicEnvironments = $allMagicEnvironments->merge($magicEnvironments);
        if ($magicEnvironments->count() > 0) {
            // Generate Coolify environment variables
            foreach ($magicEnvironments as $key => $value) {
                $key = str($key);
                $value = replaceVariables($value);
                $command = parseCommandFromMagicEnvVariable($key);
                if ($command->value() === 'FQDN' || $command->value() === 'URL') {
                    // ALWAYS create BOTH SERVICE_URL and SERVICE_FQDN pairs regardless of which one is in template
                    $parsed = parseServiceEnvironmentVariable($key->value());
                    $serviceName = $parsed['service_name'];
                    $port = $parsed['port'];

                    // Extract case-preserved service name from template
                    $strKey = str($key->value());
                    if ($parsed['has_port']) {
                        if ($strKey->startsWith('SERVICE_URL_')) {
                            $serviceNamePreserved = $strKey->after('SERVICE_URL_')->beforeLast('_')->value();
                        } else {
                            $serviceNamePreserved = $strKey->after('SERVICE_FQDN_')->beforeLast('_')->value();
                        }
                    } else {
                        if ($strKey->startsWith('SERVICE_URL_')) {
                            $serviceNamePreserved = $strKey->after('SERVICE_URL_')->value();
                        } else {
                            $serviceNamePreserved = $strKey->after('SERVICE_FQDN_')->value();
                        }
                    }

                    $originalServiceName = str($serviceName)->replace('_', '-')->value();
                    // Always normalize service names to match docker_compose_domains lookup
                    $serviceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();

                    // Generate BOTH FQDN & URL
                    $fqdn = generateFqdn(server: $server, random: "$originalServiceName-$uuid", parserVersion: $resource->compose_parsing_version);
                    $url = generateUrl(server: $server, random: "$originalServiceName-$uuid");

                    // IMPORTANT: SERVICE_FQDN env vars should NOT contain scheme (host only)
                    // But $fqdn variable itself may contain scheme (used for database domain field)
                    // Strip scheme for environment variable values
                    $fqdnValueForEnv = str($fqdn)->after('://')->value();

                    // Append port if specified
                    $urlWithPort = $url;
                    $fqdnValueForEnvWithPort = $fqdnValueForEnv;
                    if ($port && is_numeric($port)) {
                        $urlWithPort = "$url:$port";
                        $fqdnValueForEnvWithPort = "$fqdnValueForEnv:$port";
                    }

                    // ALWAYS create base SERVICE_FQDN variable (host only, no scheme)
                    $resource->environment_variables()->firstOrCreate([
                        'key' => "SERVICE_FQDN_{$serviceNamePreserved}",
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdnValueForEnv,
                        'is_preview' => false,
                    ]);

                    // ALWAYS create base SERVICE_URL variable (with scheme)
                    $resource->environment_variables()->firstOrCreate([
                        'key' => "SERVICE_URL_{$serviceNamePreserved}",
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_preview' => false,
                    ]);

                    // If port-specific, ALSO create port-specific pairs
                    if ($parsed['has_port'] && $port) {
                        $resource->environment_variables()->firstOrCreate([
                            'key' => "SERVICE_FQDN_{$serviceNamePreserved}_{$port}",
                            'resourceable_type' => get_class($resource),
                            'resourceable_id' => $resource->id,
                        ], [
                            'value' => $fqdnValueForEnvWithPort,
                            'is_preview' => false,
                        ]);

                        $resource->environment_variables()->firstOrCreate([
                            'key' => "SERVICE_URL_{$serviceNamePreserved}_{$port}",
                            'resourceable_type' => get_class($resource),
                            'resourceable_id' => $resource->id,
                        ], [
                            'value' => $urlWithPort,
                            'is_preview' => false,
                        ]);
                    }

                    if ($resource->build_pack === 'dockercompose') {
                        // Check if a service with this name actually exists
                        $serviceExists = false;
                        foreach ($services as $serviceNameKey => $service) {
                            $transformedServiceName = str($serviceNameKey)->replace('-', '_')->replace('.', '_')->value();
                            if ($transformedServiceName === $serviceName) {
                                $serviceExists = true;
                                break;
                            }
                        }

                        // Only add domain if the service exists
                        if ($serviceExists) {
                            $domains = collect(json_decode(data_get($resource, 'docker_compose_domains'))) ?? collect([]);
                            $domainExists = data_get($domains->get($serviceName), 'domain');

                            // Update domain using URL with port if applicable
                            $domainValue = $port ? $urlWithPort : $url;

                            if (is_null($domainExists)) {
                                $domains->put($serviceName, [
                                    'domain' => $domainValue,
                                ]);
                                $resource->docker_compose_domains = $domains->toJson();
                                $resource->save();
                            }
                        }
                    }
                } else {
                    $value = generateEnvValue($command, $resource);
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $value,
                        'is_preview' => false,
                    ]);
                }
            }
        }
    }

    // generate SERVICE_NAME variables for docker compose services
    $serviceNameEnvironments = collect([]);
    if ($resource->build_pack === 'dockercompose') {
        $serviceNameEnvironments = generateDockerComposeServiceName($services, $pullRequestId);
    }

    // Parse the rest of the services
    foreach ($services as $serviceName => $service) {
        $image = data_get_str($service, 'image');
        $restart = data_get_str($service, 'restart', RESTART_MODE);
        $logging = data_get($service, 'logging');

        if ($server->isLogDrainEnabled()) {
            if ($resource->isLogDrainEnabled()) {
                $logging = generate_fluentd_configuration();
            }
        }
        $volumes = collect(data_get($service, 'volumes', []));
        $networks = collect(data_get($service, 'networks', []));
        $use_network_mode = data_get($service, 'network_mode') !== null;
        $depends_on = collect(data_get($service, 'depends_on', []));
        $labels = collect(data_get($service, 'labels', []));
        if ($labels->count() > 0) {
            if (isAssociativeArray($labels)) {
                $newLabels = collect([]);
                $labels->each(function ($value, $key) use ($newLabels) {
                    $newLabels->push("$key=$value");
                });
                $labels = $newLabels;
            }
        }
        $environment = collect(data_get($service, 'environment', []));
        $ports = collect(data_get($service, 'ports', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        $environment = convertToKeyValueCollection($environment);
        $coolifyEnvironments = collect([]);

        $isDatabase = isDatabaseImage($image, $service);
        $volumesParsed = collect([]);

        $baseName = generateApplicationContainerName(
            application: $resource,
            pull_request_id: $pullRequestId
        );
        $containerName = "$serviceName-$baseName";
        $predefinedPort = null;

        $originalResource = $resource;

        if ($volumes->count() > 0) {
            foreach ($volumes as $index => $volume) {
                $type = null;
                $source = null;
                $target = null;
                $content = null;
                $isDirectory = false;
                if (is_string($volume)) {
                    $parsed = parseDockerVolumeString($volume);
                    $source = $parsed['source'];
                    $target = $parsed['target'];
                    // Mode is available in $parsed['mode'] if needed
                    $foundConfig = $fileStorages->whereMountPath($target)->first();
                    if (sourceIsLocal($source)) {
                        $type = str('bind');
                        if ($foundConfig) {
                            $contentNotNull_temp = data_get($foundConfig, 'content');
                            if ($contentNotNull_temp) {
                                $content = $contentNotNull_temp;
                            }
                            $isDirectory = data_get($foundConfig, 'is_directory');
                        } else {
                            // By default, we cannot determine if the bind is a directory or not, so we set it to directory
                            $isDirectory = true;
                        }
                    } else {
                        $type = str('volume');
                    }
                } elseif (is_array($volume)) {
                    $type = data_get_str($volume, 'type');
                    $source = data_get_str($volume, 'source');
                    $target = data_get_str($volume, 'target');
                    $content = data_get($volume, 'content');
                    $isDirectory = (bool) data_get($volume, 'isDirectory', null) || (bool) data_get($volume, 'is_directory', null);

                    // Validate source and target for command injection (array/long syntax)
                    if ($source !== null && ! empty($source->value())) {
                        $sourceValue = $source->value();
                        // Allow environment variable references and env vars with path concatenation
                        $isSimpleEnvVar = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}$/', $sourceValue);
                        $isEnvVarWithDefault = preg_match('/^\$\{[^}]+:-[^}]*\}$/', $sourceValue);
                        $isEnvVarWithPath = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}[\/\w\.\-]*$/', $sourceValue);

                        if (! $isSimpleEnvVar && ! $isEnvVarWithDefault && ! $isEnvVarWithPath) {
                            try {
                                validateShellSafePath($sourceValue, 'volume source');
                            } catch (\Exception $e) {
                                throw new \Exception(
                                    'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                    ' Please use safe path names without shell metacharacters.'
                                );
                            }
                        }
                    }
                    if ($target !== null && ! empty($target->value())) {
                        try {
                            validateShellSafePath($target->value(), 'volume target');
                        } catch (\Exception $e) {
                            throw new \Exception(
                                'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                ' Please use safe path names without shell metacharacters.'
                            );
                        }
                    }

                    $foundConfig = $fileStorages->whereMountPath($target)->first();
                    if ($foundConfig) {
                        $contentNotNull_temp = data_get($foundConfig, 'content');
                        if ($contentNotNull_temp) {
                            $content = $contentNotNull_temp;
                        }
                        $isDirectory = data_get($foundConfig, 'is_directory');
                    } else {
                        // if isDirectory is not set (or false) & content is also not set, we assume it is a directory
                        if ((is_null($isDirectory) || ! $isDirectory) && is_null($content)) {
                            $isDirectory = true;
                        }
                    }
                }
                if ($type->value() === 'bind') {
                    if ($source->value() === '/var/run/docker.sock') {
                        $volume = $source->value().':'.$target->value();
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    } elseif ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                        $volume = $source->value().':'.$target->value();
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    } else {
                        if ((int) $resource->compose_parsing_version >= 4) {
                            $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        } else {
                            $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        }
                        $source = replaceLocalSource($source, $mainDirectory);
                        if ($isPullRequest) {
                            $source = addPreviewDeploymentSuffix($source, $pull_request_id);
                        }
                        LocalFileVolume::updateOrCreate(
                            [
                                'mount_path' => $target,
                                'resource_id' => $originalResource->id,
                                'resource_type' => get_class($originalResource),
                            ],
                            [
                                'fs_path' => $source,
                                'mount_path' => $target,
                                'content' => $content,
                                'is_directory' => $isDirectory,
                                'resource_id' => $originalResource->id,
                                'resource_type' => get_class($originalResource),
                            ]
                        );
                        if (isDev()) {
                            if ((int) $resource->compose_parsing_version >= 4) {
                                $source = $source->replace($mainDirectory, '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/applications/'.$uuid);
                            } else {
                                $source = $source->replace($mainDirectory, '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/applications/'.$uuid);
                            }
                        }
                        $volume = "$source:$target";
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    }
                } elseif ($type->value() === 'volume') {
                    if ($topLevel->get('volumes')->has($source->value())) {
                        $temp = $topLevel->get('volumes')->get($source->value());
                        if (data_get($temp, 'driver_opts.type') === 'cifs') {
                            continue;
                        }
                        if (data_get($temp, 'driver_opts.type') === 'nfs') {
                            continue;
                        }
                    }
                    $slugWithoutUuid = Str::slug($source, '-');
                    $name = "{$uuid}_{$slugWithoutUuid}";

                    if ($isPullRequest) {
                        $name = addPreviewDeploymentSuffix($name, $pull_request_id);
                    }
                    if (is_string($volume)) {
                        $parsed = parseDockerVolumeString($volume);
                        $source = $parsed['source'];
                        $target = $parsed['target'];
                        $source = $name;
                        $volume = "$source:$target";
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    } elseif (is_array($volume)) {
                        data_set($volume, 'source', $name);
                    }
                    $topLevel->get('volumes')->put($name, [
                        'name' => $name,
                    ]);
                    LocalPersistentVolume::updateOrCreate(
                        [
                            'name' => $name,
                            'resource_id' => $originalResource->id,
                            'resource_type' => get_class($originalResource),
                        ],
                        [
                            'name' => $name,
                            'mount_path' => $target,
                            'resource_id' => $originalResource->id,
                            'resource_type' => get_class($originalResource),
                        ]
                    );
                }
                dispatch(new ServerFilesFromServerJob($originalResource));
                $volumesParsed->put($index, $volume);
            }
        }

        if ($depends_on?->count() > 0) {
            if ($isPullRequest) {
                $newDependsOn = collect([]);
                $depends_on->each(function ($dependency, $condition) use ($pullRequestId, $newDependsOn) {
                    if (is_numeric($condition)) {
                        $dependency = addPreviewDeploymentSuffix($dependency, $pullRequestId);

                        $newDependsOn->put($condition, $dependency);
                    } else {
                        $condition = addPreviewDeploymentSuffix($condition, $pullRequestId);
                        $newDependsOn->put($condition, $dependency);
                    }
                });
                $depends_on = $newDependsOn;
            }
        }
        if (! $use_network_mode) {
            if ($topLevel->get('networks')?->count() > 0) {
                foreach ($topLevel->get('networks') as $networkName => $network) {
                    if ($networkName === 'default') {
                        continue;
                    }
                    // ignore aliases
                    if ($network['aliases'] ?? false) {
                        continue;
                    }
                    $networkExists = $networks->contains(function ($value, $key) use ($networkName) {
                        return $value == $networkName || $key == $networkName;
                    });
                    if (! $networkExists) {
                        $networks->put($networkName, null);
                    }
                }
            }
            $baseNetworkExists = $networks->contains(function ($value, $_) use ($baseNetwork) {
                return $value == $baseNetwork;
            });
            if (! $baseNetworkExists) {
                foreach ($baseNetwork as $network) {
                    $topLevel->get('networks')->put($network, [
                        'name' => $network,
                        'external' => true,
                    ]);
                }
            }
        }

        // Collect/create/update ports
        $collectedPorts = collect([]);
        if ($ports->count() > 0) {
            foreach ($ports as $sport) {
                if (is_string($sport) || is_numeric($sport)) {
                    $collectedPorts->push($sport);
                }
                if (is_array($sport)) {
                    $target = data_get($sport, 'target');
                    $published = data_get($sport, 'published');
                    $protocol = data_get($sport, 'protocol');
                    $collectedPorts->push("$target:$published/$protocol");
                }
            }
        }

        $networks_temp = collect();

        if (! $use_network_mode) {
            foreach ($networks as $key => $network) {
                if (gettype($network) === 'string') {
                    // networks:
                    //  - appwrite
                    $networks_temp->put($network, null);
                } elseif (gettype($network) === 'array') {
                    // networks:
                    //   default:
                    //     ipv4_address: 192.168.203.254
                    $networks_temp->put($key, $network);
                }
            }
            foreach ($baseNetwork as $key => $network) {
                $networks_temp->put($network, null);
            }

            if (data_get($resource, 'settings.connect_to_docker_network')) {
                $network = $resource->destination->network;
                $networks_temp->put($network, null);
                $topLevel->get('networks')->put($network, [
                    'name' => $network,
                    'external' => true,
                ]);
            }
        }

        $normalEnvironments = $environment->diffKeys($allMagicEnvironments);
        $normalEnvironments = $normalEnvironments->filter(function ($value, $key) {
            return ! str($value)->startsWith('SERVICE_');
        });
        foreach ($normalEnvironments as $key => $value) {
            $key = str($key);
            $value = str($value);
            $originalValue = $value;
            $parsedValue = replaceVariables($value);
            if ($value->startsWith('$SERVICE_')) {
                $resource->environment_variables()->firstOrCreate([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $value,
                    'is_preview' => false,
                ]);

                continue;
            }
            if (! $value->startsWith('$')) {
                continue;
            }
            if ($key->value() === $parsedValue->value()) {
                $value = null;
                $resource->environment_variables()->firstOrCreate([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $value,
                    'is_preview' => false,
                ]);
            } else {
                if ($value->startsWith('$')) {
                    $isRequired = false;
                    if ($value->contains(':-')) {
                        $value = replaceVariables($value);
                        $key = $value->before(':');
                        $value = $value->after(':-');
                    } elseif ($value->contains('-')) {
                        $value = replaceVariables($value);

                        $key = $value->before('-');
                        $value = $value->after('-');
                    } elseif ($value->contains(':?')) {
                        $value = replaceVariables($value);

                        $key = $value->before(':');
                        $value = $value->after(':?');
                        $isRequired = true;
                    } elseif ($value->contains('?')) {
                        $value = replaceVariables($value);

                        $key = $value->before('?');
                        $value = $value->after('?');
                        $isRequired = true;
                    }
                    if ($originalValue->value() === $value->value()) {
                        // This means the variable does not have a default value, so it needs to be created in Coolify
                        $parsedKeyValue = replaceVariables($value);
                        $resource->environment_variables()->firstOrCreate([
                            'key' => $parsedKeyValue,
                            'resourceable_type' => get_class($resource),
                            'resourceable_id' => $resource->id,
                        ], [
                            'is_preview' => false,
                            'is_required' => $isRequired,
                        ]);
                        // Add the variable to the environment so it will be shown in the deployable compose file
                        $environment[$parsedKeyValue->value()] = $value;

                        continue;
                    }
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $value,
                        'is_preview' => false,
                        'is_required' => $isRequired,
                    ]);
                }
            }
        }
        $branch = $originalResource->git_branch;
        if ($pullRequestId !== 0) {
            $branch = "pull/{$pullRequestId}/head";
        }
        if ($originalResource->environment_variables->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
            $coolifyEnvironments->put('COOLIFY_BRANCH', "\"{$branch}\"");
        }

        // Add COOLIFY_RESOURCE_UUID to environment
        if ($resource->environment_variables->where('key', 'COOLIFY_RESOURCE_UUID')->isEmpty()) {
            $coolifyEnvironments->put('COOLIFY_RESOURCE_UUID', "{$resource->uuid}");
        }

        // Add COOLIFY_CONTAINER_NAME to environment
        if ($resource->environment_variables->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
            $coolifyEnvironments->put('COOLIFY_CONTAINER_NAME', "{$containerName}");
        }

        if ($isPullRequest) {
            $preview = $resource->previews()->find($preview_id);
            $domains = collect(json_decode(data_get($preview, 'docker_compose_domains'))) ?? collect([]);
        } else {
            $domains = collect(json_decode(data_get($resource, 'docker_compose_domains'))) ?? collect([]);
        }

        // Only process domains for dockercompose applications to prevent SERVICE variable recreation
        if ($resource->build_pack !== 'dockercompose') {
            $domains = collect([]);
        }
        $changedServiceName = str($serviceName)->replace('-', '_')->replace('.', '_')->value();
        $fqdns = data_get($domains, "$changedServiceName.domain");
        // Generate SERVICE_FQDN & SERVICE_URL for dockercompose
        if ($resource->build_pack === 'dockercompose') {
            foreach ($domains as $forServiceName => $domain) {
                $parsedDomain = data_get($domain, 'domain');
                $serviceNameFormatted = str($serviceName)->upper()->replace('-', '_')->replace('.', '_');

                if (filled($parsedDomain)) {
                    $parsedDomain = str($parsedDomain)->explode(',')->first();
                    $coolifyUrl = Url::fromString($parsedDomain);
                    $coolifyScheme = $coolifyUrl->getScheme();
                    $coolifyFqdn = $coolifyUrl->getHost();
                    $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                    $coolifyEnvironments->put('SERVICE_URL_'.str($forServiceName)->upper()->replace('-', '_')->replace('.', '_'), $coolifyUrl->__toString());
                    $coolifyEnvironments->put('SERVICE_FQDN_'.str($forServiceName)->upper()->replace('-', '_')->replace('.', '_'), $coolifyFqdn);
                    $resource->environment_variables()->updateOrCreate([
                        'resourceable_type' => Application::class,
                        'resourceable_id' => $resource->id,
                        'key' => 'SERVICE_URL_'.str($forServiceName)->upper()->replace('-', '_')->replace('.', '_'),
                    ], [
                        'value' => $coolifyUrl->__toString(),
                        'is_preview' => false,
                    ]);
                    $resource->environment_variables()->updateOrCreate([
                        'resourceable_type' => Application::class,
                        'resourceable_id' => $resource->id,
                        'key' => 'SERVICE_FQDN_'.str($forServiceName)->upper()->replace('-', '_')->replace('.', '_'),
                    ], [
                        'value' => $coolifyFqdn,
                        'is_preview' => false,
                    ]);
                } else {
                    $resource->environment_variables()->where('resourceable_type', Application::class)
                        ->where('resourceable_id', $resource->id)
                        ->where('key', 'LIKE', "SERVICE_FQDN_{$serviceNameFormatted}%")
                        ->update([
                            'value' => null,
                        ]);
                    $resource->environment_variables()->where('resourceable_type', Application::class)
                        ->where('resourceable_id', $resource->id)
                        ->where('key', 'LIKE', "SERVICE_URL_{$serviceNameFormatted}%")
                        ->update([
                            'value' => null,
                        ]);
                }
            }
        }
        // If the domain is set, we need to generate the FQDNs for the preview
        if (filled($fqdns)) {
            $fqdns = str($fqdns)->explode(',');
            if ($isPullRequest) {
                $preview = $resource->previews()->find($preview_id);
                $docker_compose_domains = collect(json_decode(data_get($preview, 'docker_compose_domains')));
                if ($docker_compose_domains->count() > 0) {
                    $found_fqdn = data_get($docker_compose_domains, "$changedServiceName.domain");
                    if ($found_fqdn) {
                        $fqdns = collect($found_fqdn);
                    } else {
                        $fqdns = collect([]);
                    }
                } else {
                    $fqdns = $fqdns->map(function ($fqdn) use ($pullRequestId, $resource) {
                        $preview = ApplicationPreview::findPreviewByApplicationAndPullId($resource->id, $pullRequestId);
                        $url = Url::fromString($fqdn);
                        $template = $resource->preview_url_template;
                        $host = $url->getHost();
                        $schema = $url->getScheme();
                        $portInt = $url->getPort();
                        $port = $portInt !== null ? ':'.$portInt : '';
                        $random = new Cuid2;
                        $preview_fqdn = str_replace('{{random}}', $random, $template);
                        $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                        $preview_fqdn = str_replace('{{pr_id}}', $pullRequestId, $preview_fqdn);
                        $preview_fqdn = "$schema://$preview_fqdn{$port}";
                        $preview->fqdn = $preview_fqdn;
                        $preview->save();

                        return $preview_fqdn;
                    });
                }
            }
        }
        $defaultLabels = defaultLabels(
            id: $resource->id,
            name: $containerName,
            projectName: $resource->project()->name,
            resourceName: $resource->name,
            pull_request_id: $pullRequestId,
            type: 'application',
            environment: $resource->environment->name,
        );

        $isDatabase = isDatabaseImage($image, $service);
        // Add COOLIFY_FQDN & COOLIFY_URL to environment
        if (! $isDatabase && $fqdns instanceof Collection && $fqdns->count() > 0) {
            $fqdnsWithoutPort = $fqdns->map(function ($fqdn) {
                return str($fqdn)->after('://')->before(':')->prepend(str($fqdn)->before('://')->append('://'));
            });
            $coolifyEnvironments->put('COOLIFY_URL', $fqdnsWithoutPort->implode(','));

            $urls = $fqdns->map(function ($fqdn) {
                return str($fqdn)->replace('http://', '')->replace('https://', '')->before(':');
            });
            $coolifyEnvironments->put('COOLIFY_FQDN', $urls->implode(','));
        }
        add_coolify_default_environment_variables($resource, $coolifyEnvironments, $resource->environment_variables);
        if ($environment->count() > 0) {
            $environment = $environment->filter(function ($value, $key) {
                return ! str($key)->startsWith('SERVICE_FQDN_');
            })->map(function ($value, $key) use ($resource) {
                // Preserve empty strings and null values with correct Docker Compose semantics:
                // - Empty string: Variable is set to "" (e.g., HTTP_PROXY="" means "no proxy")
                // - Null: Variable is unset/removed from container environment (may inherit from host)
                if ($value === null) {
                    // User explicitly wants variable unset - respect that
                    // NEVER override from database - null means "inherit from environment"
                    // Keep as null (will be excluded from container environment)
                } elseif ($value === '') {
                    // Empty string - allow database override for backward compatibility
                    $dbEnv = $resource->environment_variables()->where('key', $key)->first();
                    // Only use database override if it exists AND has a non-empty value
                    if ($dbEnv && str($dbEnv->value)->isNotEmpty()) {
                        $value = $dbEnv->value;
                    }
                    // Otherwise keep empty string as-is
                }

                return $value;
            });
        }
        $serviceLabels = $labels->merge($defaultLabels);
        if ($serviceLabels->count() > 0) {
            $isContainerLabelEscapeEnabled = data_get($resource, 'settings.is_container_label_escape_enabled');
            if ($isContainerLabelEscapeEnabled) {
                $serviceLabels = $serviceLabels->map(function ($value, $key) {
                    return escapeDollarSign($value);
                });
            }
        }
        if (! $isDatabase && $fqdns instanceof Collection && $fqdns->count() > 0) {
            $shouldGenerateLabelsExactly = $resource->destination->server->settings->generate_exact_labels;
            $uuid = $resource->uuid;
            $network = data_get($resource, 'destination.network');
            if ($isPullRequest) {
                $uuid = "{$resource->uuid}-{$pullRequestId}";
            }
            if ($isPullRequest) {
                $network = "{$resource->destination->network}-{$pullRequestId}";
            }
            if ($shouldGenerateLabelsExactly) {
                switch ($server->proxyType()) {
                    case ProxyTypes::TRAEFIK->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image
                        ));
                        break;
                    case ProxyTypes::CADDY->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $network,
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image,
                            predefinedPort: $predefinedPort
                        ));
                        break;
                }
            } else {
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: true,
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image
                ));
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                    network: $network,
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: true,
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image,
                    predefinedPort: $predefinedPort
                ));
            }
        }
        data_forget($service, 'volumes.*.content');
        data_forget($service, 'volumes.*.isDirectory');
        data_forget($service, 'volumes.*.is_directory');
        data_forget($service, 'exclude_from_hc');

        $volumesParsed = $volumesParsed->map(function ($volume) {
            data_forget($volume, 'content');
            data_forget($volume, 'is_directory');
            data_forget($volume, 'isDirectory');

            return $volume;
        });

        $payload = collect($service)->merge([
            'container_name' => $containerName,
            'restart' => $restart->value(),
            'labels' => $serviceLabels,
        ]);
        if (! $use_network_mode) {
            $payload['networks'] = $networks_temp;
        }
        if ($ports->count() > 0) {
            $payload['ports'] = $ports;
        }
        if ($volumesParsed->count() > 0) {
            $payload['volumes'] = $volumesParsed;
        }
        if ($environment->count() > 0 || $coolifyEnvironments->count() > 0) {
            $payload['environment'] = $environment->merge($coolifyEnvironments)->merge($serviceNameEnvironments);
        }
        if ($logging) {
            $payload['logging'] = $logging;
        }
        if ($depends_on->count() > 0) {
            $payload['depends_on'] = $depends_on;
        }
        // Auto-inject .env file so Coolify environment variables are available inside containers
        // This makes Applications behave consistently with manual .env file usage
        $existingEnvFiles = data_get($service, 'env_file');
        $envFiles = collect(is_null($existingEnvFiles) ? [] : (is_array($existingEnvFiles) ? $existingEnvFiles : [$existingEnvFiles]))
            ->push('.env')
            ->unique()
            ->values();

        $payload['env_file'] = $envFiles;

        // Inject commit-based image tag for services with build directive (for rollback support)
        // Only inject if service has build but no explicit image defined
        $hasBuild = data_get($service, 'build') !== null;
        $hasImage = data_get($service, 'image') !== null;
        if ($hasBuild && ! $hasImage && $commit) {
            $imageTag = str($commit)->substr(0, 128)->value();
            if ($isPullRequest) {
                $imageTag = "pr-{$pullRequestId}";
            }
            $imageRepo = "{$uuid}_{$serviceName}";
            $payload['image'] = "{$imageRepo}:{$imageTag}";
        }

        if ($isPullRequest) {
            $serviceName = addPreviewDeploymentSuffix($serviceName, $pullRequestId);
        }

        $parsedServices->put($serviceName, $payload);
    }
    $topLevel->put('services', $parsedServices);

    $customOrder = ['services', 'volumes', 'networks', 'configs', 'secrets'];

    $topLevel = $topLevel->sortBy(function ($value, $key) use ($customOrder) {
        return array_search($key, $customOrder);
    });

    // Remove empty top-level sections (volumes, networks, configs, secrets)
    // Keep only non-empty sections to match Docker Compose best practices
    $topLevel = $topLevel->filter(function ($value, $key) {
        // Always keep 'services' section
        if ($key === 'services') {
            return true;
        }

        // Keep section only if it has content
        return $value instanceof Collection ? $value->isNotEmpty() : ! empty($value);
    });

    $cleanedCompose = Yaml::dump(convertToArray($topLevel), 10, 2);
    $resource->docker_compose = $cleanedCompose;

    // Update docker_compose_raw to remove content: from volumes only
    // This keeps the original user input clean while preventing content reapplication
    // Parse the original compose again to create a clean version without Coolify additions
    try {
        $originalYaml = Yaml::parse($originalCompose);
        // Remove content, isDirectory, and is_directory from all volume definitions
        if (isset($originalYaml['services'])) {
            foreach ($originalYaml['services'] as $serviceName => &$service) {
                if (isset($service['volumes'])) {
                    foreach ($service['volumes'] as $key => &$volume) {
                        if (is_array($volume)) {
                            unset($volume['content']);
                            unset($volume['isDirectory']);
                            unset($volume['is_directory']);
                        }
                    }
                }
            }
        }
        $resource->docker_compose_raw = Yaml::dump($originalYaml, 10, 2);
    } catch (\Exception $e) {
        // If parsing fails, keep the original docker_compose_raw unchanged
        ray('Failed to update docker_compose_raw in applicationParser: '.$e->getMessage());
    }

    data_forget($resource, 'environment_variables');
    data_forget($resource, 'environment_variables_preview');
    $resource->save();

    return $topLevel;
}

function serviceParser(Service $resource): Collection
{
    $uuid = data_get($resource, 'uuid');
    $compose = data_get($resource, 'docker_compose_raw');
    // Store original compose for later use to update docker_compose_raw with content removed
    $originalCompose = $compose;
    if (! $compose) {
        return collect([]);
    }

    $server = data_get($resource, 'server');
    $allServices = get_service_templates();

    try {
        $yaml = Yaml::parse($compose);
    } catch (\Exception) {
        return collect([]);
    }
    $services = data_get($yaml, 'services', collect([]));
    $topLevel = collect([
        'volumes' => collect(data_get($yaml, 'volumes', [])),
        'networks' => collect(data_get($yaml, 'networks', [])),
        'configs' => collect(data_get($yaml, 'configs', [])),
        'secrets' => collect(data_get($yaml, 'secrets', [])),
    ]);
    // If there are predefined volumes, make sure they are not null
    if ($topLevel->get('volumes')->count() > 0) {
        $temp = collect([]);
        foreach ($topLevel['volumes'] as $volumeName => $volume) {
            if (is_null($volume)) {
                continue;
            }
            $temp->put($volumeName, $volume);
        }
        $topLevel['volumes'] = $temp;
    }
    // Get the base docker network
    $baseNetwork = collect([$uuid]);

    $parsedServices = collect([]);

    // Generate SERVICE_NAME variables for docker compose services
    $serviceNameEnvironments = generateDockerComposeServiceName($services);

    $allMagicEnvironments = collect([]);
    // Presave services
    foreach ($services as $serviceName => $service) {
        // Validate service name for command injection
        try {
            validateShellSafePath($serviceName, 'service name');
        } catch (\Exception $e) {
            throw new \Exception(
                'Invalid Docker Compose service name: '.$e->getMessage().
                ' Service names must not contain shell metacharacters.'
            );
        }

        $image = data_get_str($service, 'image');

        // Check for manually migrated services first (respects user's conversion choice)
        $migratedApp = ServiceApplication::where('name', $serviceName)
            ->where('service_id', $resource->id)
            ->where('is_migrated', true)
            ->first();
        $migratedDb = ServiceDatabase::where('name', $serviceName)
            ->where('service_id', $resource->id)
            ->where('is_migrated', true)
            ->first();

        if ($migratedApp || $migratedDb) {
            // Use the migrated service type, ignoring image detection
            $isDatabase = (bool) $migratedDb;
            $savedService = $migratedApp ?: $migratedDb;
        } else {
            // Use image detection for non-migrated services
            $isDatabase = isDatabaseImage($image, $service);
            if ($isDatabase) {
                $applicationFound = ServiceApplication::where('name', $serviceName)->where('service_id', $resource->id)->first();
                if ($applicationFound) {
                    $savedService = $applicationFound;
                } else {
                    $savedService = ServiceDatabase::firstOrCreate([
                        'name' => $serviceName,
                        'service_id' => $resource->id,
                    ]);
                }
            } else {
                $savedService = ServiceApplication::firstOrCreate([
                    'name' => $serviceName,
                    'service_id' => $resource->id,
                ]);
            }
        }
        // Update image if it changed
        if ($savedService->image !== $image) {
            $savedService->image = $image;
            $savedService->save();
        }
    }
    foreach ($services as $serviceName => $service) {
        $predefinedPort = null;
        $magicEnvironments = collect([]);
        $image = data_get_str($service, 'image');
        $environment = collect(data_get($service, 'environment', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        // Check for manually migrated services first (respects user's conversion choice)
        $migratedApp = ServiceApplication::where('name', $serviceName)
            ->where('service_id', $resource->id)
            ->where('is_migrated', true)
            ->first();
        $migratedDb = ServiceDatabase::where('name', $serviceName)
            ->where('service_id', $resource->id)
            ->where('is_migrated', true)
            ->first();

        if ($migratedApp || $migratedDb) {
            // Use the migrated service type, ignoring image detection
            $isDatabase = (bool) $migratedDb;
        } else {
            // Use image detection for non-migrated services
            $isDatabase = isDatabaseImage($image, $service);
        }

        $containerName = "$serviceName-{$resource->uuid}";

        if ($serviceName === 'registry') {
            $tempServiceName = 'docker-registry';
        } else {
            $tempServiceName = $serviceName;
        }
        if (str(data_get($service, 'image'))->contains('glitchtip')) {
            $tempServiceName = 'glitchtip';
        }
        if ($serviceName === 'supabase-kong') {
            $tempServiceName = 'supabase';
        }
        $serviceDefinition = data_get($allServices, $tempServiceName);
        $predefinedPort = data_get($serviceDefinition, 'port');
        if ($serviceName === 'plausible') {
            $predefinedPort = '8000';
        }

        if ($migratedApp || $migratedDb) {
            // Use the already determined migrated service
            $savedService = $migratedApp ?: $migratedDb;
        } elseif ($isDatabase) {
            $applicationFound = ServiceApplication::where('name', $serviceName)->where('service_id', $resource->id)->first();
            if ($applicationFound) {
                $savedService = $applicationFound;
            } else {
                $savedService = ServiceDatabase::firstOrCreate([
                    'name' => $serviceName,
                    'service_id' => $resource->id,
                ]);
            }
        } else {
            $savedService = ServiceApplication::firstOrCreate([
                'name' => $serviceName,
                'service_id' => $resource->id,
            ], [
                'is_gzip_enabled' => true,
            ]);
        }
        // Check if image changed
        if ($savedService->image !== $image) {
            $savedService->image = $image;
            $savedService->save();
        }
        // Pocketbase does not need gzip for SSE.
        if (str($savedService->image)->contains('pocketbase') && $savedService->is_gzip_enabled) {
            $savedService->is_gzip_enabled = false;
            $savedService->save();
        }

        $environment = collect(data_get($service, 'environment', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        // convert environment variables to one format
        $environment = convertToKeyValueCollection($environment);

        // Add Coolify defined environments
        $allEnvironments = $resource->environment_variables()->get(['key', 'value']);

        $allEnvironments = $allEnvironments->mapWithKeys(function ($item) {
            return [$item['key'] => $item['value']];
        });
        // filter and add magic environments
        foreach ($environment as $key => $value) {
            // Get all SERVICE_ variables from keys and values
            $key = str($key);
            $value = str($value);
            $regex = '/\$(\{?([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\}?)/';
            preg_match_all($regex, $value, $valueMatches);
            if (count($valueMatches[1]) > 0) {
                foreach ($valueMatches[1] as $match) {
                    $match = replaceVariables($match);
                    if ($match->startsWith('SERVICE_')) {
                        if ($magicEnvironments->has($match->value())) {
                            continue;
                        }
                        $magicEnvironments->put($match->value(), '');
                    }
                }
            }
            // Get magic environments where we need to preset the FQDN / URL
            if ($key->startsWith('SERVICE_FQDN_') || $key->startsWith('SERVICE_URL_')) {
                // SERVICE_FQDN_APP or SERVICE_FQDN_APP_3000 or SERVICE_URL_APP or SERVICE_URL_APP_3000
                // ALWAYS create BOTH SERVICE_URL and SERVICE_FQDN pairs regardless of which one is in template
                $parsed = parseServiceEnvironmentVariable($key->value());

                // Extract service name preserving original case from template
                $strKey = str($key->value());
                if ($parsed['has_port']) {
                    if ($strKey->startsWith('SERVICE_URL_')) {
                        $serviceName = $strKey->after('SERVICE_URL_')->beforeLast('_')->value();
                    } elseif ($strKey->startsWith('SERVICE_FQDN_')) {
                        $serviceName = $strKey->after('SERVICE_FQDN_')->beforeLast('_')->value();
                    } else {
                        continue;
                    }
                } else {
                    if ($strKey->startsWith('SERVICE_URL_')) {
                        $serviceName = $strKey->after('SERVICE_URL_')->value();
                    } elseif ($strKey->startsWith('SERVICE_FQDN_')) {
                        $serviceName = $strKey->after('SERVICE_FQDN_')->value();
                    } else {
                        continue;
                    }
                }

                $port = $parsed['port'];
                $fqdnFor = $parsed['service_name'];

                // Only ServiceApplication has fqdn column, ServiceDatabase does not
                $isServiceApplication = $savedService instanceof ServiceApplication;

                if ($isServiceApplication && blank($savedService->fqdn)) {
                    $fqdn = generateFqdn(server: $server, random: "$fqdnFor-$uuid", parserVersion: $resource->compose_parsing_version);
                    $url = generateUrl($server, "$fqdnFor-$uuid");
                } elseif ($isServiceApplication) {
                    $fqdn = str($savedService->fqdn)->after('://')->before(':')->prepend(str($savedService->fqdn)->before('://')->append('://'))->value();
                    $url = str($savedService->fqdn)->after('://')->before(':')->prepend(str($savedService->fqdn)->before('://')->append('://'))->value();
                } else {
                    // For ServiceDatabase, generate fqdn/url without saving to the model
                    $fqdn = generateFqdn(server: $server, random: "$fqdnFor-$uuid", parserVersion: $resource->compose_parsing_version);
                    $url = generateUrl($server, "$fqdnFor-$uuid");
                }

                // IMPORTANT: SERVICE_FQDN env vars should NOT contain scheme (host only)
                // But $fqdn variable itself may contain scheme (used for database domain field)
                // Strip scheme for environment variable values
                $fqdnValueForEnv = str($fqdn)->after('://')->value();

                if ($value && get_class($value) === \Illuminate\Support\Stringable::class && $value->startsWith('/')) {
                    $path = $value->value();
                    if ($path !== '/') {
                        // Only add path if it's not already present (prevents duplication on subsequent parse() calls)
                        if (! str($fqdn)->endsWith($path)) {
                            $fqdn = "$fqdn$path";
                        }
                        if (! str($url)->endsWith($path)) {
                            $url = "$url$path";
                        }
                        if (! str($fqdnValueForEnv)->endsWith($path)) {
                            $fqdnValueForEnv = "$fqdnValueForEnv$path";
                        }
                    }
                }

                $urlWithPort = $url;
                $fqdnValueForEnvWithPort = $fqdnValueForEnv;
                if ($fqdn && $port) {
                    $fqdnValueForEnvWithPort = "$fqdnValueForEnv:$port";
                }
                if ($url && $port) {
                    $urlWithPort = "$url:$port";
                }

                // Only save fqdn to ServiceApplication, not ServiceDatabase
                if ($isServiceApplication && is_null($savedService->fqdn)) {
                    // Save URL (with scheme) to database, not FQDN
                    if ((int) $resource->compose_parsing_version >= 5 && version_compare(config('constants.coolify.version'), '4.0.0-beta.420.7', '>=')) {
                        $savedService->fqdn = $urlWithPort;
                    } else {
                        $savedService->fqdn = $urlWithPort;
                    }
                    $savedService->save();
                }

                // ALWAYS create BOTH base SERVICE_URL and SERVICE_FQDN pairs (without port)
                $resource->environment_variables()->updateOrCreate([
                    'key' => "SERVICE_FQDN_{$serviceName}",
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $fqdnValueForEnv,
                    'is_preview' => false,
                ]);

                $resource->environment_variables()->updateOrCreate([
                    'key' => "SERVICE_URL_{$serviceName}",
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $url,
                    'is_preview' => false,
                ]);

                // For port-specific variables, ALSO create port-specific pairs
                // If template variable has port, create both URL and FQDN with port suffix
                if ($parsed['has_port'] && $port) {
                    $resource->environment_variables()->updateOrCreate([
                        'key' => "SERVICE_FQDN_{$serviceName}_{$port}",
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdnValueForEnvWithPort,
                        'is_preview' => false,
                    ]);

                    $resource->environment_variables()->updateOrCreate([
                        'key' => "SERVICE_URL_{$serviceName}_{$port}",
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $urlWithPort,
                        'is_preview' => false,
                    ]);
                }
            }
        }
        $allMagicEnvironments = $allMagicEnvironments->merge($magicEnvironments);
        if ($magicEnvironments->count() > 0) {
            foreach ($magicEnvironments as $key => $value) {
                $key = str($key);
                $value = replaceVariables($value);
                $command = parseCommandFromMagicEnvVariable($key);
                if ($command->value() === 'FQDN') {
                    $fqdnFor = $key->after('SERVICE_FQDN_')->lower()->value();
                    $fqdn = generateFqdn(server: $server, random: str($fqdnFor)->replace('_', '-')->value()."-$uuid", parserVersion: $resource->compose_parsing_version);
                    $url = generateUrl(server: $server, random: str($fqdnFor)->replace('_', '-')->value()."-$uuid");

                    $envExists = $resource->environment_variables()->where('key', $key->value())->first();
                    // Also check if a port-suffixed version exists (e.g., SERVICE_FQDN_UMAMI_3000)
                    $portSuffixedExists = $resource->environment_variables()
                        ->where('key', 'LIKE', $key->value().'_%')
                        ->whereRaw('key ~ ?', ['^'.$key->value().'_[0-9]+$'])
                        ->exists();
                    $serviceExists = ServiceApplication::where('name', str($fqdnFor)->replace('_', '-')->value())->where('service_id', $resource->id)->first();
                    // Check if FQDN already has a port set (contains ':' after the domain)
                    $fqdnHasPort = $serviceExists && str($serviceExists->fqdn)->contains(':') && str($serviceExists->fqdn)->afterLast(':')->isMatch('/^\d+$/');
                    // Only set FQDN if it's for the current service being processed (prevent race conditions)
                    $isCurrentService = $serviceExists && $serviceExists->id === $savedService->id;
                    if (! $envExists && ! $portSuffixedExists && ! $fqdnHasPort && $isCurrentService && (data_get($serviceExists, 'name') === str($fqdnFor)->replace('_', '-')->value())) {
                        // Save URL otherwise it won't work.
                        $serviceExists->fqdn = $url;
                        $serviceExists->save();
                    }
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_preview' => false,
                    ]);

                } elseif ($command->value() === 'URL') {
                    $urlFor = $key->after('SERVICE_URL_')->lower()->value();
                    $url = generateUrl(server: $server, random: str($urlFor)->replace('_', '-')->value()."-$uuid");

                    $envExists = $resource->environment_variables()->where('key', $key->value())->first();
                    // Also check if a port-suffixed version exists (e.g., SERVICE_URL_DASHBOARD_6791)
                    $portSuffixedExists = $resource->environment_variables()
                        ->where('key', 'LIKE', $key->value().'_%')
                        ->whereRaw('key ~ ?', ['^'.$key->value().'_[0-9]+$'])
                        ->exists();
                    $serviceExists = ServiceApplication::where('name', str($urlFor)->replace('_', '-')->value())->where('service_id', $resource->id)->first();
                    // Check if FQDN already has a port set (contains ':' after the domain)
                    $fqdnHasPort = $serviceExists && str($serviceExists->fqdn)->contains(':') && str($serviceExists->fqdn)->afterLast(':')->isMatch('/^\d+$/');
                    // Only set FQDN if it's for the current service being processed (prevent race conditions)
                    $isCurrentService = $serviceExists && $serviceExists->id === $savedService->id;
                    if (! $envExists && ! $portSuffixedExists && ! $fqdnHasPort && $isCurrentService && (data_get($serviceExists, 'name') === str($urlFor)->replace('_', '-')->value())) {
                        $serviceExists->fqdn = $url;
                        $serviceExists->save();
                    }
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_preview' => false,
                    ]);

                } else {
                    $value = generateEnvValue($command, $resource);
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $value,
                        'is_preview' => false,
                    ]);
                }
            }
        }
    }

    $serviceAppsLogDrainEnabledMap = $resource->applications()->get()->keyBy('name')->map(function ($app) {
        return $app->isLogDrainEnabled();
    });

    // Parse the rest of the services
    foreach ($services as $serviceName => $service) {
        $image = data_get_str($service, 'image');
        $restart = data_get_str($service, 'restart', RESTART_MODE);
        $logging = data_get($service, 'logging');

        if ($server->isLogDrainEnabled()) {
            if ($serviceAppsLogDrainEnabledMap->get($serviceName)) {
                $logging = generate_fluentd_configuration();
            }
        }
        $volumes = collect(data_get($service, 'volumes', []));
        $networks = collect(data_get($service, 'networks', []));
        $use_network_mode = data_get($service, 'network_mode') !== null;
        $depends_on = collect(data_get($service, 'depends_on', []));
        $labels = collect(data_get($service, 'labels', []));
        if ($labels->count() > 0) {
            if (isAssociativeArray($labels)) {
                $newLabels = collect([]);
                $labels->each(function ($value, $key) use ($newLabels) {
                    $newLabels->push("$key=$value");
                });
                $labels = $newLabels;
            }
        }
        $environment = collect(data_get($service, 'environment', []));
        $ports = collect(data_get($service, 'ports', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        $environment = convertToKeyValueCollection($environment);
        $coolifyEnvironments = collect([]);

        // Check for manually migrated services first (respects user's conversion choice)
        $migratedApp = ServiceApplication::where('name', $serviceName)
            ->where('service_id', $resource->id)
            ->where('is_migrated', true)
            ->first();
        $migratedDb = ServiceDatabase::where('name', $serviceName)
            ->where('service_id', $resource->id)
            ->where('is_migrated', true)
            ->first();

        if ($migratedApp || $migratedDb) {
            // Use the migrated service type, ignoring image detection
            $isDatabase = (bool) $migratedDb;
            $savedService = $migratedApp ?: $migratedDb;
        } else {
            // Use image detection for non-migrated services
            $isDatabase = isDatabaseImage($image, $service);
        }

        $volumesParsed = collect([]);

        $containerName = "$serviceName-{$resource->uuid}";

        if ($serviceName === 'registry') {
            $tempServiceName = 'docker-registry';
        } else {
            $tempServiceName = $serviceName;
        }
        if (str(data_get($service, 'image'))->contains('glitchtip')) {
            $tempServiceName = 'glitchtip';
        }
        if ($serviceName === 'supabase-kong') {
            $tempServiceName = 'supabase';
        }
        $serviceDefinition = data_get($allServices, $tempServiceName);
        $predefinedPort = data_get($serviceDefinition, 'port');
        if ($serviceName === 'plausible') {
            $predefinedPort = '8000';
        }

        if ($migratedApp || $migratedDb) {
            // Use the already determined migrated service
            $savedService = $migratedApp ?: $migratedDb;
        } elseif ($isDatabase) {
            $applicationFound = ServiceApplication::where('name', $serviceName)->where('service_id', $resource->id)->first();
            if ($applicationFound) {
                $savedService = $applicationFound;
            } else {
                $savedService = ServiceDatabase::firstOrCreate([
                    'name' => $serviceName,
                    'service_id' => $resource->id,
                ]);
            }
        } else {
            $savedService = ServiceApplication::firstOrCreate([
                'name' => $serviceName,
                'service_id' => $resource->id,
            ]);
        }
        $fileStorages = $savedService->fileStorages();
        if ($savedService->image !== $image) {
            $savedService->image = $image;
            $savedService->save();
        }

        $originalResource = $savedService;

        if ($volumes->count() > 0) {
            foreach ($volumes as $index => $volume) {
                $type = null;
                $source = null;
                $target = null;
                $content = null;
                $isDirectory = false;
                if (is_string($volume)) {
                    $parsed = parseDockerVolumeString($volume);
                    $source = $parsed['source'];
                    $target = $parsed['target'];
                    // Mode is available in $parsed['mode'] if needed
                    $foundConfig = $fileStorages->whereMountPath($target)->first();
                    if (sourceIsLocal($source)) {
                        $type = str('bind');
                        if ($foundConfig) {
                            $contentNotNull_temp = data_get($foundConfig, 'content');
                            if ($contentNotNull_temp) {
                                $content = $contentNotNull_temp;
                            }
                            $isDirectory = data_get($foundConfig, 'is_directory');
                        } else {
                            // By default, we cannot determine if the bind is a directory or not, so we set it to directory
                            $isDirectory = true;
                        }
                    } else {
                        $type = str('volume');
                    }
                } elseif (is_array($volume)) {
                    $type = data_get_str($volume, 'type');
                    $source = data_get_str($volume, 'source');
                    $target = data_get_str($volume, 'target');
                    $content = data_get($volume, 'content');
                    $isDirectory = (bool) data_get($volume, 'isDirectory', null) || (bool) data_get($volume, 'is_directory', null);

                    // Validate source and target for command injection (array/long syntax)
                    if ($source !== null && ! empty($source->value())) {
                        $sourceValue = $source->value();
                        // Allow environment variable references and env vars with path concatenation
                        $isSimpleEnvVar = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}$/', $sourceValue);
                        $isEnvVarWithDefault = preg_match('/^\$\{[^}]+:-[^}]*\}$/', $sourceValue);
                        $isEnvVarWithPath = preg_match('/^\$\{[a-zA-Z_][a-zA-Z0-9_]*\}[\/\w\.\-]*$/', $sourceValue);

                        if (! $isSimpleEnvVar && ! $isEnvVarWithDefault && ! $isEnvVarWithPath) {
                            try {
                                validateShellSafePath($sourceValue, 'volume source');
                            } catch (\Exception $e) {
                                throw new \Exception(
                                    'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                    ' Please use safe path names without shell metacharacters.'
                                );
                            }
                        }
                    }
                    if ($target !== null && ! empty($target->value())) {
                        try {
                            validateShellSafePath($target->value(), 'volume target');
                        } catch (\Exception $e) {
                            throw new \Exception(
                                'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                ' Please use safe path names without shell metacharacters.'
                            );
                        }
                    }

                    $foundConfig = $fileStorages->whereMountPath($target)->first();
                    if ($foundConfig) {
                        $contentNotNull_temp = data_get($foundConfig, 'content');
                        if ($contentNotNull_temp) {
                            $content = $contentNotNull_temp;
                        }
                        $isDirectory = data_get($foundConfig, 'is_directory');
                    } else {
                        // if isDirectory is not set (or false) & content is also not set, we assume it is a directory
                        if ((is_null($isDirectory) || ! $isDirectory) && is_null($content)) {
                            $isDirectory = true;
                        }
                    }
                }
                if ($type->value() === 'bind') {
                    if ($source->value() === '/var/run/docker.sock') {
                        $volume = $source->value().':'.$target->value();
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    } elseif ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                        $volume = $source->value().':'.$target->value();
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    } else {
                        if ((int) $resource->compose_parsing_version >= 4) {
                            $mainDirectory = str(base_configuration_dir().'/services/'.$uuid);
                        } else {
                            $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        }
                        $source = replaceLocalSource($source, $mainDirectory);
                        LocalFileVolume::updateOrCreate(
                            [
                                'mount_path' => $target,
                                'resource_id' => $originalResource->id,
                                'resource_type' => get_class($originalResource),
                            ],
                            [
                                'fs_path' => $source,
                                'mount_path' => $target,
                                'content' => $content,
                                'is_directory' => $isDirectory,
                                'resource_id' => $originalResource->id,
                                'resource_type' => get_class($originalResource),
                            ]
                        );
                        if (isDev()) {
                            if ((int) $resource->compose_parsing_version >= 4) {
                                $source = $source->replace($mainDirectory, '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/services/'.$uuid);
                            } else {
                                $source = $source->replace($mainDirectory, '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/applications/'.$uuid);
                            }
                        }
                        $volume = "$source:$target";
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    }
                } elseif ($type->value() === 'volume') {
                    if ($topLevel->get('volumes')->has($source->value())) {
                        $temp = $topLevel->get('volumes')->get($source->value());
                        if (data_get($temp, 'driver_opts.type') === 'cifs') {
                            continue;
                        }
                        if (data_get($temp, 'driver_opts.type') === 'nfs') {
                            continue;
                        }
                    }
                    $slugWithoutUuid = Str::slug($source, '-');
                    $name = "{$uuid}_{$slugWithoutUuid}";

                    if (is_string($volume)) {
                        $parsed = parseDockerVolumeString($volume);
                        $source = $parsed['source'];
                        $target = $parsed['target'];
                        $source = $name;
                        $volume = "$source:$target";
                        if (isset($parsed['mode']) && $parsed['mode']) {
                            $volume .= ':'.$parsed['mode']->value();
                        }
                    } elseif (is_array($volume)) {
                        data_set($volume, 'source', $name);
                    }
                    $topLevel->get('volumes')->put($name, [
                        'name' => $name,
                    ]);
                    LocalPersistentVolume::updateOrCreate(
                        [
                            'name' => $name,
                            'resource_id' => $originalResource->id,
                            'resource_type' => get_class($originalResource),
                        ],
                        [
                            'name' => $name,
                            'mount_path' => $target,
                            'resource_id' => $originalResource->id,
                            'resource_type' => get_class($originalResource),
                        ]
                    );
                }
                dispatch(new ServerFilesFromServerJob($originalResource));
                $volumesParsed->put($index, $volume);
            }
        }

        if (! $use_network_mode) {
            if ($topLevel->get('networks')?->count() > 0) {
                foreach ($topLevel->get('networks') as $networkName => $network) {
                    if ($networkName === 'default') {
                        continue;
                    }
                    // ignore aliases
                    if ($network['aliases'] ?? false) {
                        continue;
                    }
                    $networkExists = $networks->contains(function ($value, $key) use ($networkName) {
                        return $value == $networkName || $key == $networkName;
                    });
                    if (! $networkExists) {
                        $networks->put($networkName, null);
                    }
                }
            }
            $baseNetworkExists = $networks->contains(function ($value, $_) use ($baseNetwork) {
                return $value == $baseNetwork;
            });
            if (! $baseNetworkExists) {
                foreach ($baseNetwork as $network) {
                    $topLevel->get('networks')->put($network, [
                        'name' => $network,
                        'external' => true,
                    ]);
                }
            }
        }

        // Collect/create/update ports
        $collectedPorts = collect([]);
        if ($ports->count() > 0) {
            foreach ($ports as $sport) {
                if (is_string($sport) || is_numeric($sport)) {
                    $collectedPorts->push($sport);
                }
                if (is_array($sport)) {
                    $target = data_get($sport, 'target');
                    $published = data_get($sport, 'published');
                    $protocol = data_get($sport, 'protocol');
                    $collectedPorts->push("$target:$published/$protocol");
                }
            }
        }
        $originalResource->ports = $collectedPorts->implode(',');
        $originalResource->save();

        $networks_temp = collect();

        if (! $use_network_mode) {
            foreach ($networks as $key => $network) {
                if (gettype($network) === 'string') {
                    // networks:
                    //  - appwrite
                    $networks_temp->put($network, null);
                } elseif (gettype($network) === 'array') {
                    // networks:
                    //   default:
                    //     ipv4_address: 192.168.203.254
                    $networks_temp->put($key, $network);
                }
            }
            foreach ($baseNetwork as $key => $network) {
                $networks_temp->put($network, null);
            }
        }

        $normalEnvironments = $environment->diffKeys($allMagicEnvironments);
        $normalEnvironments = $normalEnvironments->filter(function ($value, $key) {
            return ! str($value)->startsWith('SERVICE_');
        });
        foreach ($normalEnvironments as $key => $value) {
            $key = str($key);
            $value = str($value);
            $originalValue = $value;
            $parsedValue = replaceVariables($value);
            if ($parsedValue->startsWith('SERVICE_')) {
                $resource->environment_variables()->firstOrCreate([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $value,
                    'is_preview' => false,
                ]);

                continue;
            }
            if (! $value->startsWith('$')) {
                continue;
            }
            if ($key->value() === $parsedValue->value()) {
                $value = null;
                $resource->environment_variables()->firstOrCreate([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $value,
                    'is_preview' => false,
                ]);
            } else {
                if ($value->startsWith('$')) {
                    $isRequired = false;
                    if ($value->contains(':-')) {
                        $value = replaceVariables($value);
                        $key = $value->before(':');
                        $value = $value->after(':-');
                    } elseif ($value->contains('-')) {
                        $value = replaceVariables($value);

                        $key = $value->before('-');
                        $value = $value->after('-');
                    } elseif ($value->contains(':?')) {
                        $value = replaceVariables($value);

                        $key = $value->before(':');
                        $value = $value->after(':?');
                        $isRequired = true;
                    } elseif ($value->contains('?')) {
                        $value = replaceVariables($value);

                        $key = $value->before('?');
                        $value = $value->after('?');
                        $isRequired = true;
                    }
                    if ($originalValue->value() === $value->value()) {
                        // This means the variable does not have a default value, so it needs to be created in Coolify
                        $parsedKeyValue = replaceVariables($value);
                        $resource->environment_variables()->firstOrCreate([
                            'key' => $parsedKeyValue,
                            'resourceable_type' => get_class($resource),
                            'resourceable_id' => $resource->id,
                        ], [
                            'is_preview' => false,
                            'is_required' => $isRequired,
                        ]);
                        // Add the variable to the environment so it will be shown in the deployable compose file
                        $environment[$parsedKeyValue->value()] = $value;

                        continue;
                    }
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $value,
                        'is_preview' => false,
                        'is_required' => $isRequired,
                    ]);
                }
            }
        }

        // Add COOLIFY_RESOURCE_UUID to environment
        if ($resource->environment_variables->where('key', 'COOLIFY_RESOURCE_UUID')->isEmpty()) {
            $coolifyEnvironments->put('COOLIFY_RESOURCE_UUID', "{$resource->uuid}");
        }

        // Add COOLIFY_CONTAINER_NAME to environment
        if ($resource->environment_variables->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
            $coolifyEnvironments->put('COOLIFY_CONTAINER_NAME', "{$containerName}");
        }

        if ($savedService->serviceType()) {
            $fqdns = generateServiceSpecificFqdns($savedService);
        } else {
            $fqdns = collect(data_get($savedService, 'fqdns'))->filter();
        }

        $defaultLabels = defaultLabels(
            id: $resource->id,
            name: $containerName,
            projectName: $resource->project()->name,
            resourceName: $resource->name,
            type: 'service',
            subType: $isDatabase ? 'database' : 'application',
            subId: $savedService->id,
            subName: $savedService->human_name ?? $savedService->name,
            environment: $resource->environment->name,
        );

        // Add COOLIFY_FQDN & COOLIFY_URL to environment
        if (! $isDatabase && $fqdns instanceof Collection && $fqdns->count() > 0) {
            $fqdnsWithoutPort = $fqdns->map(function ($fqdn) {
                return str($fqdn)->replace('http://', '')->replace('https://', '')->before(':');
            });
            $coolifyEnvironments->put('COOLIFY_FQDN', $fqdnsWithoutPort->implode(','));
            $urls = $fqdns->map(function ($fqdn): Stringable {
                return str($fqdn)->after('://')->before(':')->prepend(str($fqdn)->before('://')->append('://'));
            });
            $coolifyEnvironments->put('COOLIFY_URL', $urls->implode(','));
        }
        add_coolify_default_environment_variables($resource, $coolifyEnvironments, $resource->environment_variables);
        if ($environment->count() > 0) {
            $environment = $environment->filter(function ($value, $key) {
                return ! str($key)->startsWith('SERVICE_FQDN_');
            })->map(function ($value, $key) use ($resource) {
                // Preserve empty strings and null values with correct Docker Compose semantics:
                // - Empty string: Variable is set to "" (e.g., HTTP_PROXY="" means "no proxy")
                // - Null: Variable is unset/removed from container environment (may inherit from host)
                if ($value === null) {
                    // User explicitly wants variable unset - respect that
                    // NEVER override from database - null means "inherit from environment"
                    // Keep as null (will be excluded from container environment)
                } elseif ($value === '') {
                    // Empty string - allow database override for backward compatibility
                    $dbEnv = $resource->environment_variables()->where('key', $key)->first();
                    // Only use database override if it exists AND has a non-empty value
                    if ($dbEnv && str($dbEnv->value)->isNotEmpty()) {
                        $value = $dbEnv->value;
                    }
                    // Otherwise keep empty string as-is
                }

                return $value;
            });
        }
        $serviceLabels = $labels->merge($defaultLabels);
        if ($serviceLabels->count() > 0) {
            $isContainerLabelEscapeEnabled = data_get($resource, 'is_container_label_escape_enabled');
            if ($isContainerLabelEscapeEnabled) {
                $serviceLabels = $serviceLabels->map(function ($value, $key) {
                    return escapeDollarSign($value);
                });
            }
        }
        if (! $isDatabase && $fqdns instanceof Collection && $fqdns->count() > 0) {
            $shouldGenerateLabelsExactly = $resource->server->settings->generate_exact_labels;
            $uuid = $resource->uuid;
            $network = data_get($resource, 'destination.network');
            if ($shouldGenerateLabelsExactly) {
                switch ($server->proxyType()) {
                    case ProxyTypes::TRAEFIK->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image
                        ));
                        break;
                    case ProxyTypes::CADDY->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $network,
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image,
                            predefinedPort: $predefinedPort
                        ));
                        break;
                }
            } else {
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: true,
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image
                ));
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                    network: $network,
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: true,
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image,
                    predefinedPort: $predefinedPort
                ));
            }
        }
        if (data_get($service, 'restart') === 'no' || data_get($service, 'exclude_from_hc')) {
            $savedService->update(['exclude_from_status' => true]);
        }
        data_forget($service, 'volumes.*.content');
        data_forget($service, 'volumes.*.isDirectory');
        data_forget($service, 'volumes.*.is_directory');
        data_forget($service, 'exclude_from_hc');

        $volumesParsed = $volumesParsed->map(function ($volume) {
            data_forget($volume, 'content');
            data_forget($volume, 'is_directory');
            data_forget($volume, 'isDirectory');

            return $volume;
        });

        $payload = collect($service)->merge([
            'container_name' => $containerName,
            'restart' => $restart->value(),
            'labels' => $serviceLabels,
        ]);
        if (! $use_network_mode) {
            $payload['networks'] = $networks_temp;
        }
        if ($ports->count() > 0) {
            $payload['ports'] = $ports;
        }
        if ($volumesParsed->count() > 0) {
            $payload['volumes'] = $volumesParsed;
        }
        if ($environment->count() > 0 || $coolifyEnvironments->count() > 0) {
            $payload['environment'] = $environment->merge($coolifyEnvironments)->merge($serviceNameEnvironments);
        }
        if ($logging) {
            $payload['logging'] = $logging;
        }
        if ($depends_on->count() > 0) {
            $payload['depends_on'] = $depends_on;
        }
        // Auto-inject .env file so Coolify environment variables are available inside containers
        // This makes Services behave consistently with Applications
        $existingEnvFiles = data_get($service, 'env_file');
        $envFiles = collect(is_null($existingEnvFiles) ? [] : (is_array($existingEnvFiles) ? $existingEnvFiles : [$existingEnvFiles]))
            ->push('.env')
            ->unique()
            ->values();

        $payload['env_file'] = $envFiles;

        $parsedServices->put($serviceName, $payload);
    }
    $topLevel->put('services', $parsedServices);

    $customOrder = ['services', 'volumes', 'networks', 'configs', 'secrets'];

    $topLevel = $topLevel->sortBy(function ($value, $key) use ($customOrder) {
        return array_search($key, $customOrder);
    });

    // Remove empty top-level sections (volumes, networks, configs, secrets)
    // Keep only non-empty sections to match Docker Compose best practices
    $topLevel = $topLevel->filter(function ($value, $key) {
        // Always keep 'services' section
        if ($key === 'services') {
            return true;
        }

        // Keep section only if it has content
        return $value instanceof Collection ? $value->isNotEmpty() : ! empty($value);
    });

    $cleanedCompose = Yaml::dump(convertToArray($topLevel), 10, 2);
    $resource->docker_compose = $cleanedCompose;

    // Update docker_compose_raw to remove content: from volumes only
    // This keeps the original user input clean while preventing content reapplication
    // Parse the original compose again to create a clean version without Coolify additions
    try {
        $originalYaml = Yaml::parse($originalCompose);
        // Remove content, isDirectory, and is_directory from all volume definitions
        if (isset($originalYaml['services'])) {
            foreach ($originalYaml['services'] as $serviceName => &$service) {
                if (isset($service['volumes'])) {
                    foreach ($service['volumes'] as $key => &$volume) {
                        if (is_array($volume)) {
                            unset($volume['content']);
                            unset($volume['isDirectory']);
                            unset($volume['is_directory']);
                        }
                    }
                }
            }
        }
        $resource->docker_compose_raw = Yaml::dump($originalYaml, 10, 2);
    } catch (\Exception $e) {
        // If parsing fails, keep the original docker_compose_raw unchanged
        ray('Failed to update docker_compose_raw in serviceParser: '.$e->getMessage());
    }

    data_forget($resource, 'environment_variables');
    data_forget($resource, 'environment_variables_preview');
    $resource->save();

    return $topLevel;
}
