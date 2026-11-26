<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Support\RemoteSecretReferences;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses Docker Compose YAML with a higher collection alias limit than the Symfony default (128),
 * because large Compose files (for example Sentry self-hosted) reuse YAML anchors many times.
 *
 * @throws ParseException If the YAML is invalid or exceeds the alias limit
 */
function parseDockerComposeYaml(string $compose): mixed
{
    // Editors on Windows can save files with a UTF-8 BOM, which Symfony YAML keeps in the first key.
    $compose = preg_replace('/^\xEF\xBB\xBF/', '', $compose);

    try {
        return Yaml::parse($compose, maxAliasesForCollections: Application::MAX_DOCKER_COMPOSE_COLLECTION_ALIASES);
    } catch (ParseException $e) {
        $joined = joinComposeAnchorsToTheirKeys($compose);
        if ($joined === $compose) {
            throw $e;
        }
        try {
            return Yaml::parse($joined, maxAliasesForCollections: Application::MAX_DOCKER_COMPOSE_COLLECTION_ALIASES);
        } catch (ParseException) {
            throw $e;
        }
    }
}

/**
 * Moves an anchor that is on its own line below a key to the line of the key ("key:\n  &name" becomes "key: &name").
 * Docker Compose accepts both forms (the Airflow Compose file uses the first), but Symfony YAML only reads the second.
 * Only used after a failed parse, so a valid file (for example a block scalar with such lines) stays unchanged.
 */
function joinComposeAnchorsToTheirKeys(string $compose): string
{
    return preg_replace_callback(
        '/^([ \t]*)((?:- )?[^\s#][^\n]*?:)[ \t]*(?:#[^\n]*)?\r?\n([ \t]+)(&[^\s#]+)[ \t]*(?:#[^\n]*)?\r?$/m',
        fn (array $match) => strlen($match[3]) > strlen($match[1]) ? "{$match[1]}{$match[2]} {$match[4]}" : $match[0],
        $compose
    );
}

/**
 * Validates a Docker Compose YAML string for command injection vulnerabilities.
 * This should be called BEFORE saving to database to prevent malicious data from being stored.
 *
 * @param  string  $composeYaml  The raw Docker Compose YAML content
 *
 * @throws Exception If the compose file contains command injection attempts
 */
function validateDockerComposeForInjection(string $composeYaml): void
{
    try {
        $parsed = parseDockerComposeYaml($composeYaml);
    } catch (Exception $e) {
        throw new Exception('Invalid YAML format: '.$e->getMessage(), 0, $e);
    }

    if (! is_array($parsed) || ! isset($parsed['services']) || ! is_array($parsed['services'])) {
        throw new Exception('Docker Compose file must contain a "services" section');
    }
    // Validate service names
    foreach ($parsed['services'] as $serviceName => $serviceConfig) {
        try {
            validateShellSafePath($serviceName, 'service name');
        } catch (Exception $e) {
            throw new Exception(
                'Invalid Docker Compose service name: '.$e->getMessage().
                ' Service names must not contain shell metacharacters.',
                0,
                $e
            );
        }
        validateComposeServiceName((string) $serviceName);

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
                            validateComposeArrayVolumeSource($source);
                        }
                    }
                    if (isset($volume['target'])) {
                        $target = $volume['target'];
                        if (is_string($target)) {
                            rejectComposeVolumeTargetVariable($target, 'Invalid Docker volume definition (array syntax)');
                            try {
                                validateShellSafePath($target, 'volume target');
                            } catch (Exception $e) {
                                throw new Exception(
                                    'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                    ' Please use safe path names without shell metacharacters.',
                                    0,
                                    $e
                                );
                            }
                        }
                    }
                    validateComposeContentVolumeSource($volume);
                }
            }
        }

        if (is_array($serviceConfig) && isset($serviceConfig['networks']) && is_array($serviceConfig['networks'])) {
            foreach ($serviceConfig['networks'] as $networkKey => $networkDetails) {
                if (is_int($networkKey) && (is_string($networkDetails) || is_int($networkDetails))) {
                    validateComposeNetworkNameWithVariables((string) $networkDetails, 'service network', (string) $serviceName);
                } elseif (is_string($networkKey) || is_int($networkKey)) {
                    // Compose does not interpolate keys, so a key must be a plain network name.
                    validateComposeNetworkName((string) $networkKey, 'service network', (string) $serviceName);
                }
            }
        }
    }

    if (isset($parsed['networks']) && is_array($parsed['networks'])) {
        foreach ($parsed['networks'] as $networkName => $networkConfig) {
            if (is_string($networkName) || is_int($networkName)) {
                validateComposeNetworkName((string) $networkName);
            }
            if (is_array($networkConfig) && isset($networkConfig['name']) && is_string($networkConfig['name'])) {
                validateComposeNetworkNameField($networkConfig['name']);
            }
        }
    }

    if (isset($parsed['volumes']) && is_array($parsed['volumes'])) {
        foreach ($parsed['volumes'] as $volumeName => $volumeConfig) {
            if (isComposeExternalVolume($volumeConfig)) {
                validateComposeExternalVolume($volumeName, $volumeConfig);
            }
        }
    }
}

/**
 * The directory that the Compose parsers resolve `./` bind sources in. Services that use parser
 * version 3 resolve them in the applications directory.
 */
function composeResourceDirectory(Application|Service $resource): string
{
    if ($resource instanceof Service && (int) $resource->compose_parsing_version === 3) {
        return application_configuration_dir().'/'.$resource->uuid;
    }

    return $resource->workdir();
}

/**
 * The entries of a Compose `env_file` value: paths, or long-syntax maps with a `path` key.
 *
 * @return list<string|array<string, mixed>>
 */
function composeEnvFileEntries(mixed $envFile): array
{
    return array_values(is_null($envFile) ? [] : (is_array($envFile) ? $envFile : [$envFile]));
}

/**
 * The path of a Compose `env_file` entry.
 */
function composeEnvFileEntryPath(mixed $entry): string
{
    return (string) (is_array($entry) ? ($entry['path'] ?? '') : $entry);
}

/**
 * Whether a Compose `env_file` entry is Coolify's .env file, which Coolify writes in the project directory.
 */
function isCoolifyComposeEnvFile(mixed $entry): bool
{
    return in_array(composeEnvFileEntryPath($entry), ['.env', './.env'], true);
}

/**
 * The `env_file` list of a Compose service with Coolify's .env file at the end. The env files of the
 * user stay in front of it, so Coolify variables override the values in them.
 *
 * @return list<string|array<string, mixed>>
 */
function composeEnvFilesWithCoolifyEnv(mixed $envFile): array
{
    return collect(composeEnvFileEntries($envFile))
        ->reject(fn (mixed $entry): bool => isCoolifyComposeEnvFile($entry))
        ->push('.env')
        ->unique()
        ->values()
        ->all();
}

/**
 * The path of a Compose `env_file` from the project directory, which is the repository directory
 * of the deployment. Docker Compose on a local machine reads a relative path from the directory of
 * the Compose file, so $composeDirectory is that directory, relative to the project directory.
 * Null when the path is absolute or leaves the project directory.
 */
function composeEnvFileProjectPath(string $path, string $composeDirectory): ?string
{
    if ($path === '' || str_starts_with($path, '/')) {
        return null;
    }
    $segments = [];
    foreach (explode('/', trim($composeDirectory, '/').'/'.$path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                return null;
            }
            array_pop($segments);

            continue;
        }
        $segments[] = $segment;
    }

    return $segments === [] ? null : implode('/', $segments);
}

/**
 * Renders a long-syntax bind with its resolved source. It keeps the options that Docker Compose
 * accepts, such as read_only and bind, and removes other keys, which Compose rejects. Without
 * `bind.create_host_path`, a missing source is created, as with short syntax: Docker Compose
 * before v2.30 does not do that for long syntax. A short-syntax mode in the target
 * (`/data/users.yml:ro`) becomes `read_only`, because Docker rejects it in long syntax.
 *
 * @param  array<string, mixed>  $volume
 * @return array<string, mixed>
 */
function composeLongBindVolume(array $volume, string $source): array
{
    $volume = array_filter(
        $volume,
        fn ($key) => in_array($key, ['type', 'source', 'target', 'read_only', 'consistency', 'bind'], true) || str_starts_with((string) $key, 'x-'),
        ARRAY_FILTER_USE_KEY
    );
    if (preg_match('/^(.+):(ro|rw)$/', (string) ($volume['target'] ?? ''), $matches)) {
        $volume['target'] = $matches[1];
        $volume['read_only'] ??= $matches[2] === 'ro';
    }
    $volume['source'] = $source;
    $volume['bind'] = is_array($volume['bind'] ?? null) ? $volume['bind'] : [];
    $volume['bind']['create_host_path'] ??= true;

    return $volume;
}

/**
 * A long-syntax bind with `bind.create_host_path: false` must fail when its host path is missing.
 * Coolify must not create that path, so it does not add a file storage for it. A volume with
 * `content:` is still written by Coolify.
 */
function composeBindKeepsMissingHostPath(mixed $volume): bool
{
    return is_array($volume)
        && data_get($volume, 'bind.create_host_path') === false
        && ! array_key_exists('content', $volume);
}

/**
 * Removes the file storage that an earlier save created for a bind that now keeps its missing host
 * path. Otherwise, the storage jobs still create that path. The files on the server stay. A storage
 * with a backup schedule stays, because it cannot be deleted before its schedule.
 */
function forgetComposeBindStorage(Application|ServiceApplication|ServiceDatabase $resource, string $fsPath, string $mountPath): void
{
    $resource->fileStorages()
        ->where('fs_path', $fsPath)
        ->where('mount_path', $mountPath)
        ->where('is_host_file', false)
        ->whereDoesntHave('scheduledBackups')
        ->get()
        ->each->delete();
}

/**
 * Coolify writes the `content:` of a Compose bind volume to the source path on the host. Only
 * administrators can edit a Compose file, and they can mount any host path, so the source can be
 * any path, also outside the resource directory. It must exist and be safe to
 * use in a shell command; Coolify escapes it in every remote command.
 *
 * @param  array<string, mixed>  $volume  A long-syntax Compose volume
 *
 * @throws Exception If the source is missing or contains shell metacharacters
 */
/**
 * Coolify names each container "<service name>-<suffix>". Docker allows only container names
 * that match [a-zA-Z0-9][a-zA-Z0-9_.-]+, and Docker Compose allows only service names that
 * match [a-zA-Z0-9._-]+. Compose accepts a service name that starts with ".", "-", or "_",
 * but Docker then refuses to create its container.
 *
 * @throws Exception If Docker cannot use the service name at the start of a container name
 */
function validateComposeServiceName(string $serviceName): void
{
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $serviceName) !== 1) {
        throw new Exception(
            "Service name \"{$serviceName}\" must start with a letter or a digit and contain only letters, digits, \".\", \"-\", and \"_\". ".
            'Docker uses the same rule for container names, and Coolify uses the service name at the start of the container name.'
        );
    }
}

/**
 * Coolify sets container_name on every Compose service, and Docker Compose does not start more
 * than one container of a service that has a fixed container_name.
 *
 * @throws Exception If the service sets scale or deploy.replicas to more than 1
 */
function validateComposeServiceScale(string $serviceName, mixed $service): void
{
    foreach (['scale', 'deploy.replicas'] as $key) {
        $value = data_get($service, $key);
        if (is_numeric($value) && (int) $value > 1) {
            throw new Exception(
                "Service \"{$serviceName}\" sets {$key} to {$value}. Coolify sets a container_name for each service, ".
                'and Docker Compose cannot run more than one container with the same container_name. '.
                "Remove {$key}, or define each instance as a separate service."
            );
        }
    }
}

function validateComposeContentVolumeSource(array $volume): void
{
    if (! array_key_exists('content', $volume) || ($volume['type'] ?? null) !== 'bind') {
        return;
    }

    $source = $volume['source'] ?? null;
    if (! is_string($source) || trim($source) === '') {
        throw new Exception('Invalid Docker volume definition (array syntax): A bind volume with content needs a source path.');
    }

    validateComposeArrayVolumeSource($source);
}

/**
 * Resolves the Compose variables in a volume source as Docker Compose does: `${VAR}` is the value of VAR,
 * and `${VAR:-default}` or `${VAR-default}` is the value of VAR or else the default. Only non-empty values count.
 *
 * Without $variables, or when the result with them is not a host path, a variable with a default becomes the
 * default and a variable without one stays as written. So a named volume keeps the name that it always had,
 * and an empty default gives `${VAR}`.
 *
 * @param  array<string, string>|null  $variables  The plain values that the resource sets (composeVolumeSourceVariables())
 */
function resolveComposeVolumeSourceVariables(string $source, ?array $variables = null): string
{
    $resolve = fn (array $values): string => preg_replace_callback(
        '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::?-([^}]*))?\}/',
        function (array $match) use ($values): string {
            $value = $values[$match[1]] ?? '';
            if ($value !== '') {
                return $value;
            }

            return ($match[2] ?? '') !== '' ? $match[2] : '${'.$match[1].'}';
        },
        $source
    );

    $withoutValues = $resolve([]);
    if ($variables === null) {
        return $withoutValues;
    }
    $withValues = $resolve($variables);

    return sourceIsLocal(str($withValues)) ? $withValues : $withoutValues;
}

/**
 * Before v4.7.6, a short-syntax host path such as `${DATA:-./data}` always used the default, also when the resource
 * set DATA. A storage that Coolify saved with that path keeps it, so its data does not move after the upgrade. The
 * storage keeps the path of the variable in `variable_fs_path`, and the user switches to it on the storage.
 *
 * @return array{0: Stringable, 1: ?string} The host path, and the path of the variable while the saved path is kept
 */
function keepSavedComposeBindSource(Stringable $source, ?string $volume, Stringable $mainDirectory, ?LocalFileVolume $savedStorage, Application|Service $resource): array
{
    if ($volume === null || $savedStorage === null || ! str_contains($volume, '${')) {
        return [$source, null];
    }
    try {
        $defaultSource = parseDockerVolumeString($volume)['source'];
    } catch (Exception) {
        return [$source, null];
    }
    if ($defaultSource === null || ! sourceIsLocal($defaultSource)) {
        return [$source, null];
    }
    $defaultSource = resolveComposeBindSource($defaultSource, $mainDirectory, $savedStorage->fs_path);
    if ($defaultSource->value() === $source->value() || $savedStorage->fs_path !== $defaultSource->value()) {
        return [$source, null];
    }
    $resource->addComposeVolumeWarning("The storage {$savedStorage->mount_path} keeps its saved host path {$defaultSource}. Its variable gives {$source}. To use that path, open the storage and click Use the variable path.");

    return [$defaultSource, $source->value()];
}

