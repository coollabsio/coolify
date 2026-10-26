<?php

function service_logo_urls(mixed $logo): array
{
    $defaultLogo = 'svgs/default.webp';

    if (! is_string($logo) || $logo === '' || basename($logo) === basename($defaultLogo)) {
        return [
            'logo' => asset($defaultLogo),
            'logo_cdn_url' => asset($defaultLogo),
            'logo_default_url' => asset($defaultLogo),
        ];
    }

    if (str_starts_with($logo, 'svg/')) {
        $logo = 'svgs/'.str($logo)->after('svg/');
    }

    $logo = ltrim($logo, '/');

    return [
        'logo' => asset($logo),
        'logo_cdn_url' => 'https://raw.githubusercontent.com/coollabsio/coolify/refs/heads/main/public/'.$logo,
        'logo_default_url' => asset($defaultLogo),
    ];
}

use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\LocalFileVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

/**
 * The URL of a logo in public/svgs/resources. The file's modification time is the version, so
 * browsers load a changed logo instead of their cached copy.
 */
function resource_logo_url(string $name): string
{
    $path = "svgs/resources/{$name}.svg";
    $modifiedAt = is_file(public_path($path)) ? filemtime(public_path($path)) : false;

    return asset($path).($modifiedAt ? "?v={$modifiedAt}" : '');
}

/**
 * The logo of a resource, the same one shown on the "New resource" page: the database engine,
 * the service template, or how the application is built. Null when there is no specific logo.
 *
 * Applications with a domain also get the favicon URL of their first domain. The browser loads it
 * and shows it in place of the logo when it loads. Coolify itself never requests it. Applications
 * behind HTTP basic auth get no favicon: the request makes the browser ask for the credentials.
 *
 * @return array{src: string, dark: ?string, fallback: ?string, padded: bool, favicon: ?string}|null
 */
function resource_logo(Model $resource): ?array
{
    $resourceLogo = function (string $name, bool $hasDarkVariant = false): array {
        return [
            'src' => resource_logo_url($name),
            'dark' => $hasDarkVariant ? resource_logo_url("{$name}-dark") : null,
            'fallback' => null,
            'padded' => false,
            'favicon' => null,
        ];
    };

    if ($resource instanceof Service) {
        $templates = once(fn () => get_service_templates());
        $type = filled($resource->service_type) ? $resource->service_type : str($resource->name)->beforeLast('-')->value();
        $logo = data_get($templates->get(resolve_service_template_key($type, $templates)), 'logo');
        if (! is_string($logo) || $logo === '' || basename($logo) === 'default.webp') {
            return null;
        }
        $urls = service_logo_urls($logo);

        return ['src' => $urls['logo'], 'dark' => null, 'fallback' => $urls['logo_cdn_url'], 'padded' => true, 'favicon' => null];
    }

    if ($resource instanceof Application) {
        $logo = match (true) {
            $resource->build_pack === 'dockerimage' => $resourceLogo('docker-image'),
            $resource->build_pack === 'dockerfile' => $resourceLogo('dockerfile'),
            $resource->build_pack === 'dockercompose' => $resourceLogo('docker-compose'),
            $resource->source_type === GithubApp::class => $resourceLogo('github-app', true),
            $resource->source_type === GitlabApp::class => $resourceLogo('gitlab-app'),
            default => $resourceLogo('public-repo'),
        };

        $favicon = $resource->is_http_basic_auth_enabled ? null : resource_favicon_url($resource->fqdn);

        return [...$logo, 'favicon' => $favicon];
    }

    if (! method_exists($resource, 'type')) {
        return null;
    }

    return match (str($resource->type())->after('standalone-')->value()) {
        'postgresql' => $resourceLogo('postgres'),
        'mysql' => $resourceLogo('mysql'),
        'mariadb' => $resourceLogo('mariadb'),
        'mongodb' => $resourceLogo('mongodb'),
        'redis' => $resourceLogo('redis'),
        'keydb' => $resourceLogo('keydb', true),
        'dragonfly' => $resourceLogo('dragonfly', true),
        'clickhouse' => $resourceLogo('clickhouse'),
        'sqlite' => $resourceLogo('sqlite'),
        default => null,
    };
}

/**
 * The favicon URL of the first domain in a comma-separated domain list, or null when there is no usable domain.
 */
function resource_favicon_url(?string $fqdn): ?string
{
    $domain = str((string) $fqdn)->explode(',')->map(fn (string $url) => trim($url))->first(fn (string $url) => $url !== '');
    $parts = parse_url((string) $domain);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (! in_array($scheme, ['http', 'https'], true) || $host === '' || str_contains($host, '*')) {
        return null;
    }
    $port = isset($parts['port']) ? ':'.$parts['port'] : '';

    return "{$scheme}://{$host}{$port}/favicon.ico";
}

