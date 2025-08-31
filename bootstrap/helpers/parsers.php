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

    return [
        'source' => $source !== null ? str($source) : null,
        'target' => $target !== null ? str($target) : null,
        'mode' => $mode !== null ? str($mode) : null,
    ];
}

function applicationParser(Application $resource, int $pull_request_id = 0, ?int $preview_id = null): Collection
{
    $uuid = data_get($resource, 'uuid');
    $compose = data_get($resource, 'docker_compose_raw');
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
                if (substr_count(str($key)->value(), '_') === 3) {
                    $fqdnFor = $key->after('SERVICE_FQDN_')->beforeLast('_')->lower()->value();
                    $port = $key->afterLast('_')->value();
                } else {
                    $fqdnFor = $key->after('SERVICE_FQDN_')->lower()->value();
                    $port = null;
                }
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

                if (substr_count(str($key)->value(), '_') === 2) {
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                }
                if (substr_count(str($key)->value(), '_') === 3) {

                    $newKey = str($key)->beforeLast('_');
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $newKey->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_build_time' => false,
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
                if ($command->value() === 'FQDN') {
                    $fqdnFor = $key->after('SERVICE_FQDN_')->lower()->value();
                    $originalFqdnFor = str($fqdnFor)->replace('_', '-');
                    if (str($fqdnFor)->contains('-')) {
                        $fqdnFor = str($fqdnFor)->replace('-', '_');
                    }
                    // Generated FQDN & URL
                    $fqdn = generateFqdn(server: $server, random: "$originalFqdnFor-$uuid", parserVersion: $resource->compose_parsing_version);
                    $url = generateUrl(server: $server, random: "$originalFqdnFor-$uuid");
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                    if ($resource->build_pack === 'dockercompose') {
                        $domains = collect(json_decode(data_get($resource, 'docker_compose_domains'))) ?? collect([]);
                        $domainExists = data_get($domains->get($fqdnFor), 'domain');
                        $envExists = $resource->environment_variables()->where('key', $key->value())->first();
                        if (str($domainExists)->replace('http://', '')->replace('https://', '')->value() !== $envExists->value) {
                            $envExists->update([
                                'value' => $url,
                            ]);
                        }
                        if (is_null($domainExists)) {
                            // Put URL in the domains array instead of FQDN
                            $domains->put((string) $fqdnFor, [
                                'domain' => $url,
                            ]);
                            $resource->docker_compose_domains = $domains->toJson();
                            $resource->save();
                        }
                    }
                } elseif ($command->value() === 'URL') {
                    $urlFor = $key->after('SERVICE_URL_')->lower()->value();
                    $originalUrlFor = str($urlFor)->replace('_', '-');
                    if (str($urlFor)->contains('-')) {
                        $urlFor = str($urlFor)->replace('-', '_');
                    }
                    $url = generateUrl(server: $server, random: "$originalUrlFor-$uuid");
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                    if ($resource->build_pack === 'dockercompose') {
                        $domains = collect(json_decode(data_get($resource, 'docker_compose_domains'))) ?? collect([]);
                        $domainExists = data_get($domains->get($urlFor), 'domain');
                        $envExists = $resource->environment_variables()->where('key', $key->value())->first();
                        if ($domainExists !== $envExists->value) {
                            $envExists->update([
                                'value' => $url,
                            ]);
                        }
                        if (is_null($domainExists)) {
                            $domains->put((string) $urlFor, [
                                'domain' => $url,
                            ]);
                            $resource->docker_compose_domains = $domains->toJson();
                            $resource->save();
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
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                }
            }
        }
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
                            $source = $source."-pr-$pullRequestId";
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
                        $name = "{$name}-pr-$pullRequestId";
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
                        $dependency = "$dependency-pr-$pullRequestId";

                        $newDependsOn->put($condition, $dependency);
                    } else {
                        $condition = "$condition-pr-$pullRequestId";
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
                    'is_build_time' => false,
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
                    'is_build_time' => false,
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
                            'is_build_time' => false,
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
                        'is_build_time' => false,
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
        $changedServiceName = str($serviceName)->replace('-', '_')->value();
        $fqdns = data_get($domains, "$changedServiceName.domain");
        // Generate SERVICE_FQDN & SERVICE_URL for dockercompose
        if ($resource->build_pack === 'dockercompose') {
            foreach ($domains as $forServiceName => $domain) {
                $parsedDomain = data_get($domain, 'domain');
                $serviceNameFormatted = str($serviceName)->upper()->replace('-', '_');

                if (filled($parsedDomain)) {
                    $parsedDomain = str($parsedDomain)->explode(',')->first();
                    $coolifyUrl = Url::fromString($parsedDomain);
                    $coolifyScheme = $coolifyUrl->getScheme();
                    $coolifyFqdn = $coolifyUrl->getHost();
                    $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                    $coolifyEnvironments->put('SERVICE_URL_'.str($forServiceName)->upper()->replace('-', '_'), $coolifyUrl->__toString());
                    $coolifyEnvironments->put('SERVICE_FQDN_'.str($forServiceName)->upper()->replace('-', '_'), $coolifyFqdn);
                    $resource->environment_variables()->updateOrCreate([
                        'resourceable_type' => Application::class,
                        'resourceable_id' => $resource->id,
                        'key' => 'SERVICE_URL_'.str($forServiceName)->upper()->replace('-', '_'),
                    ], [
                        'value' => $coolifyUrl->__toString(),
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                    $resource->environment_variables()->updateOrCreate([
                        'resourceable_type' => Application::class,
                        'resourceable_id' => $resource->id,
                        'key' => 'SERVICE_FQDN_'.str($forServiceName)->upper()->replace('-', '_'),
                    ], [
                        'value' => $coolifyFqdn,
                        'is_build_time' => false,
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
                    $found_fqdn = data_get($docker_compose_domains, "$serviceName.domain");
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
                        $random = new Cuid2;
                        $preview_fqdn = str_replace('{{random}}', $random, $template);
                        $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                        $preview_fqdn = str_replace('{{pr_id}}', $pullRequestId, $preview_fqdn);
                        $preview_fqdn = "$schema://$preview_fqdn";
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
                // if value is empty, set it to null so if you set the environment variable in the .env file (Coolify's UI), it will used
                if (str($value)->isEmpty()) {
                    if ($resource->environment_variables()->where('key', $key)->exists()) {
                        $value = $resource->environment_variables()->where('key', $key)->first()->value;
                    } else {
                        $value = null;
                    }
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
            $payload['environment'] = $environment->merge($coolifyEnvironments);
        }
        if ($logging) {
            $payload['logging'] = $logging;
        }
        if ($depends_on->count() > 0) {
            $payload['depends_on'] = $depends_on;
        }
        if ($isPullRequest) {
            $serviceName = "{$serviceName}-pr-{$pullRequestId}";
        }

        $parsedServices->put($serviceName, $payload);
    }
    $topLevel->put('services', $parsedServices);

    $customOrder = ['services', 'volumes', 'networks', 'configs', 'secrets'];

    $topLevel = $topLevel->sortBy(function ($value, $key) use ($customOrder) {
        return array_search($key, $customOrder);
    });

    $resource->docker_compose = Yaml::dump(convertToArray($topLevel), 10, 2);
    data_forget($resource, 'environment_variables');
    data_forget($resource, 'environment_variables_preview');
    $resource->save();

    return $topLevel;
}

function serviceParser(Service $resource): Collection
{
    $uuid = data_get($resource, 'uuid');
    $compose = data_get($resource, 'docker_compose_raw');
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

    $allMagicEnvironments = collect([]);
    // Presave services
    foreach ($services as $serviceName => $service) {
        $image = data_get_str($service, 'image');
        $isDatabase = isDatabaseImage($image, $service);
        if ($isDatabase) {
            $applicationFound = ServiceApplication::where('name', $serviceName)->where('image', $image)->where('service_id', $resource->id)->first();
            if ($applicationFound) {
                $savedService = $applicationFound;
            } else {
                $savedService = ServiceDatabase::firstOrCreate([
                    'name' => $serviceName,
                    'image' => $image,
                    'service_id' => $resource->id,
                ]);
            }
        } else {
            $savedService = ServiceApplication::firstOrCreate([
                'name' => $serviceName,
                'image' => $image,
                'service_id' => $resource->id,
            ]);
        }
    }
    foreach ($services as $serviceName => $service) {
        $predefinedPort = null;
        $magicEnvironments = collect([]);
        $image = data_get_str($service, 'image');
        $environment = collect(data_get($service, 'environment', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);
        $isDatabase = isDatabaseImage($image, $service);

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
                // SERVICE_FQDN_APP or SERVICE_FQDN_APP_3000
                if (substr_count(str($key)->value(), '_') === 3) {
                    if ($key->startsWith('SERVICE_FQDN_')) {
                        $urlFor = null;
                        $fqdnFor = $key->after('SERVICE_FQDN_')->beforeLast('_')->lower()->value();
                    }
                    if ($key->startsWith('SERVICE_URL_')) {
                        $fqdnFor = null;
                        $urlFor = $key->after('SERVICE_URL_')->beforeLast('_')->lower()->value();
                    }
                    $port = $key->afterLast('_')->value();
                } else {
                    if ($key->startsWith('SERVICE_FQDN_')) {
                        $urlFor = null;
                        $fqdnFor = $key->after('SERVICE_FQDN_')->lower()->value();
                    }
                    if ($key->startsWith('SERVICE_URL_')) {
                        $fqdnFor = null;
                        $urlFor = $key->after('SERVICE_URL_')->lower()->value();
                    }
                    $port = null;
                }
                if (blank($savedService->fqdn)) {
                    if ($fqdnFor) {
                        $fqdn = generateFqdn(server: $server, random: "$fqdnFor-$uuid", parserVersion: $resource->compose_parsing_version);
                    } else {
                        $fqdn = generateFqdn(server: $server, random: "{$savedService->name}-$uuid", parserVersion: $resource->compose_parsing_version);
                    }
                    if ($urlFor) {
                        $url = generateUrl($server, "$urlFor-$uuid");
                    } else {
                        $url = generateUrl($server, "{$savedService->name}-$uuid");
                    }
                } else {
                    $fqdn = str($savedService->fqdn)->after('://')->before(':')->prepend(str($savedService->fqdn)->before('://')->append('://'))->value();
                    $url = str($savedService->fqdn)->after('://')->before(':')->prepend(str($savedService->fqdn)->before('://')->append('://'))->value();
                }

                if ($value && get_class($value) === \Illuminate\Support\Stringable::class && $value->startsWith('/')) {
                    $path = $value->value();
                    if ($path !== '/') {
                        $fqdn = "$fqdn$path";
                        $url = "$url$path";
                    }
                }
                $fqdnWithPort = $fqdn;
                $urlWithPort = $url;
                if ($fqdn && $port) {
                    $fqdnWithPort = "$fqdn:$port";
                }
                if ($url && $port) {
                    $urlWithPort = "$url:$port";
                }
                if (is_null($savedService->fqdn)) {
                    if ((int) $resource->compose_parsing_version >= 5 && version_compare(config('constants.coolify.version'), '4.0.0-beta.420.7', '>=')) {
                        if ($fqdnFor) {
                            $savedService->fqdn = $fqdnWithPort;
                        }
                        if ($urlFor) {
                            $savedService->fqdn = $urlWithPort;
                        }
                    } else {
                        $savedService->fqdn = $fqdnWithPort;
                    }
                    $savedService->save();
                }
                if (substr_count(str($key)->value(), '_') === 2) {
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                }
                if (substr_count(str($key)->value(), '_') === 3) {
                    $newKey = str($key)->beforeLast('_');
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $newKey->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $newKey->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_build_time' => false,
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
                    $serviceExists = ServiceApplication::where('name', str($fqdnFor)->replace('_', '-')->value())->where('service_id', $resource->id)->first();
                    if (! $envExists && (data_get($serviceExists, 'name') === str($fqdnFor)->replace('_', '-')->value())) {
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
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);

                } elseif ($command->value() === 'URL') {
                    $urlFor = $key->after('SERVICE_URL_')->lower()->value();
                    $url = generateUrl(server: $server, random: str($urlFor)->replace('_', '-')->value()."-$uuid");

                    $envExists = $resource->environment_variables()->where('key', $key->value())->first();
                    $serviceExists = ServiceApplication::where('name', str($urlFor)->replace('_', '-')->value())->where('service_id', $resource->id)->first();
                    if (! $envExists && (data_get($serviceExists, 'name') === str($urlFor)->replace('_', '-')->value())) {
                        $serviceExists->fqdn = $url;
                        $serviceExists->save();
                    }
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_build_time' => false,
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
                        'is_build_time' => false,
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

        $isDatabase = isDatabaseImage($image, $service);
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

        if ($isDatabase) {
            $applicationFound = ServiceApplication::where('name', $serviceName)->where('image', $image)->where('service_id', $resource->id)->first();
            if ($applicationFound) {
                $savedService = $applicationFound;
            } else {
                $savedService = ServiceDatabase::firstOrCreate([
                    'name' => $serviceName,
                    'image' => $image,
                    'service_id' => $resource->id,
                ]);
            }
        } else {
            $savedService = ServiceApplication::firstOrCreate([
                'name' => $serviceName,
                'image' => $image,
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
                    'is_build_time' => false,
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
                    'is_build_time' => false,
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
                            'is_build_time' => false,
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
                        'is_build_time' => false,
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
                // if value is empty, set it to null so if you set the environment variable in the .env file (Coolify's UI), it will used
                if (str($value)->isEmpty()) {
                    if ($resource->environment_variables()->where('key', $key)->exists()) {
                        $value = $resource->environment_variables()->where('key', $key)->first()->value;
                    } else {
                        $value = null;
                    }
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
            $payload['environment'] = $environment->merge($coolifyEnvironments);
        }
        if ($logging) {
            $payload['logging'] = $logging;
        }
        if ($depends_on->count() > 0) {
            $payload['depends_on'] = $depends_on;
        }

        $parsedServices->put($serviceName, $payload);
    }
    $topLevel->put('services', $parsedServices);

    $customOrder = ['services', 'volumes', 'networks', 'configs', 'secrets'];

    $topLevel = $topLevel->sortBy(function ($value, $key) use ($customOrder) {
        return array_search($key, $customOrder);
    });

    $resource->docker_compose = Yaml::dump(convertToArray($topLevel), 10, 2);
    data_forget($resource, 'environment_variables');
    data_forget($resource, 'environment_variables_preview');
    $resource->save();

    return $topLevel;
}