/**
 * The name of the Docker volume that v4.7.6 and older gave a short-syntax volume with a variable, such as
 * `${DATA}/config:/config` or `${DATA:-name}:/data`. Those versions ignored the values of the resource, so the
 * source was a volume name. Null when the source without those values is a host path.
 */
function composeShortVolumeSavedName(string $volume, string $uuid): ?string
{
    if (! str_contains($volume, '${')) {
        return null;
    }
    try {
        $source = parseDockerVolumeString($volume)['source'];
    } catch (Exception) {
        return null;
    }
    if ($source === null || sourceIsLocal($source)) {
        return null;
    }

    return "{$uuid}_".Str::slug($source, '-');
}

/**
 * The host path that the values of the resource give a short-syntax volume, or null when they give a volume name
 * or a value that is not valid.
 *
 * @param  array<string, string>  $variables  The plain values that the resource sets (composeVolumeSourceVariables())
 */
function composeShortVolumeVariableHostPath(string $volume, array $variables): ?string
{
    try {
        $source = parseDockerVolumeString($volume, $variables)['source'];
    } catch (Exception) {
        return null;
    }

    return $source !== null && sourceIsLocal($source) ? $source->value() : null;
}

/**
 * Like keepSavedComposeBindSource() for a named volume: a short-syntax volume that v4.7.6 and older saved as a
 * Docker volume stays that volume while its storage entry exists, also when its variable now gives a host path.
 * So the data does not move after the upgrade. To use the host path, the user deletes the storage entry
 * (LocalPersistentVolume::replacedComposeHostPath()).
 *
 * @param  array<string, string>  $variables  The plain values that the resource sets (composeVolumeSourceVariables())
 * @return bool Whether the parser must use the volume without the values of the resource
 */
function keepSavedComposeNamedVolume(string $volume, string $uuid, array $variables, Application|ServiceApplication|ServiceDatabase $owner, Application|Service $resource): bool
{
    $name = composeShortVolumeSavedName($volume, $uuid);
    if ($name === null || ! $owner->persistentStorages()->where('name', $name)->exists()) {
        return false;
    }
    $hostPath = composeShortVolumeVariableHostPath($volume, $variables);
    if ($hostPath !== null) {
        $resource->addComposeVolumeWarning("The volume {$name} stays in use, so its data does not move. Its variable gives the host path {$hostPath}. To use that path, copy the data, delete the storage entry {$name} in Persistent Storage, and redeploy.");
    }

    return true;
}

/**
 * The environment variables that Coolify writes to the `.env` file, where Docker Compose reads the variables of the Compose file.
 */
function composeEnvironmentVariablesQuery(Application|Service $resource, bool $isPreview = false): mixed
{
    return match (true) {
        $resource instanceof Service => $resource->environment_variables(),
        $isPreview => $resource->runtime_environment_variables_preview()->where('is_runtime', true),
        default => $resource->runtime_environment_variables()->where('is_runtime', true),
    };
}

/**
 * The values for resolveComposeVolumeSourceVariables(). A value with a variable or vault reference is left out,
 * because only Docker Compose can resolve it.
 *
 * @return array<string, string>
 */
function composeVolumeSourceVariables(Application|Service $resource, bool $isPreview = false): array
{
    return composeEnvironmentVariablesQuery($resource, $isPreview)->get()
        ->mapWithKeys(fn ($variable) => [$variable->key => (string) $variable->real_value])
        ->reject(fn (string $value) => $value === '' || str_contains($value, '$') || RemoteSecretReferences::containsReference($value))
        ->all();
}

/**
 * Resolves a long-syntax bind source as Docker Compose does with the `.env` file that Coolify writes:
 * `${VAR:-default}` is the value of VAR when the resource sets it to a non-empty value, else the default.
 * A value with a variable or vault reference stays for Docker Compose to resolve.
 *
 * @throws Exception If the value of the variable is not a shell-safe path
 */
function resolveComposeLongBindSource(string $source, Application|Service $resource, bool $isPreview = false): string
{
    if (! preg_match('/^\$\{([A-Za-z_][A-Za-z0-9_]*):-[^}]*\}$/', $source, $matches)) {
        return resolveComposeVolumeSourceVariables($source);
    }

    $value = composeEnvironmentVariablesQuery($resource, $isPreview)->where('key', $matches[1])->first()?->real_value;
    if (blank($value)) {
        return resolveComposeVolumeSourceVariables($source);
    }
    // Docker Compose expands variable references in the `.env` file, and Coolify writes vault secrets to it.
    if (str_contains($value, '$') || RemoteSecretReferences::containsReference($value)) {
        return $source;
    }

    try {
        validateShellSafePath($value, 'volume source');
    } catch (Exception $e) {
        throw new Exception("Invalid Docker volume definition (array syntax): the value of {$matches[1]} is not a safe path. ".$e->getMessage(), 0, $e);
    }

    return $value;
}

/**
 * Keep the existing array-source forms, but inspect the default that was previously skipped.
 */
function validateComposeArrayVolumeSource(string $source): void
{
    try {
        if (preg_match('/[\x00-\x1F\x7F]/', $source)) {
            throw new Exception('Invalid volume source: contains a control character.');
        }

        if (preg_match('/^\$\{[A-Za-z_][A-Za-z0-9_]*\}[\/\w.\-]*$/', $source)) {
            return;
        }

        if (preg_match('/^\$\{[A-Za-z_][A-Za-z0-9_]*:-([^}]*)\}$/', $source, $matches)) {
            validateShellSafePath($matches[1], 'volume source');

            return;
        }

        validateShellSafePath($source, 'volume source');
    } catch (Exception $e) {
        throw new Exception(
            'Invalid Docker volume definition (array syntax): '.$e->getMessage().
            ' Please use safe path names without shell metacharacters.',
            0,
            $e
        );
    }
}

/**
 * Matches one braced Compose variable: `${VAR}`, `${VAR:-default}`, `${VAR-default}`,
 * `${VAR:?error}`, `${VAR?error}`, `${VAR:+value}` or `${VAR+value}`.
 */
const COMPOSE_NETWORK_VARIABLE_PATTERN = '\$\{([A-Za-z_][A-Za-z0-9_]*)(?::?([-?+])([^}]*))?\}';

/**
 * Splits a top-level network `name:` that is one whole Compose variable (see
 * COMPOSE_NETWORK_VARIABLE_PATTERN), such as an external network that differs per server. Only the
 * `-` operators give a default; the text after `?` is an error message and after `+` a value that
 * Compose uses only when the variable is set.
 *
 * @return array{variable: string, default: ?string}|null
 */
function composeNetworkNameVariable(string $name): ?array
{
    if (preg_match('/\A'.COMPOSE_NETWORK_VARIABLE_PATTERN.'\z/', $name, $matches) !== 1) {
        return null;
    }

    return ['variable' => $matches[1], 'default' => ($matches[2] ?? '') === '-' ? $matches[3] : null];
}

/**
 * Tells if the text after the operator of a Compose variable is safe. A default or alternative value
 * becomes part of the network name; an error message only shows in the Compose error, so it can
 * also contain spaces and simple punctuation.
 */
function isSafeComposeNetworkVariableOperand(string $operator, string $operand): bool
{
    $pattern = $operator === '?' ? '/\A[A-Za-z0-9 _.,:!-]*\z/' : '/\A[A-Za-z0-9_.-]*\z/';

    return preg_match($pattern, $operand) === 1;
}

/**
 * Tells if Coolify sets this variable itself. A reference to it in the compose file must not create a
 * resource variable, because a resource variable with the same key replaces the value of Coolify.
 */
function isCoolifyProvidedComposeVariable(string $key): bool
{
    return in_array($key, [
        'COOLIFY_RESOURCE_UUID',
        'COOLIFY_CONTAINER_NAME',
        'COOLIFY_BRANCH',
        'COOLIFY_URL',
        'COOLIFY_FQDN',
        'COOLIFY_APP_NAME',
        'COOLIFY_PROJECT_NAME',
        'COOLIFY_ENVIRONMENT_NAME',
        'COOLIFY_SERVER_IP',
    ], true);
}

/**
 * Tells if a compose value starts with a reference to a variable that Coolify sets, for example `${COOLIFY_RESOURCE_UUID:-x}`.
 */