function replaceRegex(?string $name = null)
{
    return "/\\\${?{$name}[^}]*}?|\\\${$name}\w+/";
}
function collectRegex(string $name)
{
    return "/{$name}\w+/";
}

/**
 * Extract content between balanced braces, handling nested braces properly.
 *
 * @param  string  $str  The string to search
 * @param  int  $startPos  Position to start searching from
 * @return array|null Array with 'content', 'start', and 'end' keys, or null if no balanced braces found
 */
function extractBalancedBraceContent(string $str, int $startPos = 0): ?array
{
    // Find opening brace
    if ($startPos >= strlen($str)) {
        return null;
    }
    $openPos = strpos($str, '{', $startPos);
    if ($openPos === false) {
        return null;
    }

    // Track depth to find matching closing brace
    $depth = 1;
    $pos = $openPos + 1;
    $len = strlen($str);

    while ($pos < $len && $depth > 0) {
        if ($str[$pos] === '{') {
            $depth++;
        } elseif ($str[$pos] === '}') {
            $depth--;
        }
        $pos++;
    }

    if ($depth !== 0) {
        // Unbalanced braces
        return null;
    }

    return [
        'content' => substr($str, $openPos + 1, $pos - $openPos - 2),
        'start' => $openPos,
        'end' => $pos - 1,
    ];
}

/**
 * Split variable expression on operators (:-,  -,  :?,  ?) while respecting nested braces.
 *
 * @param  string  $content  The content to split (without outer ${...})
 * @return array|null Array with 'variable', 'operator', and 'default' keys, or null if no operator found
 */
function splitOnOperatorOutsideNested(string $content): ?array
{
    $operators = [':-', '-', ':?', '?'];
    $depth = 0;
    $len = strlen($content);

    for ($i = 0; $i < $len; $i++) {
        if ($content[$i] === '{') {
            $depth++;
        } elseif ($content[$i] === '}') {
            $depth--;
        } elseif ($depth === 0) {
            // Check for operators only at depth 0 (outside nested braces)
            foreach ($operators as $op) {
                if (substr($content, $i, strlen($op)) === $op) {
                    return [
                        'variable' => substr($content, 0, $i),
                        'operator' => $op,
                        'default' => substr($content, $i + strlen($op)),
                    ];
                }
            }
        }
    }

    return null;
}

function replaceVariables(string $variable): Stringable
{
    // Handle ${VAR} syntax with proper brace matching
    $str = str($variable);

    // Handle ${VAR} format
    if ($str->startsWith('${')) {
        $result = extractBalancedBraceContent($variable, 0);
        if ($result !== null) {
            return str($result['content']);
        }

        // Fallback to old behavior for malformed input
        return $str->before('}')->replaceFirst('$', '')->replaceFirst('{', '');
    }

    // Handle {VAR} format (from regex capture group without $)
    if ($str->startsWith('{') && $str->endsWith('}')) {
        return str(substr($variable, 1, -1));
    }

    // Handle {VAR format (from regex capture group, may be truncated)
    if ($str->startsWith('{')) {
        $result = extractBalancedBraceContent('$'.$variable, 0);
        if ($result !== null) {
            return str($result['content']);
        }

        // Fallback: remove { and get content before }
        return $str->replaceFirst('{', '')->before('}');
    }

    // Handle bare $VAR format (no braces)
    if ($str->startsWith('$')) {
        return $str->replaceFirst('$', '');
    }

    return $str;
}

/**
 * Quote a storage path without changing its existing simple shell-variable expansion.
 */
function filesystemVolumeShellArgument(string $path): string
{
    if (trim($path) === '') {
        throw new Exception('Invalid storage path: path is empty.');
    }
    validateComposeArrayVolumeSource($path);

    $tilde = '';
    if (preg_match('/^~[A-Za-z0-9_-]*(?:\/|$)/', $path, $matches)) {
        $tilde = $matches[0];
        $path = substr($path, strlen($tilde));
    }

    if (! str_contains($path, '$')) {
        return $tilde === '' ? escapeshellarg($path) : $tilde.($path === '' ? '' : escapeshellarg($path));
    }

    $quotedPath = str_replace(['\\', '"'], ['\\\\', '\\"'], $path);

    return $tilde.'"'.$quotedPath.'"';
}

function getFilesystemVolumesFromServer(ServiceApplication|ServiceDatabase|Application $oneService, bool $isInit = false)
{
    try {
        if ($oneService->getMorphClass() === Application::class) {
            $workdir = $oneService->workdir();
            $server = $oneService->destination->server;
        } else {
            $workdir = $oneService->service->workdir();
            $server = $oneService->service->server;
        }
        $fileVolumes = $oneService->fileStorages()->get();
        $escapedWorkdir = escapeshellarg($workdir);
        $commands = collect([
            "mkdir -p -- {$escapedWorkdir} > /dev/null 2>&1 || true",
        ]);
        instant_remote_process($commands, $server);
        foreach ($fileVolumes as $fileVolume) {
            $content = data_get($fileVolume, 'content');
            // A relative path is inside the resource directory, never in the SSH user's working
            // directory. A persisted Compose expression (`${VAR:-/path}`) stays as it is.
            $fsPath = trim((string) $fileVolume->fs_path);
            $fileLocation = str_starts_with($fsPath, '$') ? $fsPath : $fileVolume->resolvedFsPath($workdir)->value();
            $escapedFileLocation = filesystemVolumeShellArgument((string) $fileLocation);
            // A token with only the write permission can save a Compose file, so Coolify does not
            // write outside the resource directory here. A deployment or start writes those paths.
            $isOutsideResourceDirectory = fn (): bool => $fileVolume->isOutsideResourceDirectory();
            // Exists and is a file
            $isFile = instant_remote_process(["test -f {$escapedFileLocation} && echo OK || echo NOK"], $server);
            // Exists and is a directory
            $isDir = instant_remote_process(["test -d {$escapedFileLocation} && echo OK || echo NOK"], $server);

            if ($isFile === 'OK') {
                $fileVolume->is_directory = false;
                $fileVolume->save();
                if ($fileVolume->is_based_on_git) {
                    $fileVolume->loadStorageOnServer();
                }
            } elseif ($isDir === 'OK' && ! $fileVolume->is_directory && filled($content)) {
                // A configured file must not lose its content because a directory is at its path.
                // Docker leaves an empty directory when it starts before the file exists: replace it.
                // A directory with files stays; the start or deployment shows a warning for it.
                if (! $isOutsideResourceDirectory() && LocalFileVolume::remoteFileStates([(string) $fileLocation], $server)[0] === 'empty-directory') {
                    $fileVolume->saveStorageOnServer();
                }
            } elseif ($isDir === 'OK') {
                // If its a directory & exists
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && ! $fileVolume->is_directory && $isInit && $content) {
                // Does not exists (no dir or file), not flagged as directory, is init, has content
                if ($isOutsideResourceDirectory()) {
                    continue;
                }
                $fileVolume->content = $content;
                $fileVolume->is_directory = false;
                $fileVolume->save();
                $content = base64_encode($content);
                instant_remote_process([
                    'mkdir -p -- "$(dirname -- '.$escapedFileLocation.')"',
                    "echo '$content' | base64 -d | tee -- {$escapedFileLocation}",
                ], $server);
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && $fileVolume->is_directory && $isInit) {
                // Does not exists (no dir or file), flagged as directory, is init
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
                if (! $isOutsideResourceDirectory()) {
                    instant_remote_process(["mkdir -p -- {$escapedFileLocation}"], $server);
                }
            } elseif ($isFile === 'NOK' && $isDir === 'NOK' && ! $fileVolume->is_directory && $isInit && is_null($content)) {
                // Does not exists (no dir or file), not flagged as directory, is init, has no content => create directory
                $fileVolume->content = null;
                $fileVolume->is_directory = true;
                $fileVolume->save();
                if (! $isOutsideResourceDirectory()) {
                    instant_remote_process(["mkdir -p -- {$escapedFileLocation}"], $server);
                }
            }
        }
    } catch (Throwable $e) {
        return handleError($e);
    }
}
function updateCompose(ServiceApplication|ServiceDatabase $resource)
{
    try {
        $name = data_get($resource, 'name');
        $dockerComposeRaw = data_get($resource, 'service.docker_compose_raw');
        if (! $dockerComposeRaw) {
            throw new Exception('No compose file found or not a valid YAML file.');
        }
        $dockerCompose = parseDockerComposeYaml($dockerComposeRaw);

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
        $environment = data_get($serviceConfig, 'environment') ?? [];
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

        // Remove duplicates and copies of another application's declaration
        $templateVariableNames = array_filter(
            array_unique($templateVariableNames),
            fn ($variableName) => ! isCopiedServiceUrlDeclaration(
                $resource->service,
                collect(data_get($dockerCompose, 'services', [])),
                $resource,
                $variableName,
            ),
        );

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
            $firstFqdn = firstDomainFromList($resource->fqdn);
            $url = Url::fromString($firstFqdn);
            $port = $url->getPort();

            // Same helpers as application/service parsers (COOLIFY_URL / COOLIFY_FQDN).
            $urlValue = getFqdnWithoutPort($firstFqdn);
            $fqdnValue = getHostWithoutPort($firstFqdn);

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
    } catch (Throwable $e) {
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
    } catch (Throwable $e) {
        // Log error but don't throw - prerequisites are nice-to-have, not critical
        Log::error('Failed to apply service application prerequisites', [
            'service_id' => $service->id,
            'service_name' => $service->name,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

/**
 * Clones a service to a destination. The applications, databases, and volumes of the clone come from parse(),
 * so their names are the names that the compose file of the clone uses.
 *
 * @param  array<string, mixed>  $overrides  Attributes for the new service, for example uuid, name, and environment_id.
 */
function clone_service(Service $source, StandaloneDocker|SwarmDocker $destination, array $overrides = [], bool $cloneVolumeData = false): Service
{
    $teamId = $source->team()->id;
    $uuid = $overrides['uuid'] ?? new_public_id();

    $newService = $source->replicate([
        'id',
        'created_at',
        'updated_at',
    ])->fill([
        'name' => $source->name.'-clone-'.$uuid,
        ...$overrides,
        'uuid' => $uuid,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'server_id' => $destination->server_id,
    ]);
    $newService->save();

    foreach ($source->tags as $tag) {
        $newService->tags()->attach($tag->id);
    }

    foreach ($source->scheduled_tasks()->get() as $task) {
        $task->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'uuid' => new_public_id(),
            'service_id' => $newService->id,
            'team_id' => $teamId,
        ])->save();
    }

    foreach ($source->environment_variables()->get() as $environmentVariable) {
        $environmentVariable->replicate([
            'id',
            'created_at',
            'updated_at',
        ])->fill([
            'resourceable_id' => $newService->id,
            'resourceable_type' => $newService->getMorphClass(),
        ])->save();
    }

    $source->cloneSecretManagerLinkTo($newService);

    // Service::replicate() does not copy the child rows, so parse() creates them for the clone.
    $newService->parse();
    $newService->refresh();

    $sourceApplicationsByName = $source->applications()->get()->keyBy('name');
    $sourceDatabasesByName = $source->databases()->get()->keyBy('name');
    $pendingVolumeClones = [];

    foreach ([
        [$newService->applications()->get(), $sourceApplicationsByName],
        [$newService->databases()->get(), $sourceDatabasesByName],
    ] as [$newResources, $sourcesByName]) {
        foreach ($newResources as $newResource) {
            $newResource->fill(['status' => 'exited'])->save();

            $sourceResource = $sourcesByName->get($newResource->name);
            if (! $sourceResource || ! $cloneVolumeData) {
                continue;
            }

            $targetVolumesByMount = $newResource->persistentStorages()->get()->keyBy('mount_path');
            foreach ($sourceResource->persistentStorages()->get() as $sourceVolume) {
                $targetVolume = $targetVolumesByMount->get($sourceVolume->mount_path);
                if ($targetVolume) {
                    $pendingVolumeClones[] = [$sourceVolume->name, $targetVolume];
                }
            }
        }
    }

    foreach ($newService->databases()->get() as $database) {
        $sourceDatabase = $sourceDatabasesByName->get($database->name);
        foreach ($sourceDatabase?->scheduledBackups()->get() ?? [] as $backup) {
            $newBackup = $backup->replicate([
                'id',
                'created_at',
                'updated_at',
            ])->fill([
                'uuid' => new_public_id(),
                'database_id' => $database->id,
                'database_type' => $database->getMorphClass(),
                'team_id' => $teamId,
            ]);
            $newBackup->save();
            $backup->copyS3StoragesTo($newBackup);
        }
    }

    $sourceServer = $source->destination?->server;
    $targetServer = $newService->destination?->server;
    if ($pendingVolumeClones !== [] && $sourceServer && $targetServer) {
        $chain = [
            function () use ($source) {
                StopService::run($source);
            },
            ...array_map(
                fn (array $clone) => new VolumeCloneJob($clone[0], $clone[1]->name, $sourceServer, $targetServer, $clone[1]),
                $pendingVolumeClones,
            ),
            function () use ($source) {
                StartService::run($source);
            },
        ];

        // The jobs load the new volume rows, so they must not run before the transaction of the caller commits.
        DB::afterCommit(fn () => Bus::chain($chain)->onQueue('high')->dispatch());
    }

    return $newService;
}