function composeValueReferencesCoolifyProvidedVariable(string $value): bool
{
    return preg_match('/\A\$\{?([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches) === 1
        && isCoolifyProvidedComposeVariable($matches[1]);
}

/**
 * Creates a resource environment variable for each top-level network `name:` that is one Compose
 * variable, like variables in `environment:`, so users can see and change it. The compose file keeps
 * the variable and Compose resolves it from the deployment `.env`. The default from the compose file
 * (or an empty value) is only the first value: a value that the user changed is kept. A variable with
 * a default that is not a valid network name is not created.
 *
 * For applications, the EnvironmentVariable `created` hook adds the preview copy.
 */
function ensureComposeNetworkNameVariables(Application|Service $resource, iterable $networks): void
{
    foreach ($networks as $network) {
        $name = data_get($network, 'name');
        $variable = is_string($name) ? composeNetworkNameVariable($name) : null;
        if ($variable === null || isCoolifyProvidedComposeVariable($variable['variable'])) {
            continue;
        }
        if ($variable['default'] !== null && ! ValidationPatterns::isValidDockerNetwork($variable['default'])) {
            continue;
        }

        $resource->environment_variables()->firstOrCreate([
            'key' => $variable['variable'],
            'resourceable_type' => get_class($resource),
            'resourceable_id' => $resource->id,
        ], [
            'value' => $variable['default'] ?? '',
            'is_preview' => false,
        ]);
    }
}

/**
 * A network `name:` may contain variables: only Docker Compose reads this value and it never runs a
 * shell; Coolify's own network commands use the network keys.
 *
 * @throws Exception If the value is not a valid network name with safe variables
 */
function validateComposeNetworkNameField(string $name): void
{
    validateComposeNetworkNameWithVariables($name, 'network name field');
}

/**
 * Allows $VAR and the braced variables of COMPOSE_NETWORK_VARIABLE_PATTERN with safe operands in a network value that Compose interpolates.
 */
function validateComposeNetworkNameWithVariables(string $name, string $context, ?string $serviceName = null): void
{
    $variable = composeNetworkNameVariable($name);
    if ($variable !== null && $variable['default'] !== null) {
        $isValid = ValidationPatterns::isValidDockerNetwork($variable['default']);
    } else {
        $hasSafeDefaults = true;
        $withoutVariables = preg_replace_callback(
            '/'.COMPOSE_NETWORK_VARIABLE_PATTERN.'|\$[A-Za-z_][A-Za-z0-9_]*/',
            function (array $matches) use (&$hasSafeDefaults): string {
                if (! isSafeComposeNetworkVariableOperand($matches[2] ?? '', $matches[3] ?? '')) {
                    $hasSafeDefaults = false;
                }

                return 'x';
            },
            $name,
        );
        $isValid = $hasSafeDefaults && $withoutVariables !== null && ValidationPatterns::isValidDockerNetwork($withoutVariables);
    }

    if (! $isValid) {
        throw invalidComposeNetworkNameException($name, $context, $serviceName, allowsVariables: true);
    }
}

/**
 * Reject Docker Compose network names that are not valid Docker identifiers.
 *
 * @throws Exception If the network name is not a valid Docker network identifier
 */
function validateComposeNetworkName(string $networkName, string $context = 'network name', ?string $serviceName = null): void
{
    if ($networkName === '' || ! ValidationPatterns::isValidDockerNetwork($networkName)) {
        throw invalidComposeNetworkNameException($networkName, $context, $serviceName);
    }
}

function invalidComposeNetworkNameException(string $name, string $context, ?string $serviceName, bool $allowsVariables = false): Exception
{
    $location = $serviceName === null ? '' : " in service {$serviceName}";

    return new Exception(
        "Invalid Docker Compose {$context} \"{$name}\"{$location}. Network names must start with an alphanumeric character and contain only alphanumeric characters, dots, hyphens, and underscores"
        .($allowsVariables ? ', and can use variables such as ${NETWORK:-default}.' : '.')
    );
}

/**
 * Tells if a top-level Compose volume declaration is external: `external: true` or the old
 * `external: {name: x}` syntax. Docker Compose does not create or remove an external volume.
 */
function isComposeExternalVolume(mixed $declaration): bool
{
    if (! is_array($declaration) || ! array_key_exists('external', $declaration)) {
        return false;
    }
    $external = $declaration['external'];
    if (is_array($external)) {
        return true;
    }

    return filter_var($external, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Returns the top-level declaration of a volume source when the Compose file declares it as
 * external, and null for all other sources. The parsers use an external volume as written: no
 * "{uuid}_" prefix and no LocalPersistentVolume row, so that Coolify never removes a volume that it
 * does not own. A preview deployment never uses an external volume; it gets its own volume
 * "{uuid}_{volume}-pr-{id}" like any other named volume.
 *
 * @param  iterable<array-key, mixed>  $topLevelVolumes
 * @return array<string, mixed>|null
 *
 * @throws Exception If the external volume has a name that is not safe (see validateComposeExternalVolume())
 */
function composeExternalVolumeDeclaration(iterable $topLevelVolumes, string $source): ?array
{
    $declaration = collect($topLevelVolumes)->get($source);
    if (! isComposeExternalVolume($declaration)) {
        return null;
    }
    validateComposeExternalVolume($source, $declaration);

    return $declaration;
}

/**
 * Tells if a parser uses the volume source as written because it is an external volume.
 *
 * Only for production deployments: a preview deployment always gets its own volume.
 *
 * Before Coolify used external volumes as written, the parsers renamed them like all other
 * volumes (the old name, for example "{uuid}_{volume}"), so the resource wrote its data into the
 * renamed volume. When the owner still has the storage entry
 * with the old name, the parser must keep the old name, or the resource loses its data. Then
 * this function records a warning on the resource and returns false. When the user deletes that
 * storage entry, the next parse uses the external volume.
 *
 * Returns false also for a source that is not external. Then the parser continues as usual.
 *
 * @param  iterable<array-key, mixed>  $topLevelVolumes
 * @param  string  $legacyName  the name that the parser gave the volume before it used external volumes as written
 *
 * @throws Exception If the external volume has a name that is not safe (see validateComposeExternalVolume())
 */
function useComposeExternalVolumeAsWritten(Application|Service $resource, Application|ServiceApplication|ServiceDatabase $owner, iterable $topLevelVolumes, string $source, string $legacyName): bool
{
    $declaration = composeExternalVolumeDeclaration($topLevelVolumes, $source);
    if ($declaration === null) {
        return false;
    }
    if ($legacyName === $source) {
        return true;
    }

    $hasLegacyStorage = LocalPersistentVolume::query()
        ->where('resource_type', get_class($owner))
        ->where('resource_id', $owner->getKey())
        ->where('name', $legacyName)
        ->exists();
    if (! $hasLegacyStorage) {
        return true;
    }

    $resource->addComposeVolumeWarning(composeLegacyExternalVolumeWarning($source, $declaration, $legacyName));

    return false;
}

/**
 * The name that the legacy application parsers (compose_parsing_version 1 and 2) gave a volume
 * before they used external volumes as written: parser version 1 kept the name and added only the
 * preview suffix, parser version 2 added the "{uuid}-" prefix too.
 */
function legacyApplicationComposeVolumeName(Application $resource, string $source, int $pull_request_id): string
{
    $name = addPreviewDeploymentSuffix($source, $pull_request_id);

    return $resource->compose_parsing_version === '2' ? "{$resource->uuid}-{$name}" : $name;
}

/**
 * The top-level declaration of a volume that a legacy application parser (compose_parsing_version 1
 * and 2) renamed. Only applications from before Coolify kept driver options use these parsers, and
 * they have no storage entries, so their volumes keep the old name-only declaration. Docker created
 * them without the options, and Docker Compose would otherwise ask to recreate them.
 *
 * @return array{name: string}
 */
function legacyApplicationRenamedVolumeDeclaration(string $name): array
{
    return ['name' => $name];
}

/**
 * Records a warning when a legacy Compose application (parser version 1 or 2) does not use an
 * external volume as written. These parsers keep the old volume name (see
 * legacyApplicationComposeVolumeName()), so the resource keeps its data. The parser version 1 keeps
 * the name of a production volume, so it uses the external volume and gets no warning. A preview
 * always uses its own volume, so it gets no warning either.
 *
 * The volume names are not validated here, so that the legacy parsers keep their old behavior. The
 * warning shows the Docker volume name only when it is a literal, valid Docker volume name.
 *
 * @param  iterable<array-key, mixed>  $topLevelVolumes  the top-level volumes as declared in the Compose file
 */
function warnLegacyApplicationComposeExternalVolume(Application $resource, iterable $topLevelVolumes, string $source, int $pull_request_id): void
{
    if ($pull_request_id !== 0) {
        return;
    }
    $declaration = collect($topLevelVolumes)->get($source);
    if (! isComposeExternalVolume($declaration)) {
        return;
    }
    $keptName = legacyApplicationComposeVolumeName($resource, $source, $pull_request_id);
    if ($keptName === $source) {
        return;
    }

    $dockerVolume = data_get($declaration, 'name') ?? data_get($declaration, 'external.name') ?? $source;
    $external = ! is_string($dockerVolume) || $dockerVolume === $source || preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $dockerVolume) !== 1
        ? 'external'
        : "external (Docker volume '{$dockerVolume}')";

    $resource->addComposeVolumeWarning("Volume '{$source}' is declared as {$external}, but this application uses an old Compose parser, so Coolify uses '{$keptName}'. To use the external volume, copy your data into it, then clone this application or create it again.");
}

/**
 * The warning for an external volume that the resource does not use yet (see useComposeExternalVolumeAsWritten()).
 * The volume names are validated and contain no secrets.
 *
 * @param  array<string, mixed>  $declaration
 */
function composeLegacyExternalVolumeWarning(string $source, array $declaration, string $legacyName): string
{
    $dockerVolume = composeExternalVolumeDockerName($source, $declaration);
    $external = $dockerVolume === $source ? 'external' : "external (Docker volume '{$dockerVolume}')";

    return "Volume '{$source}' is declared as {$external}, but Coolify still uses '{$legacyName}' because this resource used it before. To use the external volume, copy your data into it and delete the storage entry '{$legacyName}', then redeploy.";
}

/**
 * The Docker volume that an external Compose volume declaration refers to: `name:`, the old
 * `external: {name: x}` syntax, or else the key.
 *
 * @param  array<string, mixed>  $declaration
 */
function composeExternalVolumeDockerName(string $key, array $declaration): string
{
    return (string) (data_get($declaration, 'name') ?? data_get($declaration, 'external.name') ?? $key);
}

/**
 * The top-level declaration of a volume that a parser renamed (for example `data` to `{uuid}_data`).
 * It keeps the options of the original declaration, such as `driver`, `driver_opts` and `labels`, so a
 * local volume that binds a host folder still binds it. Variables in the options stay as written;
 * Docker Compose resolves them from `.env`. The parser renamed the volume, so the declaration gets the
 * new name, and it is not external: Docker Compose creates the renamed volume.
 *
 * @return array<string, mixed>
 */
function composeRenamedVolumeDeclaration(mixed $declaration, string $name): array
{
    $renamed = is_array($declaration) ? $declaration : [];
    unset($renamed['external'], $renamed['name']);
    $renamed['name'] = $name;

    return $renamed;
}

/**
 * The top-level declaration of a renamed volume with its storage entry. A volume that existed before
 * Coolify kept the driver options (see the `ignores_compose_driver_options` flag) keeps its old
 * name-only declaration: Docker created it without the options, and Docker Compose would otherwise
 * ask to recreate it on every deployment.
 *
 * A preview volume does not get `driver_opts` that name a host device (for example a bind mount of a
 * host folder): the preview would otherwise mount the same folder or disk as the production volume.
 *
 * @return array<string, mixed>
 */
function composeRenamedVolumeDeclarationFor(mixed $declaration, string $name, ?LocalPersistentVolume $volume, bool $isPreview = false): array
{
    if ($volume?->ignores_compose_driver_options) {
        return ['name' => $name];
    }

    $renamed = composeRenamedVolumeDeclaration($declaration, $name);
    if ($isPreview && filled(data_get($renamed, 'driver_opts.device')) && data_get($renamed, 'driver_opts.type') !== 'tmpfs') {
        unset($renamed['driver_opts']);
    }

    return $renamed;
}

/**
 * The Docker volumes that a Compose file declares as external. Returns an empty list for a
 * Compose file that is empty or not valid YAML.
 *
 * @return list<string>
 */
function composeExternalVolumeDockerNames(?string $compose): array
{
    if (blank($compose)) {
        return [];
    }
    try {
        $volumes = data_get(parseDockerComposeYaml($compose), 'volumes');
    } catch (Throwable) {
        return [];
    }
    if (! is_array($volumes)) {
        return [];
    }

    return collect($volumes)
        ->filter(fn (mixed $declaration): bool => isComposeExternalVolume($declaration))
        ->map(fn (array $declaration, int|string $key): string => composeExternalVolumeDockerName((string) $key, $declaration))
        ->values()
        ->all();
}

/**
 * The external volumes that the services of a Compose file mount, one entry for each volume and
 * service. Returns an empty list for a Compose file that is empty or not valid YAML.
 *
 * @return list<array{key: string, dockerName: string, service: string, mountPaths: list<string>}>
 */
function composeExternalVolumeMounts(?string $compose): array
{
    if (blank($compose)) {
        return [];
    }
    try {
        $yaml = parseDockerComposeYaml($compose);
    } catch (Throwable) {
        return [];
    }
    $topLevelVolumes = data_get($yaml, 'volumes');
    $services = data_get($yaml, 'services');
    if (! is_array($topLevelVolumes) || ! is_array($services)) {
        return [];
    }

    $mounts = [];
    foreach ($services as $serviceName => $service) {
        $serviceVolumes = data_get($service, 'volumes');
        if (! is_array($serviceVolumes)) {
            continue;
        }
        foreach ($serviceVolumes as $volume) {
            $parsed = match (true) {
                is_string($volume) => parseDockerVolumeString($volume),
                is_array($volume) => $volume,
                default => [],
            };
            // parseDockerVolumeString() returns Stringable values.
            $source = data_get($parsed, 'source');
            $target = data_get($parsed, 'target');
            if (! (is_scalar($source) || $source instanceof Stringable) || ! (is_scalar($target) || $target instanceof Stringable)) {
                continue;
            }
            $source = (string) $source;
            $target = (string) $target;
            $declaration = $topLevelVolumes[$source] ?? null;
            if (! isComposeExternalVolume($declaration)) {
                continue;
            }

            $dockerName = data_get($declaration, 'name') ?? data_get($declaration, 'external.name') ?? $source;
            $mountKey = $source."\0".$serviceName;
            $mounts[$mountKey] ??= [
                'key' => $source,
                'dockerName' => is_scalar($dockerName) ? (string) $dockerName : $source,
                'service' => (string) $serviceName,
                'mountPaths' => [],
            ];
            if (! in_array($target, $mounts[$mountKey]['mountPaths'], true)) {
                $mounts[$mountKey]['mountPaths'][] = $target;
            }
        }
    }

    return array_values($mounts);
}

/**
 * The key of an external volume must be a literal Docker volume name, because Docker Compose does not
 * resolve variables in keys. The name (`name:` or the old `external: {name: x}`) may also contain
 * variables (see isComposeVolumeNameWithVariables()): Docker Compose resolves them from the deployment
 * `.env` when it starts the resource. Coolify never removes an external volume, because it gets no
 * storage entry, so Coolify does not need the resolved name.
 *
 * @param  array<string, mixed>  $declaration
 *
 * @throws Exception If the key or the name is not a valid Docker volume name
 */
function validateComposeExternalVolume(int|string $key, array $declaration): void
{
    $key = (string) $key;
    if (preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $key) !== 1) {
        throw new Exception(
            'Invalid external Docker Compose volume. Volume names must start with an alphanumeric character and contain only alphanumeric characters, dots, hyphens, and underscores.'
        );
    }
    $names = [];
    if (array_key_exists('name', $declaration)) {
        $names[] = $declaration['name'];
    }
    if (is_array($declaration['external'] ?? null) && array_key_exists('name', $declaration['external'])) {
        $names[] = $declaration['external']['name'];
    }

    foreach ($names as $name) {
        if (! is_string($name) || (preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $name) !== 1 && ! isComposeVolumeNameWithVariables($name))) {
            throw new Exception(
                "Invalid external Docker Compose volume {$key}. The name must be a Docker volume name (alphanumeric characters, dots, hyphens, and underscores) and can contain variables such as \${NAME} or \${NAME:-default}."
            );
        }
    }
}

/**
 * Tells if a volume name contains Compose variables and otherwise only Docker volume name characters.
 * The variables are `$NAME`, `${NAME}`, `${NAME-default}` and `${NAME:-default}`, with a default
 * that contains only Docker volume name characters.
 */
function isComposeVolumeNameWithVariables(string $name): bool
{
    return str_contains($name, '$')
        && preg_match('/^(?:[A-Za-z0-9._-]|\$[A-Za-z_][A-Za-z0-9_]*|\$\{[A-Za-z_][A-Za-z0-9_]*(?::?-[A-Za-z0-9._-]*)?\})+$/', $name) === 1;
}

/**
 * Validates a Docker volume string (format: "source:target" or "source:target:mode")
 *
 * @param  string  $volumeString  The volume string to validate
 *
 * @throws Exception If the volume string contains command injection attempts
 */
function validateVolumeStringForInjection(string $volumeString): void
{
    // Canonical parsing also validates and throws on unsafe input
    parseDockerVolumeString($volumeString);
}

/**
 * Rejects a Compose variable in the container path of a volume with a message that tells the user what to do.
 * Coolify stores the container path and uses it in backups, file storages, and transfers, so it must be a literal path.
 *
 * @throws Exception If the target contains a variable
 */
function rejectComposeVolumeTargetVariable(string $target, string $prefix): void
{
    if (str_contains($target, '${')) {
        throw new Exception(
            "{$prefix}: the container path {$target} contains a variable. Coolify does not support variables in the container path of a volume. Write the path without variables, for example /app/data."
        );
    }
}

function parseDockerVolumeString(string $volumeString, ?array $variables = null): array
{
    $volumeString = trim($volumeString);
    $source = null;
    $target = null;
    $mode = null;

    // A Compose variable such as ${VAR:-/data} can contain a colon, so the split does not look inside variables.
    $variableTexts = [];
    $volumeString = preg_replace_callback('/\$\{[^}]*\}/', function (array $match) use (&$variableTexts): string {
        $variableTexts[] = $match[0];

        return "\0".(count($variableTexts) - 1)."\0";
    }, $volumeString);
    $restoreVariables = fn (?string $part): ?string => $part === null ? null : preg_replace_callback(
        '/\0(\d+)\0/',
        fn (array $match): string => $variableTexts[(int) $match[1]] ?? $match[0],
        $part
    );

    $colonCount = substr_count($volumeString, ':');

    if ($colonCount === 0) {
        // Named volume without target (unusual but valid)
        // Example: "myvolume"
        $source = $volumeString;
        $target = $volumeString;
    } elseif ($colonCount === 1) {
        // Simple volume mapping
        // Examples: "gitea:/data" or "./data:/app/data" or "${VAR:-default}:/data"
        $parts = explode(':', $volumeString);
        $source = $parts[0];
        $target = $parts[1];
    } elseif ($colonCount === 2) {
        // Volume with mode OR Windows path
        if (preg_match('/^[A-Za-z]:/', $volumeString)) {
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

            if (array_diff(array_map('trim', explode(',', $possibleMode)), $validModes) === []) {
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

                if (array_diff(array_map('trim', explode(',', $possibleMode)), $validModes) === []) {
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

    $source = $restoreVariables($source);
    $target = $restoreVariables($target);
    $mode = $restoreVariables($mode);

    if ($source) {
        $source = resolveComposeVolumeSourceVariables((string) $source, $variables);
    }

    // Validate source path for command injection attempts
    // We validate the final source value after environment variable processing
    if ($source !== null) {
        // A ${VAR} that is left is resolved by Docker Compose, not by a shell. The text around it must be safe.
        try {
            validateShellSafePath(preg_replace('/\$\{[A-Za-z_][A-Za-z0-9_]*\}/', '', $source), 'volume source');
        } catch (Exception $e) {
            // Re-throw with more context about the volume string
            throw new Exception(
                'Invalid Docker volume definition: '.$e->getMessage().
                ' Please use safe path names without shell metacharacters.'
            );
        }

        // Coolify creates and manages the host path, so it must know the value of each variable in it.
        if ($variables !== null && sourceIsLocal(str($source)) && preg_match('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}/', $source, $matches)) {
            throw new Exception(
                "Invalid Docker volume definition: the host path {$source} uses the variable {$matches[1]}, which has no value. ".
                "Set {$matches[1]} in the environment variables of the resource, or add a default, for example \${{$matches[1]}:-/data}."
            );
        }
    }

    // Also validate target path
    if ($target !== null) {
        $targetStr = is_string($target) ? $target : $target;
        // Target paths in containers are typically absolute paths, so we validate them too
        // but they're less likely to be dangerous since they're not used in host commands
        // Still, defense in depth is important
        rejectComposeVolumeTargetVariable($targetStr, 'Invalid Docker volume definition');
        try {
            validateShellSafePath($targetStr, 'volume target');
        } catch (Exception $e) {
            throw new Exception(
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

function addTraefikDockerNetworkLabel(Collection $labels, string $network): Collection
{
    $hasUserDefinedNetwork = $labels->contains(
        fn ($label): bool => is_string($label) && str($label)->before('=')->is('traefik.docker.network')
    );

    if (! $hasUserDefinedNetwork) {
        $labels->push("traefik.docker.network={$network}");
    }

    return $labels;
}

/**
 * Remove one-time fields from long-form volume entries without reformatting the rest of the source.
 * Fall back to a YAML dump when the source uses a form that the line edit cannot handle safely.
 *
 * @param  array<string, mixed>  $cleanedYaml
 * @param  array<int, string>  $fields
 */
function removeComposeVolumeFieldsPreservingComments(string $source, array $cleanedYaml, array $fields): string
{
    $context = [];
    $removeIndent = null;
    $blockIndent = null;
    $result = [];

    foreach (preg_split('/(?<=\n)/', $source) as $line) {
        $text = rtrim($line, "\r\n");
        $indent = strspn($text, ' ');

        if ($removeIndent !== null) {
            if (trim($text) === '' || $indent > $removeIndent) {
                continue;
            }
            $removeIndent = null;
        }

        if ($blockIndent !== null) {
            if (trim($text) === '' || $indent > $blockIndent) {
                $result[] = $line;

                continue;
            }
            $blockIndent = null;
        }

        if (trim($text) === '' || str_starts_with(ltrim($text), '#')) {
            $result[] = $line;

            continue;
        }

        while ($context && end($context)['indent'] >= $indent) {
            array_pop($context);
        }

        $body = substr($text, $indent);
        $isListItem = preg_match('/^-\s+/', $body) === 1;
        if ($isListItem) {
            $context[] = ['indent' => $indent, 'key' => '[]'];
            $body = preg_replace('/^-\s+/', '', $body);
        }

        if (preg_match('/^([\w.-]+|"[^"]+"|\x27[^\x27]+\x27)\s*:(.*)$/', $body, $matches)) {
            $key = trim($matches[1], "\"'");
            $path = array_column($context, 'key');
            if (! $isListItem && count($path) === 4 && $path[0] === 'services' && $path[2] === 'volumes' && $path[3] === '[]' && in_array($key, $fields, true)) {
                $removeIndent = $indent;

                continue;
            }

            $value = trim($matches[2]);
            if ($value === '' || str_starts_with($value, '#')) {
                $context[] = ['indent' => $indent, 'key' => $key];
            } elseif (preg_match('/^[|>][+-]?(?:\s+#.*)?$/', $value)) {
                $blockIndent = $indent;
            }
        }

        $result[] = $line;
    }

    $candidate = implode('', $result);
    try {
        if (parseDockerComposeYaml($candidate) === $cleanedYaml) {
            return $candidate;
        }
    } catch (Exception) {
        // Use the validated parsed result if the line edit is not valid YAML.
    }

    return Yaml::dump($cleanedYaml, 10, 2);
}

function applicationParser(Application $resource, int $pull_request_id = 0, ?int $preview_id = null, ?string $commit = null): Collection
{
    $resource->resetComposeVolumeWarnings();
    $uuid = data_get($resource, 'uuid');
    $compose = data_get($resource, 'docker_compose_raw');
    // Store original compose for later use to update docker_compose_raw with content removed
    $originalCompose = $compose;
    if (! $compose) {
        return collect([]);
    }

    $pullRequestId = $pull_request_id;
    $isPullRequest = $pullRequestId == 0 ? false : true;
    $renamesServices = $isPullRequest && $resource->renamesPreviewComposeServices();
    $pullRequestPreview = $isPullRequest
        ? ($resource->previews()->find($preview_id) ?? ApplicationPreview::where('application_id', $resource->id)->where('pull_request_id', $pullRequestId)->first())
        : null;
    // A public preview can run the compose file of a fork pull request. A preview from before v4.5.1 keeps the production values.
    $isPublicPullRequest = $isPullRequest && (bool) $resource->settings?->hasPublicPrDeployments() && ! $pullRequestPreview?->keeps_production_values;
    // The Compose file of a pull request deploys only that preview: it reads the rows of the application, but does not add or change them.
    $keepsApplicationRows = $isPullRequest && $resource->usesPullRequestComposeFile;
    $server = data_get($resource, 'destination.server');
    try {
        $yaml = parseDockerComposeYaml($compose);
    } catch (Exception) {
        return collect([]);
    }
    $services = data_get($yaml, 'services', []);
    if (! is_array($services)) {
        return collect([]);
    }
    $topLevel = collect([
        'volumes' => collect(data_get($yaml, 'volumes', [])),
        'networks' => collect(data_get($yaml, 'networks', [])),
        'configs' => collect(data_get($yaml, 'configs', [])),
        'secrets' => collect(data_get($yaml, 'secrets', [])),
    ]);
    if (! $keepsApplicationRows) {
        ensureComposeNetworkNameVariables($resource, $topLevel->get('networks'));
    }
    // A volume without options ("data:") stays declared: a service from `extends` can mount it as written.
    $topLevel->put('volumes', $topLevel->get('volumes')->map(fn ($volume) => $volume ?? []));
    // Get the base docker network
    $baseNetwork = collect([$uuid]);
    if ($isPullRequest) {
        $baseNetwork = collect(["{$uuid}-{$pullRequestId}"]);
    }

    $legacyNullEnvironmentVariables = $resource->environment_variables()->get();
    if ($isPullRequest) {
        $legacyNullEnvironmentVariables = $resource->environment_variables_preview()->get()
            ->concat($legacyNullEnvironmentVariables)->unique('key');
    }

    $parsedServices = collect([]);

    $allMagicEnvironments = collect([]);
    foreach ($services as $serviceName => $service) {
        // Validate service name for command injection
        try {
            validateShellSafePath($serviceName, 'service name');
        } catch (Exception $e) {
            throw new Exception(
                'Invalid Docker Compose service name: '.$e->getMessage().
                ' Service names must not contain shell metacharacters.'
            );
        }
        validateComposeServiceName((string) $serviceName);
        validateComposeServiceScale((string) $serviceName, $service);

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

        // filter and add magic environments
        foreach ($environment as $key => $value) {
            // Get all SERVICE_ variables from keys and values
            $key = str($key);
            $value = str($value);
            $regex = '/\$(\{?([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\}?)/';
            preg_match_all($regex, $value, $valueMatches);
            if (count($valueMatches[2]) > 0) {
                foreach ($valueMatches[2] as $match) {
                    $match = str($match);
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

                if ($value && get_class($value) === Stringable::class && $value->startsWith('/')) {
                    $path = $value->value();
                    if ($path !== '/') {
                        $fqdn = "$fqdn$path";
                    }
                }
                $fqdnWithPort = $fqdn;
                if ($port) {
                    $fqdnWithPort = "$fqdn:$port";
                }
                if (is_null($resource->fqdn) && ! $keepsApplicationRows) {
                    data_forget($resource, 'environment_variables');
                    data_forget($resource, 'environment_variables_preview');
                    $resource->fqdn = $fqdnWithPort;
                    $resource->save();
                }

                // The stored variables belong to the main application; a preview deployment gets its own domains.
                if (! $parsed['has_port'] && ! $isPullRequest) {
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_preview' => false,
                    ]);
                }
                if ($parsed['has_port'] && ! $isPullRequest) {

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

            // Also populate docker_compose_domains for dockercompose apps from direct SERVICE_* declarations.
            if ($resource->build_pack === 'dockercompose' && ($key->startsWith('SERVICE_FQDN_') || $key->startsWith('SERVICE_URL_'))) {
                $parsed = parseServiceEnvironmentVariable($key->value());
                $normalizedServiceName = normalizeComposeServiceName((string) $parsed['service_name']);
                $originalServiceName = findComposeServiceName($normalizedServiceName, array_keys($services));
                if ($originalServiceName !== null) {
                    $domains = json_decode(data_get($resource, 'docker_compose_domains') ?: '[]', true) ?: [];
                    if (! $keepsApplicationRows && ! hasComposeServiceDomainEntry($domains, $originalServiceName)) {
                        $serviceNameForDomain = str($parsed['service_name'])->replace('_', '-')->value();
                        $domainValue = generateUrl(server: $server, random: "$serviceNameForDomain-$uuid");
                        if ($value && get_class($value) === Stringable::class && $value->startsWith('/')) {
                            $path = $value->value();
                            if ($path !== '/') {
                                $domainValue = "$domainValue$path";
                            }
                        }
                        if ($parsed['port'] && is_numeric($parsed['port'])) {
                            $domainValue = "$domainValue:{$parsed['port']}";
                        }
                        $resource->docker_compose_domains = json_encode(putComposeServiceDomain(
                            $domains,
                            $originalServiceName,
                            $domainValue,
                            array_keys($services),
                        ));
                        $resource->save();
                    }
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
                    // Env var SERVICE_* names still use underscores; domain map keys use original compose names.
                    $serviceName = normalizeComposeServiceName((string) $serviceName);

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

                    // The stored variables belong to the main application; a preview deployment gets its own domains.
                    if (! $isPullRequest) {
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
                    }

                    if ($resource->build_pack === 'dockercompose') {
                        // Match env-derived name to the real compose service key (hyphens/dots preserved).
                        $composeServiceName = findComposeServiceName($serviceName, array_keys($services));

                        // Only add domain if the service exists
                        if ($composeServiceName !== null) {
                            $domains = json_decode(data_get($resource, 'docker_compose_domains') ?: '[]', true) ?: [];
                            // Update domain using URL with port if applicable
                            $domainValue = $port ? $urlWithPort : $url;

                            if (! hasComposeServiceDomainEntry($domains, $composeServiceName)) {
                                $resource->docker_compose_domains = json_encode(putComposeServiceDomain(
                                    $domains,
                                    $composeServiceName,
                                    $domainValue,
                                    array_keys($services),
                                ));
                                $resource->save();
                            }
                        }
                    }
                } elseif ($isPullRequest) {
                    // A preview deployment reads its preview variables, so a pull request parse generates the value there.
                    $previewVariable = $resource->environment_variables_preview()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => generateEnvValue($command, $resource),
                        'is_preview' => true,
                    ]);
                    // A public preview does not get the production secret; it gets its own value.
                    if ($isPublicPullRequest && ! $previewVariable->wasRecentlyCreated
                        && $previewVariable->value === $resource->environment_variables()->where('key', $key->value())->first()?->value) {
                        $previewVariable->update(['value' => generateEnvValue($command, $resource)]);
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

    // A pull request parse keeps its magic variables in the preview variables (see above), not in the production variables.
    $isPullRequestMagicVariable = fn (string $key): bool => $isPullRequest && $allMagicEnvironments->has($key);

    // generate SERVICE_NAME variables for docker compose services
    $serviceNameEnvironments = collect([]);
    if ($resource->build_pack === 'dockercompose') {
        $serviceNameEnvironments = generateDockerComposeServiceName($services, $renamesServices ? $pullRequestId : 0);
    }

    // Parse the rest of the services
    $previewOwnVolumes = collect([]);
    foreach ($services as $serviceName => $service) {
        $image = data_get_str($service, 'image');
        $restart = data_get_str($service, 'restart', RESTART_MODE);
        $logging = data_get($service, 'logging');

        if ($server->isLogDrainEnabled()) {
            if ($resource->isLogDrainEnabled()) {
                $logging = generate_log_drain_configuration($server);
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
                    $volumeVariables = str_contains($volume, '${')
                        ? $volumeSourceVariables ??= composeVolumeSourceVariables($resource, $isPullRequest)
                        : null;
                    $keepsSavedVolume = $volumeVariables !== null
                        && keepSavedComposeNamedVolume($volume, $uuid, $volumeVariables, $originalResource, $resource);
                    $parsed = parseDockerVolumeString($volume, $keepsSavedVolume ? null : $volumeVariables);
                    $source = $parsed['source'];
                    $target = $parsed['target'];
                    if ($keepsSavedVolume && ! $isPullRequest) {
                        // An earlier parse of this version mounted the host path. That bind is not mounted anymore.
                        forgetComposeBindStorage($originalResource, $target->value());
                    }
                    // Mode is available in $parsed['mode'] if needed
                    $foundConfig = findLocalFileVolumeConfig($originalResource, $source, $target, is_string($volume) ? $volume : null);
                    if (sourceIsLocal($source)) {
                        $type = str('bind');
                        if ($foundConfig) {
                            $content = data_get($foundConfig, 'content');
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
                    $isDirectory = data_get($volume, 'is_directory') ?? data_get($volume, 'isDirectory');

                    // Validate source and target for command injection (array/long syntax)
                    if ($source !== null && ! empty($source->value())) {
                        validateComposeArrayVolumeSource($source->value());
                        // A named volume keeps its source as written, so its volume name does not change.
                        if ($type->value() === 'bind') {
                            $source = str(resolveComposeLongBindSource($source->value(), $resource, $isPullRequest));
                        }
                    }
                    validateComposeContentVolumeSource($volume);
                    if ($target !== null && ! empty($target->value())) {
                        try {
                            validateShellSafePath($target->value(), 'volume target');
                        } catch (Exception $e) {
                            throw new Exception(
                                'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                ' Please use safe path names without shell metacharacters.'
                            );
                        }
                    }

                    $foundConfig = findLocalFileVolumeConfig($originalResource, $source, $target, is_string($volume) ? $volume : null);
                    if ($foundConfig) {
                        $content = data_get($foundConfig, 'content');
                        $isDirectory = data_get($foundConfig, 'is_directory');
                    } else {
                        // Without is_directory, a bind without content is a directory. An explicit `is_directory: false` stays a file.
                        $isDirectory = is_null($isDirectory) ? is_null($content) : (bool) $isDirectory;
                    }
                }
                if ($type->value() === 'bind') {
                    if (in_array($source->value(), ['/var/run/docker.sock', '/tmp', '/tmp/'], true)) {
                        if (is_string($volume)) {
                            $volume = $source->value().':'.$target->value();
                            if (isset($parsed['mode']) && $parsed['mode']) {
                                $volume .= ':'.$parsed['mode']->value();
                            }
                        } else {
                            $volume = composeLongBindVolume($volume, $source->value());
                        }
                    } else {
                        if ((int) $resource->compose_parsing_version >= 4) {
                            $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        } else {
                            $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        }
                        $source = resolveComposeBindSource($source, $mainDirectory, $foundConfig?->fs_path);
                        [$source, $variableSource] = keepSavedComposeBindSource($source, is_string($volume) ? $volume : null, $mainDirectory, $foundConfig, $resource);
                        // The file storage keeps the path of the application; a preview deployment adds the suffix (LocalFileVolume::forPullRequest()).
                        $storedSource = $source;
                        $isPreviewSuffixEnabled = $foundConfig
                            ? (bool) data_get($foundConfig, 'is_preview_suffix_enabled', true)
                            : true;
                        if ($isPullRequest && $isPreviewSuffixEnabled) {
                            $source = str(addPreviewDeploymentSuffix($source, $pull_request_id));
                        }
                        // With `bind.create_host_path: false`, the host path must exist already: Coolify does not create or manage it.
                        // A preview with the Compose file of its pull request writes the file storages of the application and does not change them.
                        $keepsMissingHostPath = composeBindKeepsMissingHostPath($volume);
                        if (! $keepsApplicationRows && ! $keepsMissingHostPath) {
                            LocalFileVolume::updateOrCreate(
                                [
                                    'fs_path' => (string) $storedSource,
                                    'mount_path' => $target,
                                    'resource_id' => $originalResource->id,
                                    'resource_type' => get_class($originalResource),
                                ],
                                [
                                    'fs_path' => (string) $storedSource,
                                    'variable_fs_path' => $variableSource,
                                    'mount_path' => $target,
                                    'content' => $content,
                                    'is_directory' => $isDirectory,
                                    'resource_id' => $originalResource->id,
                                    'resource_type' => get_class($originalResource),
                                ]
                            );
                        } elseif (! $keepsApplicationRows) {
                            forgetComposeBindStorage($originalResource, $storedSource->value(), $target->value());
                        }
                        // The file storage keeps the path that Coolify writes; the Docker daemon may need another one.
                        $source = $source->replace($mainDirectory, devHostDockerPath($server, $mainDirectory->value()));
                        if (is_array($volume)) {
                            $volume = composeLongBindVolume($volume, $source->value());
                        } else {
                            $volume = "$source:$target";
                            if (isset($parsed['mode']) && $parsed['mode']) {
                                $volume .= ':'.$parsed['mode']->value();
                            }
                        }
                    }
                } elseif ($type->value() === 'volume') {
                    // A preview never mounts an external or network (NFS, CIFS) volume: two deployments
                    // that write into the same data directory (for example two databases) can corrupt it.
                    // The preview gets its own volume with the name that older Coolify versions gave
                    // it, so it keeps its data, and the preview cleanup removes it.
                    $declaration = $topLevel->get('volumes')->get($source->value());
                    $isNetworkVolume = in_array(data_get($declaration, 'driver_opts.type'), ['cifs', 'nfs'], true);
                    $isPreviewOwnVolume = $isPullRequest
                        && ($isNetworkVolume || composeExternalVolumeDeclaration($topLevel->get('volumes'), $source->value()) !== null);
                    if (! $isPullRequest && useComposeExternalVolumeAsWritten($resource, $originalResource, $topLevel->get('volumes'), $source->value(), "{$uuid}_".Str::slug($source, '-'))) {
                        // The external volume gets no row, so Coolify never removes it.
                        $volumesParsed->put($index, $volume);

                        continue;
                    }
                    if (! $isPullRequest && $isNetworkVolume) {
                        // Network volumes are used as written.
                        $volumesParsed->put($index, $volume);

                        continue;
                    }
                    if ($isPreviewOwnVolume) {
                        $previewOwnVolumes->put($source->value(), true);
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
                    $persistentVolume = LocalPersistentVolume::updateOrCreate(
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
                    $topLevel->get('volumes')->put($name, $isPreviewOwnVolume
                        ? ['name' => $name]
                        : composeRenamedVolumeDeclarationFor($declaration, $name, $persistentVolume, $isPullRequest));
                }
                $volumesParsed->put($index, $volume);
            }
        }

        if (! $use_network_mode) {
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
                } elseif (is_array($network) || is_null($network)) {
                    // networks:
                    //   default:
                    //     ipv4_address: 192.168.203.254
                    //   internal: null
                    $networks_temp->put($key, $network);
                }
            }
            // On the preview network, a preview service also answers to its other name: the Compose name
            // when the preview renames its services, otherwise the "<name>-pr-<id>" name of older versions.
            $previewAlias = $renamesServices ? (string) $serviceName : addPreviewDeploymentSuffix((string) $serviceName, $pullRequestId);
            foreach ($baseNetwork as $key => $network) {
                $networks_temp->put($network, $isPullRequest ? ['aliases' => [$previewAlias]] : null);
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

        /**
         * Finds or adds a variable of the application. With the Compose file of a pull request it only finds one.
         *
         * @param  array{key: mixed, resourceable_type: string, resourceable_id: int}  $attributes
         * @param  array<string, mixed>  $values
         */
        $applicationVariable = function (array $attributes, array $values) use ($resource, $keepsApplicationRows): ?EnvironmentVariable {
            if ($keepsApplicationRows) {
                return $resource->environment_variables()->where('key', (string) $attributes['key'])->first();
            }

            return $resource->environment_variables()->firstOrCreate($attributes, $values);
        };
        $passthroughEnvironmentKeys = extractDockerComposePassthroughKeys(data_get($service, 'environment', []));
        $normalEnvironments = $environment->diffKeys($allMagicEnvironments);
        $normalEnvironments = $normalEnvironments->filter(function ($value, $key) {
            return ! str($value)->startsWith('SERVICE_');
        });
        foreach ($normalEnvironments as $key => $value) {
            $key = str($key);
            $value = str($value);
            $originalValue = $value;
            $parsedValue = replaceVariables($value);
            if ($value->isEmpty() && $passthroughEnvironmentKeys->contains($key->value()) && ! isCoolifyProvidedComposeVariable($key->value())) {
                $applicationVariable([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'is_preview' => false,
                ]);

                continue;
            }
            extractDockerComposeEnvironmentVariableReferences($value->value())
                ->reject(fn (string $reference): bool => str_starts_with($reference, 'SERVICE_') || isCoolifyProvidedComposeVariable($reference))
                ->each(function (string $reference) use ($resource, $applicationVariable, $keepsApplicationRows): void {
                    $environmentVariable = $applicationVariable([
                        'key' => $reference,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'is_preview' => false,
                    ]);
                    $isRequired = dockerComposeEnvironmentVariableIsRequired(
                        $resource->docker_compose_raw ?? $resource->docker_compose,
                        $reference
                    );
                    if (! $keepsApplicationRows && (bool) $environmentVariable->is_required !== $isRequired) {
                        $environmentVariable->update(['is_required' => $isRequired]);
                    }
                });
            if ($value->startsWith('$SERVICE_')) {
                $applicationVariable([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $value,
                    'is_preview' => false,
                ]);

                continue;
            }
            if (! $value->startsWith('$') || composeValueReferencesCoolifyProvidedVariable($value->value())) {
                continue;
            }
            if ($key->value() === $parsedValue->value()) {
                // Simple variable reference (e.g. DATABASE_URL: ${DATABASE_URL})
                // Ensure the variable exists in DB for .env generation and UI display
                $applicationVariable([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'is_preview' => false,
                ]);
                // Keep the ${VAR} reference in compose — Docker Compose resolves from .env at deploy time.
                // Do NOT replace with DB value: if user updates env var without re-parsing compose,
                // a stale resolved value in environment: would override the correct .env value.
            } else {
                if ($value->startsWith('$')) {
                    $isRequired = false;

                    // Extract variable content between ${...} using balanced brace matching
                    $result = extractBalancedBraceContent($value->value(), 0);

                    if ($result !== null) {
                        $content = $result['content'];
                        $split = splitOnOperatorOutsideNested($content);

                        if ($split !== null) {
                            // Has default value syntax (:-,  -,  :?, or ?)
                            $varName = $split['variable'];
                            $operator = $split['operator'];
                            $defaultValue = $split['default'];
                            $isRequired = str_contains($operator, '?');

                            // Create the primary variable with its default (only if it doesn't exist)
                            if (! $isPullRequestMagicVariable($varName)) {
                                $envVar = $applicationVariable([
                                    'key' => $varName,
                                    'resourceable_type' => get_class($resource),
                                    'resourceable_id' => $resource->id,
                                ], [
                                    'value' => $isRequired ? null : $defaultValue,
                                    'is_preview' => false,
                                    'is_required' => $isRequired,
                                ]);
                            }

                            // Recursively process nested variables in default value
                            if (str_contains($defaultValue, '${')) {
                                $searchPos = 0;
                                $nestedResult = extractBalancedBraceContent($defaultValue, $searchPos);
                                while ($nestedResult !== null) {
                                    $nestedContent = $nestedResult['content'];
                                    $nestedSplit = splitOnOperatorOutsideNested($nestedContent);

                                    // Determine the nested variable name
                                    $nestedVarName = $nestedSplit !== null ? $nestedSplit['variable'] : $nestedContent;

                                    // Skip SERVICE_URL_* and SERVICE_FQDN_* variables - they are handled by magic variable system
                                    $isMagicVariable = str_starts_with($nestedVarName, 'SERVICE_URL_') || str_starts_with($nestedVarName, 'SERVICE_FQDN_');

                                    if (! $isMagicVariable && ! $isPullRequestMagicVariable($nestedVarName)) {
                                        if ($nestedSplit !== null) {
                                            $nestedEnvVar = $applicationVariable([
                                                'key' => $nestedSplit['variable'],
                                                'resourceable_type' => get_class($resource),
                                                'resourceable_id' => $resource->id,
                                            ], [
                                                'value' => $nestedSplit['default'],
                                                'is_preview' => false,
                                            ]);
                                        } else {
                                            $nestedEnvVar = $applicationVariable([
                                                'key' => $nestedContent,
                                                'resourceable_type' => get_class($resource),
                                                'resourceable_id' => $resource->id,
                                            ], [
                                                'is_preview' => false,
                                            ]);
                                        }
                                    }

                                    $searchPos = $nestedResult['end'] + 1;
                                    if ($searchPos >= strlen($defaultValue)) {
                                        break;
                                    }
                                    $nestedResult = extractBalancedBraceContent($defaultValue, $searchPos);
                                }
                            }
                        } else {
                            // Simple variable reference without default
                            $parsedKeyValue = replaceVariables($value);
                            if (! $isPullRequestMagicVariable($content)) {
                                $envVar = $applicationVariable([
                                    'key' => $content,
                                    'resourceable_type' => get_class($resource),
                                    'resourceable_id' => $resource->id,
                                ], [
                                    'is_preview' => false,
                                    'is_required' => $isRequired,
                                ]);
                            }
                        }
                    } else {
                        // Fallback to old behavior for malformed input (backward compatibility)
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
                            // This means the variable does not have a default value
                            $parsedKeyValue = replaceVariables($value);
                            $envVar = $applicationVariable([
                                'key' => $parsedKeyValue,
                                'resourceable_type' => get_class($resource),
                                'resourceable_id' => $resource->id,
                            ], [
                                'is_preview' => false,
                                'is_required' => $isRequired,
                            ]);
                            // Add the variable to the environment using the saved DB value
                            $environment[$parsedKeyValue->value()] = $envVar?->value;

                            continue;
                        }
                        $applicationVariable([
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
        }
        $branch = $originalResource->coolifyBranch($pullRequestId);
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
            $domains = collect(json_decode(data_get($preview, 'docker_compose_domains') ?: '[]', true) ?: []);
        } else {
            $domains = collect(json_decode(data_get($resource, 'docker_compose_domains') ?: '[]', true) ?: []);
        }

        // Only process domains for dockercompose applications to prevent SERVICE variable recreation
        if ($resource->build_pack !== 'dockercompose') {
            $domains = collect([]);
        }
        // Prefer original compose service key; fall back to legacy underscore storage keys.
        $fqdns = getComposeServiceDomainString($domains, (string) $serviceName);
        // Generate SERVICE_FQDN & SERVICE_URL for dockercompose
        if ($resource->build_pack === 'dockercompose') {
            foreach ($domains as $forServiceName => $domain) {
                $parsedDomain = composeDomainEntryString($domain);
                $serviceEnvKey = str(normalizeComposeServiceName((string) $forServiceName))->upper()->value();

                if (filled($parsedDomain)) {
                    $parsedDomain = str($parsedDomain)->explode(',')->first();
                    $coolifyUrl = Url::fromString($parsedDomain);
                    $coolifyScheme = $coolifyUrl->getScheme();
                    $coolifyFqdn = $coolifyUrl->getHost();
                    $coolifyUrl = $coolifyUrl->withScheme($coolifyScheme)->withHost($coolifyFqdn)->withPort(null);
                    $coolifyEnvironments->put('SERVICE_URL_'.$serviceEnvKey, $coolifyUrl->__toString());
                    $coolifyEnvironments->put('SERVICE_FQDN_'.$serviceEnvKey, $coolifyFqdn);
                    // A preview uses its own domains; the stored variables belong to the main application.
                    if ($isPullRequest) {
                        continue;
                    }
                    syncComposeServiceUrlVariables($resource, $serviceEnvKey, $coolifyUrl->__toString(), $coolifyFqdn);
                } elseif (! $isPullRequest) {
                    syncComposeServiceUrlVariables($resource, $serviceEnvKey, null, null);
                }
            }
        }
        // If the domain is set, we need to generate the FQDNs for the preview
        if (filled($fqdns)) {
            $fqdns = str($fqdns)->explode(',');
            if ($isPullRequest) {
                $preview = $resource->previews()->find($preview_id);
                $docker_compose_domains = collect(json_decode(data_get($preview, 'docker_compose_domains') ?: '[]', true) ?: []);
                if ($docker_compose_domains->count() > 0) {
                    $found_fqdn = getComposeServiceDomainString($docker_compose_domains, (string) $serviceName);
                    if ($found_fqdn) {
                        $fqdns = str($found_fqdn)->explode(',')->map(fn ($fqdn) => trim($fqdn))->filter();
                    } else {
                        $fqdns = collect([]);
                    }
                } else {
                    $generatedDomains = $fqdns->map(
                        fn ($fqdn) => $preview->generatedPreviewDomain((string) $fqdn)
                    );
                    $fqdns = $generatedDomains->pluck('url');
                    $preview->fqdn = $fqdns->implode(',');
                    $preview->domain_port_overrides = $generatedDomains
                        ->filter(fn (array $generated): bool => filled($generated['port']))
                        ->mapWithKeys(fn (array $generated): array => [$generated['url'] => $generated['port']])
                        ->all();
                    $preview->save();
                }
            }
        }
        $defaultLabels = defaultLabels(
            uuid: $resource->uuid,
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
            $coolifyEnvironments->put('COOLIFY_URL', $fqdns->map(fn ($fqdn) => getFqdnWithoutPort($fqdn))->implode(','));
            $coolifyEnvironments->put('COOLIFY_FQDN', $fqdns->map(fn ($fqdn) => getHostWithoutPort($fqdn))->implode(','));
        }
        $userDefinedKeys = $resource->environment_variables->pluck('key')->merge($environment->keys());
        coolifyDefaultEnvironmentVariables($resource, $userDefinedKeys)
            ->each(fn ($value, $key) => $coolifyEnvironments->put($key, escapeDollarSign($value)));
        if ($environment->count() > 0) {
            $environment = $environment->filter(function ($value, $key) {
                return ! str($key)->startsWith('SERVICE_FQDN_');
            })->map(function ($value, $key) use ($resource, $isPullRequest, $isPublicPullRequest) {
                $isValueFromDatabase = false;
                // Preserve empty strings and null values with correct Docker Compose semantics:
                // - Empty string: Variable is set to "" (e.g., HTTP_PROXY="" means "no proxy")
                // - Null: Variable is unset/removed from container environment (may inherit from host)
                if ($value === null) {
                    // User explicitly wants variable unset - respect that
                    // NEVER override from database - null means "inherit from environment"
                    // Keep as null (will be excluded from container environment)
                } elseif ($value === '' && ! ($isPullRequest && str($key)->startsWith('SERVICE_URL_'))) {
                    // Empty string - allow database override for backward compatibility.
                    // The stored service URLs belong to the main application, so a preview does not use them.
                    // A preview uses its preview variables, like ApplicationDeploymentJob does.
                    $dbEnv = $isPullRequest
                        ? $resource->environment_variables_preview()->where('key', $key)->first()
                        : $resource->environment_variables()->where('key', $key)->first();
                    // A public preview does not get preview variables that have the same value as in production.
                    if ($dbEnv && $isPublicPullRequest && $dbEnv->hasSameValueAs($resource->environment_variables()->where('key', $key)->first())) {
                        $dbEnv = null;
                    }
                    // Only use database override if it exists AND has a non-empty value
                    if ($dbEnv && str($dbEnv->value)->isNotEmpty()) {
                        $value = $dbEnv->value;
                        $isValueFromDatabase = true;
                    }
                    // Otherwise keep empty string as-is
                }

                // Resolve shared variable patterns like {{environment.VAR}}, {{project.VAR}}, {{team.VAR}}
                // Without this, literal {{...}} strings end up in the compose environment: section,
                // which takes precedence over the resolved values in the .env file (env_file:)
                // A public preview resolves them only in its own variables, not in the compose file of the pull request.
                if (is_string($value) && str_contains($value, '{{') && ($isValueFromDatabase || ! $isPublicPullRequest)) {
                    $value = resolveSharedEnvironmentVariables($value, $resource);
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
            $labelUuid = $resource->uuid;
            $labelNetwork = data_get($resource, 'destination.network');
            if ($isPullRequest) {
                $labelUuid = "{$resource->uuid}-{$pullRequestId}";
            }
            if ($isPullRequest) {
                $labelNetwork = "{$resource->destination->network}-{$pullRequestId}";
            }
            $noindexDomains = $isPullRequest ? $fqdns : $originalResource->noindexDomains();
            $domainServiceName = findComposeServiceName((string) $serviceName, $domains->keys());
            $composeRedirect = data_get($domains->get($domainServiceName), 'redirect');
            $redirectDirection = in_array($composeRedirect, ['www', 'non-www', 'both'], true)
                ? $composeRedirect
                : 'both';
            $previewForPorts = $isPullRequest
                ? ($resource->previews()->find($preview_id) ?? ApplicationPreview::where('application_id', $resource->id)->where('pull_request_id', $pullRequestId)->first())
                : null;
            $domainPortOverrides = $isPullRequest
                ? ($previewForPorts?->domain_port_overrides ?? [])
                : ($originalResource->domain_port_overrides ?? []);
            $onlyPort = firstDockerComposeServicePort($service);
            $isTrafficAnalyticsEnabled = (bool) $server?->isTrafficAnalyticsEnabled();
            $supportsLogAppend = (bool) $server?->caddySupportsLogAppend();
            if (! $use_network_mode && (! $shouldGenerateLabelsExactly || $server->proxyType() === ProxyTypes::TRAEFIK->value)) {
                $serviceLabels = addTraefikDockerNetworkLabel($serviceLabels, $baseNetwork->first());
            }
            if ($shouldGenerateLabelsExactly) {
                switch ($server->proxyType()) {
                    case ProxyTypes::TRAEFIK->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $labelUuid,
                            domains: $fqdns,
                            is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                            is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image,
                            onlyPort: $onlyPort,
                            noindex_domains: $noindexDomains,
                            redirect_direction: $redirectDirection,
                            domainPortOverrides: $domainPortOverrides,
                            certificate_resolver: $server->traefikCertificateResolver(data_get($resource, 'settings.traefik_certificate_resolver')),
                        ));
                        break;
                    case ProxyTypes::CADDY->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $labelNetwork,
                            uuid: $labelUuid,
                            domains: $fqdns,
                            is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                            is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image,
                            onlyPort: $onlyPort,
                            predefinedPort: $predefinedPort,
                            noindex_domains: $noindexDomains,
                            redirect_direction: $redirectDirection,
                            domainPortOverrides: $domainPortOverrides,
                            is_traffic_analytics_enabled: $isTrafficAnalyticsEnabled,
                            supports_log_append: $supportsLogAppend,
                        ));
                        break;
                }
            } else {
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                    uuid: $labelUuid,
                    domains: $fqdns,
                    is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                    is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image,
                    onlyPort: $onlyPort,
                    noindex_domains: $noindexDomains,
                    redirect_direction: $redirectDirection,
                    domainPortOverrides: $domainPortOverrides,
                    certificate_resolver: $server->traefikCertificateResolver(data_get($resource, 'settings.traefik_certificate_resolver')),
                ));
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                    network: $labelNetwork,
                    uuid: $labelUuid,
                    domains: $fqdns,
                    is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                    is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image,
                    onlyPort: $onlyPort,
                    predefinedPort: $predefinedPort,
                    noindex_domains: $noindexDomains,
                    redirect_direction: $redirectDirection,
                    domainPortOverrides: $domainPortOverrides,
                    is_traffic_analytics_enabled: $isTrafficAnalyticsEnabled,
                    supports_log_append: $supportsLogAppend,
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
        // A security update of this service, while the compose file still names the image it replaced.
        if (($imageOverride = $resource->composeImageOverride((string) $serviceName, $image->isEmpty() ? null : $image->value())) !== null) {
            $payload['image'] = $imageOverride;
        }
        if (! $use_network_mode) {
            $payload['networks'] = $networks_temp;
        }
        // Yaml::dump writes an empty list as a mapping ({}), which docker compose rejects.
        if ($ports->count() > 0) {
            $payload['ports'] = $ports;
        } else {
            $payload->forget('ports');
        }
        if ($volumesParsed->count() > 0) {
            $payload['volumes'] = $volumesParsed;
        } else {
            $payload->forget('volumes');
        }
        if ($environment->count() > 0 || $coolifyEnvironments->count() > 0) {
            $environment = preserveLegacyDockerComposeNullAssignments($environment, $legacyNullEnvironmentVariables);
            $payload['environment'] = $environment->merge($coolifyEnvironments)->merge($serviceNameEnvironments);
        }
        if ($logging) {
            $payload['logging'] = $logging;
        }
        if ($renamesServices) {
            $depends_on = $depends_on->mapWithKeys(fn ($dependency, $condition) => is_numeric($condition)
                ? [$condition => addPreviewDeploymentSuffix($dependency, $pullRequestId)]
                : [addPreviewDeploymentSuffix($condition, $pullRequestId) => $dependency]);
        }
        if ($depends_on->count() > 0) {
            $payload['depends_on'] = $depends_on;
        }
        // Auto-inject .env file so Coolify environment variables are available inside containers
        // This makes Applications behave consistently with manual .env file usage
        $payload['env_file'] = composeEnvFilesWithCoolifyEnv(data_get($service, 'env_file'));

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

        // A preview keeps the Compose service names, which other Compose files and start commands use:
        // its own project and network isolate it. See Application::renamesPreviewComposeServices().
        if ($renamesServices) {
            $serviceName = addPreviewDeploymentSuffix($serviceName, $pullRequestId);
        }
        $parsedServices->put($serviceName, $payload);
    }
    // The preview mounts its own volumes instead of these external and network volumes (see above), so it does not declare them.
    $topLevel->put('volumes', $topLevel->get('volumes')->except($previewOwnVolumes->keys()->all()));
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
    // A preview parse keeps the Compose fields of the application: a preview can use the Compose file of its pull request.
    if (! $isPullRequest) {
        $resource->docker_compose = $cleanedCompose;
    }

    // Update docker_compose_raw to remove content: from volumes only
    // This keeps the original user input clean while preventing content reapplication
    // Parse the original compose again to create a clean version without Coolify additions
    try {
        $originalYaml = parseDockerComposeYaml($originalCompose);
        $originalYamlBeforeCleanup = $originalYaml;
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
        if (! $isPullRequest && $originalYaml !== $originalYamlBeforeCleanup) {
            $resource->docker_compose_raw = removeComposeVolumeFieldsPreservingComments($originalCompose, $originalYaml, ['content', 'isDirectory', 'is_directory']);
        }
    } catch (Exception) {
        // If parsing fails, keep the original docker_compose_raw unchanged
    }

    data_forget($resource, 'environment_variables');
    data_forget($resource, 'environment_variables_preview');
    $resource->save();

    if (! $keepsApplicationRows) {
        syncDockerComposeEnvironmentVariableRequiredState($resource);
    }

    return $topLevel;
}

/**
 * The service application or database that a Compose service uses, created when it does not exist.
 *
 * A part that the user converted wins. Then the part type that the image detection gives, and then the
 * other part type. An image change does not replace an existing part, so its settings and backups stay.
 */
function resolveServicePart(Service $resource, string $serviceName, bool $isDatabaseImage): ServiceApplication|ServiceDatabase
{
    $application = fn () => $resource->applications()->where('name', $serviceName);
    $database = fn () => $resource->databases()->where('name', $serviceName);

    $migrated = $application()->where('is_migrated', true)->first() ?? $database()->where('is_migrated', true)->first();
    if ($migrated) {
        return $migrated;
    }
    if ($isDatabaseImage) {
        return $database()->first() ?? $application()->first() ?? $resource->databases()->create(['name' => $serviceName]);
    }

    return $application()->first() ?? $database()->first() ?? $resource->applications()->create(['name' => $serviceName]);
}

/**
 * Marks the applications and databases of a service that its compose file no longer uses: parts whose names are
 * not in the file, and a duplicate part that another part with the same name replaces.
 * They keep their settings, so a part that comes back under the same name is used again.
 * The service status ignores marked parts, and the user can delete them.
 *
 * @param  Collection<int, ServiceApplication|ServiceDatabase>  $usedParts
 */
function markServicePartsRemovedFromCompose(Service $resource, Collection $usedParts): void
{
    if ($usedParts->isEmpty()) {
        return;
    }

    foreach ([[$resource->applications(), ServiceApplication::class], [$resource->databases(), ServiceDatabase::class]] as [$parts, $class]) {
        $usedIds = $usedParts->filter(fn ($part) => $part instanceof $class)->pluck('id')->all();
        (clone $parts)->whereIn('id', $usedIds)->where('is_removed_from_compose', true)
            ->update(['is_removed_from_compose' => false]);
        (clone $parts)->whereNotIn('id', $usedIds)->where('is_removed_from_compose', false)
            ->update(['is_removed_from_compose' => true]);
    }
}

function serviceParser(Service $resource): Collection
{
    $resource->resetComposeVolumeWarnings();
    $uuid = data_get($resource, 'uuid');
    $compose = data_get($resource, 'docker_compose_raw');
    // Store original compose for later use to update docker_compose_raw with content removed
    $originalCompose = $compose;
    if (! $compose) {
        return collect([]);
    }

    // Extract inline comments from raw YAML before Symfony parser discards them
    $envComments = extractYamlEnvironmentComments($compose);

    $server = data_get($resource, 'server');

    try {
        $yaml = parseDockerComposeYaml($compose);
    } catch (Exception) {
        return collect([]);
    }
    $services = data_get($yaml, 'services', []);
    if (! is_array($services)) {
        return collect([]);
    }

    // Clean up corrupted environment variables from previous parser bugs
    // (keys starting with $ or ending with } should not exist as env var names)
    $resource->environment_variables()
        ->where('resourceable_type', get_class($resource))
        ->where('resourceable_id', $resource->id)
        ->where(function ($q) {
            $q->where('key', 'LIKE', '$%')
                ->orWhere('key', 'LIKE', '%}');
        })
        ->delete();
    $existingEnvironmentVariableKeys = $resource->environment_variables()->pluck('key');

    $topLevel = collect([
        'volumes' => collect(data_get($yaml, 'volumes', [])),
        'networks' => collect(data_get($yaml, 'networks', [])),
        'configs' => collect(data_get($yaml, 'configs', [])),
        'secrets' => collect(data_get($yaml, 'secrets', [])),
    ]);
    ensureComposeNetworkNameVariables($resource, $topLevel->get('networks'));
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

    $legacyNullEnvironmentVariables = $resource->environment_variables()->where('preserve_compose_null', true)->whereNull('value')->get();

    $parsedServices = collect([]);

    // Generate SERVICE_NAME variables for docker compose services
    $serviceNameEnvironments = generateDockerComposeServiceName($services);

    $allMagicEnvironments = collect([]);
    $usedServiceParts = collect([]);
    // Presave services
    foreach ($services as $serviceName => $service) {
        // Validate service name for command injection
        try {
            validateShellSafePath($serviceName, 'service name');
        } catch (Exception $e) {
            throw new Exception(
                'Invalid Docker Compose service name: '.$e->getMessage().
                ' Service names must not contain shell metacharacters.'
            );
        }
        validateComposeServiceName((string) $serviceName);
        validateComposeServiceScale((string) $serviceName, $service);

        $image = data_get_str($service, 'image');
        $savedService = resolveServicePart($resource, (string) $serviceName, isDatabaseImage($image, $service));
        // Update image if it changed
        if ($savedService->image !== $image) {
            $savedService->image = $image;
            $savedService->save();
        }
        $usedServiceParts->push($savedService);
    }
    markServicePartsRemovedFromCompose($resource, $usedServiceParts);
    foreach ($services as $serviceName => $service) {
        $predefinedPort = null;
        $magicEnvironments = collect([]);
        $image = data_get_str($service, 'image');
        $environment = collect(data_get($service, 'environment', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        $savedService = resolveServicePart($resource, (string) $serviceName, isDatabaseImage($image, $service));
        // A part that the user converted keeps its type, ignoring image detection.
        $isDatabase = $savedService->is_migrated ? $savedService instanceof ServiceDatabase : isDatabaseImage($image, $service);

        $containerName = "$serviceName-{$resource->uuid}";

        $predefinedPort = $resource->getRequiredPort();

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
            if (count($valueMatches[2]) > 0) {
                foreach ($valueMatches[2] as $match) {
                    $match = str($match);
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

                if (isCopiedServiceUrlDeclaration($resource, $services, $savedService, $key->value())) {
                    continue;
                }

                // Only ServiceApplication has fqdn column, ServiceDatabase does not
                $isServiceApplication = $savedService instanceof ServiceApplication;

                if ($isServiceApplication && blank($savedService->fqdn)) {
                    $fqdn = generateFqdn(server: $server, random: "$fqdnFor-$uuid", parserVersion: $resource->compose_parsing_version);
                    $url = generateUrl($server, "$fqdnFor-$uuid");
                } elseif ($isServiceApplication) {
                    // FQDN may be a comma-separated list; use the first entry (same as updateCompose).
                    $firstFqdn = firstDomainFromList($savedService->fqdn);
                    $fqdn = getFqdnWithoutPort($firstFqdn);
                    $url = $fqdn;
                } else {
                    // For ServiceDatabase, generate fqdn/url without saving to the model
                    $fqdn = generateFqdn(server: $server, random: "$fqdnFor-$uuid", parserVersion: $resource->compose_parsing_version);
                    $url = generateUrl($server, "$fqdnFor-$uuid");
                }

                // IMPORTANT: SERVICE_FQDN env vars should NOT contain scheme (host only)
                // But $fqdn variable itself may contain scheme (used for database domain field)
                // Strip scheme for environment variable values
                $fqdnValueForEnv = str($fqdn)->after('://')->value();

                if ($value && get_class($value) === Stringable::class && $value->startsWith('/')) {
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
                    $savedService->fqdn = $url;
                    $savedService->save();
                }

                // ALWAYS create BOTH base SERVICE_URL and SERVICE_FQDN pairs (without port)
                $fqdnKey = "SERVICE_FQDN_{$serviceName}";
                $resource->environment_variables()->updateOrCreate([
                    'key' => $fqdnKey,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $fqdnValueForEnv,
                    'is_preview' => false,
                    'comment' => $envComments[$fqdnKey] ?? null,
                ]);

                $urlKey = "SERVICE_URL_{$serviceName}";
                $resource->environment_variables()->updateOrCreate([
                    'key' => $urlKey,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $url,
                    'is_preview' => false,
                    'comment' => $envComments[$urlKey] ?? null,
                ]);

                // For port-specific variables, ALSO create port-specific pairs
                // If template variable has port, create both URL and FQDN with port suffix
                if ($parsed['has_port'] && $port) {
                    $fqdnPortKey = "SERVICE_FQDN_{$serviceName}_{$port}";
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $fqdnPortKey,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdnValueForEnvWithPort,
                        'is_preview' => false,
                        'comment' => $envComments[$fqdnPortKey] ?? null,
                    ]);

                    $urlPortKey = "SERVICE_URL_{$serviceName}_{$port}";
                    $resource->environment_variables()->updateOrCreate([
                        'key' => $urlPortKey,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $urlWithPort,
                        'is_preview' => false,
                        'comment' => $envComments[$urlPortKey] ?? null,
                    ]);
                }
            }
        }
        $allMagicEnvironments = $allMagicEnvironments->merge($magicEnvironments);
        if ($magicEnvironments->count() > 0) {
            foreach ($magicEnvironments as $magicKey => $value) {
                $originalMagicKey = $magicKey; // Preserve original key for comment lookup
                $key = str($magicKey);
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
                        ->pluck('key')
                        ->contains(fn ($envKey) => preg_match('/^'.preg_quote($key->value(), '/').'_[0-9]+$/', $envKey) === 1);
                    $serviceExists = findServiceApplicationForEnvName($resource, (string) $fqdnFor);
                    // Check if FQDN already has a port set (contains ':' after the domain)
                    $fqdnHasPort = $serviceExists && str($serviceExists->fqdn)->contains(':') && str($serviceExists->fqdn)->afterLast(':')->isMatch('/^\d+$/');
                    // Only set FQDN if it's for the current service being processed (prevent race conditions)
                    $isCurrentService = $serviceExists && $serviceExists->id === $savedService->id;
                    if (! $envExists && ! $portSuffixedExists && ! $fqdnHasPort && $isCurrentService) {
                        // Save URL otherwise it won't work.
                        $serviceExists->fqdn = $url;
                        $serviceExists->save();
                    }
                    // Create FQDN variable (use firstOrCreate to avoid overwriting values
                    // already set by direct template declarations or updateCompose)
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_preview' => false,
                        'comment' => $envComments[$originalMagicKey] ?? null,
                    ]);

                    // Also create the paired SERVICE_URL_* variable
                    $urlKey = 'SERVICE_URL_'.strtoupper($fqdnFor);
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $urlKey,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_preview' => false,
                        'comment' => $envComments[$urlKey] ?? null,
                    ]);

                } elseif ($command->value() === 'URL') {
                    $urlFor = $key->after('SERVICE_URL_')->lower()->value();
                    $url = generateUrl(server: $server, random: str($urlFor)->replace('_', '-')->value()."-$uuid");
                    $fqdn = generateFqdn(server: $server, random: str($urlFor)->replace('_', '-')->value()."-$uuid", parserVersion: $resource->compose_parsing_version);

                    $envExists = $resource->environment_variables()->where('key', $key->value())->first();
                    // Also check if a port-suffixed version exists (e.g., SERVICE_URL_DASHBOARD_6791)
                    $portSuffixedExists = $resource->environment_variables()
                        ->where('key', 'LIKE', $key->value().'_%')
                        ->pluck('key')
                        ->contains(fn ($envKey) => preg_match('/^'.preg_quote($key->value(), '/').'_[0-9]+$/', $envKey) === 1);
                    $serviceExists = findServiceApplicationForEnvName($resource, (string) $urlFor);
                    // Check if FQDN already has a port set (contains ':' after the domain)
                    $fqdnHasPort = $serviceExists && str($serviceExists->fqdn)->contains(':') && str($serviceExists->fqdn)->afterLast(':')->isMatch('/^\d+$/');
                    // Only set FQDN if it's for the current service being processed (prevent race conditions)
                    $isCurrentService = $serviceExists && $serviceExists->id === $savedService->id;
                    if (! $envExists && ! $portSuffixedExists && ! $fqdnHasPort && $isCurrentService) {
                        $serviceExists->fqdn = $url;
                        $serviceExists->save();
                    }
                    // Create URL variable (use firstOrCreate to avoid overwriting values
                    // already set by direct template declarations or updateCompose)
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $key->value(),
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $url,
                        'is_preview' => false,
                        'comment' => $envComments[$originalMagicKey] ?? null,
                    ]);

                    // Also create the paired SERVICE_FQDN_* variable
                    $fqdnKey = 'SERVICE_FQDN_'.strtoupper($urlFor);
                    $resource->environment_variables()->firstOrCreate([
                        'key' => $fqdnKey,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'value' => $fqdn,
                        'is_preview' => false,
                        'comment' => $envComments[$fqdnKey] ?? null,
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
                        'comment' => $envComments[$originalMagicKey] ?? null,
                    ]);
                }
            }
        }
    }

    $serviceLogDrainEnabledMap = $resource->applications()->get()
        ->concat($resource->databases()->get())
        ->keyBy('name')
        ->map(fn (ServiceApplication|ServiceDatabase $servicePart) => $servicePart->isLogDrainEnabled());

    // Parse the rest of the services
    foreach ($services as $serviceName => $service) {
        $image = data_get_str($service, 'image');
        $restart = data_get_str($service, 'restart', RESTART_MODE);
        $logging = data_get($service, 'logging');

        if ($server->isLogDrainEnabled()) {
            if ($serviceLogDrainEnabledMap->get($serviceName)) {
                $logging = generate_log_drain_configuration($server);
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

        $savedService = resolveServicePart($resource, (string) $serviceName, isDatabaseImage($image, $service));
        // A part that the user converted keeps its type, ignoring image detection.
        $isDatabase = $savedService->is_migrated ? $savedService instanceof ServiceDatabase : isDatabaseImage($image, $service);

        $volumesParsed = collect([]);

        $containerName = "$serviceName-{$resource->uuid}";

        $predefinedPort = $resource->getRequiredPort();

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
                    $volumeVariables = str_contains($volume, '${')
                        ? $volumeSourceVariables ??= composeVolumeSourceVariables($resource)
                        : null;
                    $keepsSavedVolume = $volumeVariables !== null
                        && keepSavedComposeNamedVolume($volume, $uuid, $volumeVariables, $originalResource, $resource);
                    $parsed = parseDockerVolumeString($volume, $keepsSavedVolume ? null : $volumeVariables);
                    $source = $parsed['source'];
                    $target = $parsed['target'];
                    if ($keepsSavedVolume) {
                        // An earlier parse of this version mounted the host path. That bind is not mounted anymore.
                        forgetComposeBindStorage($originalResource, $target->value());
                    }
                    // Mode is available in $parsed['mode'] if needed
                    $foundConfig = findLocalFileVolumeConfig($originalResource, $source, $target, is_string($volume) ? $volume : null);
                    if (sourceIsLocal($source)) {
                        $type = str('bind');
                        if ($foundConfig) {
                            $content = data_get($foundConfig, 'content');
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
                    $isDirectory = data_get($volume, 'is_directory') ?? data_get($volume, 'isDirectory');

                    // Validate source and target for command injection (array/long syntax)
                    if ($source !== null && ! empty($source->value())) {
                        validateComposeArrayVolumeSource($source->value());
                        // A named volume keeps its source as written, so its volume name does not change.
                        if ($type->value() === 'bind') {
                            $source = str(resolveComposeLongBindSource($source->value(), $resource));
                        }
                    }
                    validateComposeContentVolumeSource($volume);
                    if ($target !== null && ! empty($target->value())) {
                        try {
                            validateShellSafePath($target->value(), 'volume target');
                        } catch (Exception $e) {
                            throw new Exception(
                                'Invalid Docker volume definition (array syntax): '.$e->getMessage().
                                ' Please use safe path names without shell metacharacters.'
                            );
                        }
                    }

                    $foundConfig = findLocalFileVolumeConfig($originalResource, $source, $target, is_string($volume) ? $volume : null);
                    if ($foundConfig) {
                        $content = data_get($foundConfig, 'content');
                        $isDirectory = data_get($foundConfig, 'is_directory');
                    } else {
                        // Without is_directory, a bind without content is a directory. An explicit `is_directory: false` stays a file.
                        $isDirectory = is_null($isDirectory) ? is_null($content) : (bool) $isDirectory;
                    }
                }
                if ($type->value() === 'bind') {
                    if (in_array($source->value(), ['/var/run/docker.sock', '/tmp', '/tmp/'], true)) {
                        if (is_string($volume)) {
                            $volume = $source->value().':'.$target->value();
                            if (isset($parsed['mode']) && $parsed['mode']) {
                                $volume .= ':'.$parsed['mode']->value();
                            }
                        } else {
                            $volume = composeLongBindVolume($volume, $source->value());
                        }
                    } else {
                        if ((int) $resource->compose_parsing_version >= 4) {
                            $mainDirectory = str(base_configuration_dir().'/services/'.$uuid);
                        } else {
                            $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        }
                        $source = resolveComposeBindSource($source, $mainDirectory, $foundConfig?->fs_path);
                        [$source, $variableSource] = keepSavedComposeBindSource($source, is_string($volume) ? $volume : null, $mainDirectory, $foundConfig, $resource);
                        // With `bind.create_host_path: false`, the host path must exist already: Coolify does not create or manage it.
                        if (! composeBindKeepsMissingHostPath($volume)) {
                            LocalFileVolume::updateOrCreate(
                                [
                                    'fs_path' => (string) $source,
                                    'mount_path' => $target,
                                    'resource_id' => $originalResource->id,
                                    'resource_type' => get_class($originalResource),
                                ],
                                [
                                    'fs_path' => (string) $source,
                                    'variable_fs_path' => $variableSource,
                                    'mount_path' => $target,
                                    'content' => $content,
                                    'is_directory' => $isDirectory,
                                    'resource_id' => $originalResource->id,
                                    'resource_type' => get_class($originalResource),
                                ]
                            );
                        } else {
                            forgetComposeBindStorage($originalResource, $source->value(), $target->value());
                        }
                        // The file storage keeps the path that Coolify writes; the Docker daemon may need another one.
                        $source = $source->replace($mainDirectory, devHostDockerPath($server, $mainDirectory->value()));
                        if (is_array($volume)) {
                            $volume = composeLongBindVolume($volume, $source->value());
                        } else {
                            $volume = "$source:$target";
                            if (isset($parsed['mode']) && $parsed['mode']) {
                                $volume .= ':'.$parsed['mode']->value();
                            }
                        }
                    }
                } elseif ($type->value() === 'volume') {
                    if (useComposeExternalVolumeAsWritten($resource, $originalResource, $topLevel->get('volumes'), $source->value(), "{$uuid}_".Str::slug($source, '-'))) {
                        // The external volume gets no row, so Coolify never removes it.
                        $volumesParsed->put($index, $volume);

                        continue;
                    }
                    $declaration = $topLevel->get('volumes')->get($source->value());
                    if (in_array(data_get($declaration, 'driver_opts.type'), ['cifs', 'nfs'], true)) {
                        // Network volumes are used as written.
                        $volumesParsed->put($index, $volume);

                        continue;
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
                    $persistentVolume = LocalPersistentVolume::updateOrCreate(
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
                    $topLevel->get('volumes')->put($name, composeRenamedVolumeDeclarationFor($declaration, $name, $persistentVolume));
                }
                $volumesParsed->put($index, $volume);
            }
        }

        if (! $use_network_mode) {
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
                } elseif (is_array($network) || is_null($network)) {
                    // networks:
                    //   default:
                    //     ipv4_address: 192.168.203.254
                    //   internal: null
                    $networks_temp->put($key, $network);
                }
            }
            foreach ($baseNetwork as $key => $network) {
                $networks_temp->put($network, null);
            }
        }

        $passthroughEnvironmentKeys = extractDockerComposePassthroughKeys(data_get($service, 'environment', []));
        $normalEnvironments = $environment->diffKeys($allMagicEnvironments);
        $normalEnvironments = $normalEnvironments->filter(function ($value, $key) {
            return ! str($value)->startsWith('SERVICE_');
        });
        foreach ($normalEnvironments as $key => $value) {
            $originalKey = $key; // Preserve original key for comment lookup
            $key = str($key);
            $value = str($value);
            $originalValue = $value;
            $parsedValue = replaceVariables($value);
            if ($value->isEmpty() && $passthroughEnvironmentKeys->contains($key->value()) && ! isCoolifyProvidedComposeVariable($key->value())) {
                $resource->environment_variables()->firstOrCreate([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'is_preview' => false,
                    'comment' => $envComments[$originalKey] ?? null,
                ]);

                continue;
            }
            $referenceContent = extractBalancedBraceContent($value->value());
            $primaryReference = $referenceContent === null ? null : splitOnOperatorOutsideNested($referenceContent['content']);
            extractDockerComposeEnvironmentVariableReferences($value->value())
                ->reject(fn (string $reference): bool => $value->startsWith('$') && $reference === ($primaryReference['variable'] ?? null) && str_contains($primaryReference['operator'], '?'))
                ->reject(fn (string $reference): bool => str_starts_with($reference, 'SERVICE_') || isCoolifyProvidedComposeVariable($reference))
                ->each(function (string $reference) use ($resource): void {
                    $environmentVariable = $resource->environment_variables()->firstOrCreate([
                        'key' => $reference,
                        'resourceable_type' => get_class($resource),
                        'resourceable_id' => $resource->id,
                    ], [
                        'is_preview' => false,
                    ]);
                    $isRequired = dockerComposeEnvironmentVariableIsRequired(
                        $resource->docker_compose_raw ?? $resource->docker_compose,
                        $reference
                    );
                    if ((bool) $environmentVariable->is_required !== $isRequired) {
                        $environmentVariable->update(['is_required' => $isRequired]);
                    }
                });
            if ($parsedValue->startsWith('SERVICE_')) {
                $resource->environment_variables()->updateOrCreate([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'value' => $value,
                    'is_preview' => false,
                    'comment' => $envComments[$originalKey] ?? null,
                ]);

                continue;
            }
            if (! $value->startsWith('$') || composeValueReferencesCoolifyProvidedVariable($value->value())) {
                continue;
            }
            if ($key->value() === $parsedValue->value()) {
                // Simple variable reference (e.g. DATABASE_URL: ${DATABASE_URL})
                // Ensure the variable exists in DB for .env generation and UI display
                $resource->environment_variables()->firstOrCreate([
                    'key' => $key,
                    'resourceable_type' => get_class($resource),
                    'resourceable_id' => $resource->id,
                ], [
                    'is_preview' => false,
                    'comment' => $envComments[$originalKey] ?? null,
                ]);
                // Keep the ${VAR} reference in compose — Docker Compose resolves from .env at deploy time.
                // Do NOT replace with DB value: if user updates env var without re-parsing compose,
                // a stale resolved value in environment: would override the correct .env value.
            } else {
                if ($value->startsWith('$')) {
                    $isRequired = false;

                    // Extract variable content between ${...} using balanced brace matching
                    $result = extractBalancedBraceContent($value->value(), 0);

                    if ($result !== null) {
                        $content = $result['content'];
                        $split = splitOnOperatorOutsideNested($content);

                        if ($split !== null) {
                            // Has default value syntax (:-,  -,  :?, or ?)
                            $varName = $split['variable'];
                            $operator = $split['operator'];
                            $defaultValue = $split['default'];
                            $isRequired = str_contains($operator, '?');

                            // Create the primary variable with its default (only if it doesn't exist)
                            // Use firstOrCreate instead of updateOrCreate to avoid overwriting user edits
                            $envVar = $resource->environment_variables()->firstOrCreate([
                                'key' => $varName,
                                'resourceable_type' => get_class($resource),
                                'resourceable_id' => $resource->id,
                            ], [
                                'value' => $isRequired ? $defaultValue : null,
                                'is_preview' => false,
                                'is_required' => $isRequired,
                                'comment' => $envComments[$originalKey] ?? null,
                            ]);

                            // Recursively process nested variables in default value
                            if (str_contains($defaultValue, '${')) {
                                // Extract and create nested variables
                                $searchPos = 0;
                                $nestedResult = extractBalancedBraceContent($defaultValue, $searchPos);
                                while ($nestedResult !== null) {
                                    $nestedContent = $nestedResult['content'];
                                    $nestedSplit = splitOnOperatorOutsideNested($nestedContent);

                                    // Determine the nested variable name
                                    $nestedVarName = $nestedSplit !== null ? $nestedSplit['variable'] : $nestedContent;

                                    // Skip SERVICE_URL_* and SERVICE_FQDN_* variables - they are handled by magic variable system
                                    $isMagicVariable = str_starts_with($nestedVarName, 'SERVICE_URL_') || str_starts_with($nestedVarName, 'SERVICE_FQDN_');

                                    if (! $isMagicVariable) {
                                        if ($nestedSplit !== null) {
                                            // Create nested variable with its default (only if it doesn't exist)
                                            $nestedEnvVar = $resource->environment_variables()->firstOrCreate([
                                                'key' => $nestedSplit['variable'],
                                                'resourceable_type' => get_class($resource),
                                                'resourceable_id' => $resource->id,
                                            ], [
                                                'value' => $nestedSplit['default'],
                                                'is_preview' => false,
                                            ]);
                                        } else {
                                            // Simple nested variable without default (only if it doesn't exist)
                                            $nestedEnvVar = $resource->environment_variables()->firstOrCreate([
                                                'key' => $nestedContent,
                                                'resourceable_type' => get_class($resource),
                                                'resourceable_id' => $resource->id,
                                            ], [
                                                'is_preview' => false,
                                            ]);
                                        }
                                    }

                                    // Look for more nested variables
                                    $searchPos = $nestedResult['end'] + 1;
                                    if ($searchPos >= strlen($defaultValue)) {
                                        break;
                                    }
                                    $nestedResult = extractBalancedBraceContent($defaultValue, $searchPos);
                                }
                            }
                        } else {
                            // Simple variable reference without default
                            // Use firstOrCreate to avoid overwriting user-saved values on redeploy
                            $envVar = $resource->environment_variables()->firstOrCreate([
                                'key' => $content,
                                'resourceable_type' => get_class($resource),
                                'resourceable_id' => $resource->id,
                            ], [
                                'is_preview' => false,
                                'is_required' => $isRequired,
                                'comment' => $envComments[$originalKey] ?? null,
                            ]);
                        }
                    } else {
                        // Fallback to old behavior for malformed input (backward compatibility)
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
                            // This means the variable does not have a default value
                            // Use firstOrCreate to avoid overwriting user-saved values on redeploy
                            $parsedKeyValue = replaceVariables($value);
                            $envVar = $resource->environment_variables()->firstOrCreate([
                                'key' => $parsedKeyValue,
                                'resourceable_type' => get_class($resource),
                                'resourceable_id' => $resource->id,
                            ], [
                                'is_preview' => false,
                                'is_required' => $isRequired,
                                'comment' => $envComments[$originalKey] ?? null,
                            ]);
                            // Add the variable to the environment using the saved DB value
                            $environment[$parsedKeyValue->value()] = $envVar->value;

                            continue;
                        }
                        // Variable with a default value from compose — use firstOrCreate to preserve user edits
                        $resource->environment_variables()->firstOrCreate([
                            'key' => $key,
                            'resourceable_type' => get_class($resource),
                            'resourceable_id' => $resource->id,
                        ], [
                            'value' => $value,
                            'is_preview' => false,
                            'is_required' => $isRequired,
                            'comment' => $envComments[$originalKey] ?? null,
                        ]);
                    }
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
        // Flags live on the ServiceApplication; a ServiceDatabase has no domains.
        $noindexDomains = $savedService instanceof ServiceApplication
            ? $savedService->noindexDomains()
            : collect([]);

        $defaultLabels = defaultLabels(
            uuid: $resource->uuid,
            name: $containerName,
            projectName: $resource->project()->name,
            resourceName: $resource->name,
            type: 'service',
            subType: $savedService instanceof ServiceDatabase ? 'database' : 'application',
            subUuid: $savedService->uuid,
            subName: $savedService->human_name ?? $savedService->name,
            environment: $resource->environment->name,
        );

        // Add COOLIFY_FQDN & COOLIFY_URL to environment
        if (! $isDatabase && $fqdns instanceof Collection && $fqdns->count() > 0) {
            $coolifyEnvironments->put('COOLIFY_FQDN', $fqdns->map(fn ($fqdn) => getHostWithoutPort($fqdn))->implode(','));
            $coolifyEnvironments->put('COOLIFY_URL', $fqdns->map(fn ($fqdn) => getFqdnWithoutPort($fqdn))->implode(','));
        }
        $userDefinedKeys = $resource->environment_variables->pluck('key')->merge($environment->keys());
        coolifyDefaultEnvironmentVariables($resource, $userDefinedKeys)
            ->each(fn ($value, $key) => $coolifyEnvironments->put($key, escapeDollarSign($value)));
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

                // Resolve shared variable patterns like {{environment.VAR}}, {{project.VAR}}, {{team.VAR}}
                // Without this, literal {{...}} strings end up in the compose environment: section,
                // which takes precedence over the resolved values in the .env file (env_file:)
                if (is_string($value) && str_contains($value, '{{')) {
                    $value = resolveSharedEnvironmentVariables($value, $resource);
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
            $redirectDirection = in_array(data_get($originalResource, 'redirect'), ['www', 'non-www', 'both'], true)
                ? data_get($originalResource, 'redirect')
                : 'both';
            $onlyPort = $originalResource instanceof ServiceApplication
                ? $originalResource->getRequiredPort()
                : $predefinedPort;
            $isTrafficAnalyticsEnabled = (bool) $server?->isTrafficAnalyticsEnabled();
            $supportsLogAppend = (bool) $server?->caddySupportsLogAppend();
            if (! $use_network_mode && (! $shouldGenerateLabelsExactly || $server->proxyType() === ProxyTypes::TRAEFIK->value)) {
                $serviceLabels = addTraefikDockerNetworkLabel($serviceLabels, $baseNetwork->first());
            }
            if ($shouldGenerateLabelsExactly) {
                switch ($server->proxyType()) {
                    case ProxyTypes::TRAEFIK->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                            is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image,
                            onlyPort: $onlyPort,
                            domainPortOverrides: $originalResource->domain_port_overrides ?? [],
                            noindex_domains: $noindexDomains,
                            redirect_direction: $redirectDirection,
                            certificate_resolver: $server->traefikCertificateResolver(data_get($originalResource, 'traefik_certificate_resolver')),
                        ));
                        break;
                    case ProxyTypes::CADDY->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $network,
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                            is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image,
                            onlyPort: $onlyPort,
                            predefinedPort: $onlyPort,
                            domainPortOverrides: $originalResource->domain_port_overrides ?? [],
                            noindex_domains: $noindexDomains,
                            redirect_direction: $redirectDirection,
                            is_traffic_analytics_enabled: $isTrafficAnalyticsEnabled,
                            supports_log_append: $supportsLogAppend,
                        ));
                        break;
                }
            } else {
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                    is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image,
                    onlyPort: $onlyPort,
                    domainPortOverrides: $originalResource->domain_port_overrides ?? [],
                    noindex_domains: $noindexDomains,
                    redirect_direction: $redirectDirection,
                    certificate_resolver: $server->traefikCertificateResolver(data_get($originalResource, 'traefik_certificate_resolver')),
                ));
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                    network: $network,
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: $originalResource->isForceHttpsEnabled(),
                    is_permanent_redirect_enabled: $originalResource->isPermanentRedirectEnabled(),
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image,
                    onlyPort: $onlyPort,
                    predefinedPort: $onlyPort,
                    domainPortOverrides: $originalResource->domain_port_overrides ?? [],
                    noindex_domains: $noindexDomains,
                    redirect_direction: $redirectDirection,
                    is_traffic_analytics_enabled: $isTrafficAnalyticsEnabled,
                    supports_log_append: $supportsLogAppend,
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
        // Yaml::dump writes an empty list as a mapping ({}), which docker compose rejects.
        if ($ports->count() > 0) {
            $payload['ports'] = $ports;
        } else {
            $payload->forget('ports');
        }
        if ($volumesParsed->count() > 0) {
            $payload['volumes'] = $volumesParsed;
        } else {
            $payload->forget('volumes');
        }
        if ($environment->count() > 0 || $coolifyEnvironments->count() > 0) {
            $environment = preserveLegacyDockerComposeNullAssignments($environment, $legacyNullEnvironmentVariables);
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
        $payload['env_file'] = composeEnvFilesWithCoolifyEnv(data_get($service, 'env_file'));

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
        $originalYaml = parseDockerComposeYaml($originalCompose);
        $originalYamlBeforeCleanup = $originalYaml;
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
        if ($originalYaml !== $originalYamlBeforeCleanup) {
            $resource->docker_compose_raw = removeComposeVolumeFieldsPreservingComments($originalCompose, $originalYaml, ['content', 'isDirectory', 'is_directory']);
        }
    } catch (Exception $e) {
        // If parsing fails, keep the original docker_compose_raw unchanged
    }

    data_forget($resource, 'environment_variables');
    data_forget($resource, 'environment_variables_preview');
    $resource->save();

    fillDockerComposeDefaultsForBareReferences($resource, $existingEnvironmentVariableKeys);
    syncDockerComposeEnvironmentVariableRequiredState($resource);

    return $topLevel;
}
