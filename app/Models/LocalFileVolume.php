<?php

namespace App\Models;

use App\Events\FileStorageChanged;
use App\Jobs\ServerStorageSaveJob;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Symfony\Component\Yaml\Yaml;

class LocalFileVolume extends BaseModel
{
    protected $casts = [
        // 'fs_path' => 'encrypted',
        // 'mount_path' => 'encrypted',
        'content' => 'encrypted',
        'is_directory' => 'boolean',
        'is_preview_suffix_enabled' => 'boolean',
    ];

    use HasFactory;

    protected $fillable = [
        'fs_path',
        'mount_path',
        'content',
        'resource_type',
        'resource_id',
        'is_directory',
        'chown',
        'chmod',
        'is_based_on_git',
        'is_preview_suffix_enabled',
    ];

    public $appends = ['is_binary'];

    protected static function booted()
    {
        static::created(function (LocalFileVolume $fileVolume) {
            $fileVolume->load(['service']);
            dispatch(new ServerStorageSaveJob($fileVolume));
        });
    }

    /**
     * Resolve env var default syntax from fs_path (e.g., ${VAR:-./path} -> ./path).
     * Resolves against the owning resource's environment variables so that
     * Coolify-defined values take precedence over defaults.
     *
     * @throws \RuntimeException if the resolved path is bare '.' or empty (unresolvable variable with no default)
     */
    public function resolvedFsPath(): string
    {
        $envVars = $this->getResourceEnvironmentVariables();
        $resolved = resolveEnvVarDefault(str($this->fs_path), $envVars);
        $value = $resolved->value();

        if ($value === '.' || $value === '' || str_contains($value, '${')) {
            throw new \RuntimeException(
                'Cannot resolve storage path: environment variable has no value and no default was provided. '
                .'Original path: '.$this->fs_path
            );
        }

        return $value;
    }

    /**
     * Gather environment variables from the owning resource (and its parent
     * service when the resource is a ServiceApplication or ServiceDatabase).
     */
    private function getResourceEnvironmentVariables(): Collection
    {
        if ($this->exists) {
            $this->loadMissing(['service']);
        }
        $resource = $this->resource;

        if (! $resource) {
            return collect();
        }

        $envVars = collect();

        // Service children (ServiceApplication / ServiceDatabase) — merge parent service env vars
        $parentService = data_get($resource, 'service');
        if ($parentService && method_exists($parentService, 'environment_variables')) {
            $envVars = $parentService->environment_variables()->get(['key', 'value'])
                ->mapWithKeys(fn ($item) => [$item['key'] => $item['value']]);
        }

        // Resource's own env vars (overrides parent service vars)
        if (method_exists($resource, 'environment_variables')) {
            $resourceEnvVars = $resource->environment_variables()->get(['key', 'value'])
                ->mapWithKeys(fn ($item) => [$item['key'] => $item['value']]);
            $envVars = $envVars->merge($resourceEnvVars);
        }

        return $envVars;
    }

    protected function isBinary(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->content === '[binary file]';
            }
        );
    }

    public function service()
    {
        return $this->morphTo('resource');
    }

    /**
     * Re-resolve fs_path for file volumes whose raw compose source references
     * environment variables. Call this after env vars are updated.
     *
     * Works for Application and Service resources.
     */
    public static function reResolveVolumePaths($resource): void
    {
        try {
            if ($resource instanceof Application) {
                static::reResolveForApplication($resource);
            } elseif ($resource instanceof Service) {
                static::reResolveForService($resource);
            }
        } catch (\Throwable) {
            // Best-effort — don't break env var saves
        }
    }

    private static function reResolveForApplication(Application $application): void
    {
        if ((int) $application->compose_parsing_version < 6) {
            return;
        }

        $dockerComposeRaw = $application->docker_compose_raw;
        if (! $dockerComposeRaw) {
            return;
        }

        $compose = Yaml::parse($dockerComposeRaw);
        if (! isset($compose['services'])) {
            return;
        }

        $envVars = $application->environment_variables()->get(['key', 'value'])
            ->mapWithKeys(fn ($item) => [$item['key'] => $item['value']]);

        $mainDirectory = str(base_configuration_dir().'/applications/'.$application->uuid);

        // Build raw source map from ALL compose services (Application owns all volumes)
        $rawSources = [];
        foreach ($compose['services'] as $service) {
            $volumes = data_get($service, 'volumes', []);
            foreach ($volumes as $volume) {
                $source = null;
                $target = null;
                if (is_string($volume)) {
                    $parsed = parseDockerVolumeString($volume);
                    $source = $parsed['source'];
                    $target = $parsed['target'];
                } elseif (is_array($volume)) {
                    $source = data_get_str($volume, 'source');
                    $target = data_get_str($volume, 'target');
                }
                if ($source && $target) {
                    $targetValue = $target instanceof Stringable ? $target->value() : (string) $target;
                    $rawSources[$targetValue] = $source;
                }
            }
        }

        static::updateFileVolumePaths($application, $rawSources, $envVars, $mainDirectory);
    }

    private static function reResolveForService(Service $service): void
    {
        if ((int) $service->compose_parsing_version < 6) {
            return;
        }

        $dockerComposeRaw = $service->docker_compose_raw;
        if (! $dockerComposeRaw) {
            return;
        }

        $compose = Yaml::parse($dockerComposeRaw);
        if (! isset($compose['services'])) {
            return;
        }

        $serviceEnvVars = $service->environment_variables()->get(['key', 'value'])
            ->mapWithKeys(fn ($item) => [$item['key'] => $item['value']]);

        $mainDirectory = str(base_configuration_dir().'/services/'.$service->uuid);

        $volumeOwners = collect()
            ->merge($service->applications ?? collect())
            ->merge($service->databases ?? collect());

        foreach ($volumeOwners as $owner) {
            $childEnvVars = $owner->environment_variables()->get(['key', 'value'])
                ->mapWithKeys(fn ($item) => [$item['key'] => $item['value']]);
            $mergedEnvVars = $serviceEnvVars->merge($childEnvVars);

            $serviceName = $owner->name ?? null;
            if (! $serviceName) {
                continue;
            }

            $rawSources = [];
            $volumes = data_get($compose, "services.{$serviceName}.volumes", []);
            foreach ($volumes as $volume) {
                $source = null;
                $target = null;
                if (is_string($volume)) {
                    $parsed = parseDockerVolumeString($volume);
                    $source = $parsed['source'];
                    $target = $parsed['target'];
                } elseif (is_array($volume)) {
                    $source = data_get_str($volume, 'source');
                    $target = data_get_str($volume, 'target');
                }
                if ($source && $target) {
                    $targetValue = $target instanceof Stringable ? $target->value() : (string) $target;
                    $rawSources[$targetValue] = $source;
                }
            }

            static::updateFileVolumePaths($owner, $rawSources, $mergedEnvVars, $mainDirectory);
        }
    }

    private static function updateFileVolumePaths($resource, array $rawSources, $envVars, Stringable $mainDirectory): void
    {
        foreach ($resource->fileStorages()->get() as $fileVolume) {
            $rawSource = $rawSources[$fileVolume->mount_path] ?? null;
            if (! $rawSource) {
                continue;
            }

            $sourceStr = $rawSource instanceof Stringable ? $rawSource : str($rawSource);
            if (! str_contains($sourceStr->value(), '${')) {
                continue;
            }

            $resolved = resolveEnvVarDefault($sourceStr, $envVars);
            if (sourceIsLocal($resolved)) {
                $resolved = replaceLocalSource($resolved, $mainDirectory);
                if ($fileVolume->fs_path !== $resolved->value()) {
                    $fileVolume->update(['fs_path' => $resolved->value()]);
                }
            } else {
                // Env var removed or no longer resolves to local path — convert back to named volume
                $slugWithoutUuid = Str::slug($resolved, '-');
                $name = $resource->uuid.'_'.$slugWithoutUuid;
                LocalPersistentVolume::updateOrCreate(
                    [
                        'mount_path' => $fileVolume->mount_path,
                        'resource_id' => $resource->id,
                        'resource_type' => get_class($resource),
                    ],
                    [
                        'name' => $name,
                    ]
                );
                $fileVolume->delete();
            }
        }

        // Reclassify: when env var now resolves to a local path, create LocalFileVolume
        // and remove stale LocalPersistentVolume
        foreach ($rawSources as $mountPath => $rawSource) {
            $sourceStr = $rawSource instanceof Stringable ? $rawSource : str($rawSource);
            if (! str_contains($sourceStr->value(), '${')) {
                continue;
            }

            $resolved = resolveEnvVarDefault($sourceStr, $envVars);
            if (! sourceIsLocal($resolved)) {
                continue;
            }

            if (! $resource->fileStorages()->where('mount_path', $mountPath)->exists()) {
                $fsPath = replaceLocalSource($resolved, $mainDirectory);
                LocalFileVolume::create([
                    'mount_path' => $mountPath,
                    'fs_path' => $fsPath,
                    'is_directory' => true,
                    'resource_id' => $resource->id,
                    'resource_type' => get_class($resource),
                ]);
            }

            $resource->persistentStorages()->where('mount_path', $mountPath)->delete();
        }
    }

    public function loadStorageOnServer()
    {
        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([]);
        $path = str($this->resolvedFsPath());
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }

        // Validate and escape path to prevent command injection
        validateShellSafePath($path, 'storage path');
        $escapedPath = escapeshellarg($path);

        $isFile = instant_remote_process(["test -f {$escapedPath} && echo OK || echo NOK"], $server);
        if ($isFile === 'OK') {
            $content = instant_remote_process(["cat {$escapedPath}"], $server, false);
            // Check if content contains binary data by looking for null bytes or non-printable characters
            if (str_contains($content, "\0") || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content)) {
                $content = '[binary file]';
            }
            $this->content = $content;
            $this->is_directory = false;
            $this->save();
        }
    }

    public function deleteStorageOnServer()
    {
        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([]);
        $path = str($this->resolvedFsPath());
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }

        // Validate and escape path to prevent command injection
        validateShellSafePath($path, 'storage path');
        $escapedPath = escapeshellarg($path);

        $isFile = instant_remote_process(["test -f {$escapedPath} && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d {$escapedPath} && echo OK || echo NOK"], $server);
        if ($path && $path != '/' && $path != '.' && $path != '..') {
            if ($isFile === 'OK') {
                $commands->push("rm -rf {$escapedPath} > /dev/null 2>&1 || true");
            } elseif ($isDir === 'OK') {
                $commands->push("rm -rf {$escapedPath} > /dev/null 2>&1 || true");
                $commands->push("rmdir {$escapedPath} > /dev/null 2>&1 || true");
            }
        }
        if ($commands->count() > 0) {
            return instant_remote_process($commands, $server);
        }
    }

    public function saveStorageOnServer()
    {
        $this->load(['service']);
        $isService = data_get($this->resource, 'service');
        if ($isService) {
            $workdir = $this->resource->service->workdir();
            $server = $this->resource->service->server;
        } else {
            $workdir = $this->resource->workdir();
            $server = $this->resource->destination->server;
        }
        $commands = collect([]);

        // Resolve env var syntax (e.g., ${VAR:-./path} -> ./path) for legacy records
        $fsPath = $this->resolvedFsPath();

        // Validate fs_path early before any shell interpolation
        validateShellSafePath($fsPath, 'storage path');
        $escapedFsPath = escapeshellarg($fsPath);
        $escapedWorkdir = escapeshellarg($workdir);

        if ($this->is_directory) {
            $commands->push("mkdir -p {$escapedFsPath} > /dev/null 2>&1 || true");
            $commands->push("mkdir -p {$escapedWorkdir} > /dev/null 2>&1 || true");
            $commands->push("cd {$escapedWorkdir}");
        }
        if (str($fsPath)->startsWith('.') || str($fsPath)->startsWith('/') || str($fsPath)->startsWith('~')) {
            $parent_dir = str($fsPath)->beforeLast('/');
            if ($parent_dir != '') {
                $escapedParentDir = escapeshellarg($parent_dir);
                $commands->push("mkdir -p {$escapedParentDir} > /dev/null 2>&1 || true");
            }
        }
        $path = str($fsPath);
        $content = data_get($this, 'content');
        if ($path->startsWith('.')) {
            $path = $path->after('.');
            $path = $workdir.$path;
        }

        // Validate and escape resolved path (may differ from fs_path if relative)
        validateShellSafePath($path, 'storage path');
        $escapedPath = escapeshellarg($path);

        $isFile = instant_remote_process(["test -f {$escapedPath} && echo OK || echo NOK"], $server);
        $isDir = instant_remote_process(["test -d {$escapedPath} && echo OK || echo NOK"], $server);
        if ($isFile === 'OK' && $this->is_directory) {
            $content = instant_remote_process(["cat {$escapedPath}"], $server, false);
            $this->is_directory = false;
            $this->content = $content;
            $this->save();
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
            throw new \Exception('The following file is a file on the server, but you are trying to mark it as a directory. Please delete the file on the server or mark it as directory.');
        } elseif ($isDir === 'OK' && ! $this->is_directory) {
            if ($path === '/' || $path === '.' || $path === '..' || $path === '' || str($path)->isEmpty() || is_null($path)) {
                $this->is_directory = true;
                $this->save();
                throw new \Exception('The following file is a directory on the server, but you are trying to mark it as a file. <br><br>Please delete the directory on the server or mark it as directory.');
            }
            instant_remote_process([
                "rm -fr {$escapedPath}",
                "touch {$escapedPath}",
            ], $server, false);
            FileStorageChanged::dispatch(data_get($server, 'team_id'));
        }
        if ($isDir === 'NOK' && ! $this->is_directory) {
            $chmod = data_get($this, 'chmod');
            $chown = data_get($this, 'chown');
            if ($content) {
                $content = base64_encode($content);
                $commands->push("echo '$content' | base64 -d | tee {$escapedPath} > /dev/null");
            } else {
                $commands->push("touch {$escapedPath}");
            }
            $commands->push("chmod +x {$escapedPath}");
            if ($chown) {
                $commands->push("chown $chown {$escapedPath}");
            }
            if ($chmod) {
                $commands->push("chmod $chmod {$escapedPath}");
            }
        } elseif ($isDir === 'NOK' && $this->is_directory) {
            $commands->push("mkdir -p {$escapedPath} > /dev/null 2>&1 || true");
        }

        return instant_remote_process($commands, $server);
    }

    // Accessor for convenient access
    protected function plainMountPath(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->mount_path,
            set: fn ($value) => $this->mount_path = $value
        );
    }

    // Scope for searching
    public function scopeWherePlainMountPath($query, $path)
    {
        return $query->get()->where('plain_mount_path', $path);
    }

    // Check if this volume belongs to a service resource
    public function isServiceResource(): bool
    {
        return in_array($this->resource_type, [
            'App\Models\ServiceApplication',
            'App\Models\ServiceDatabase',
        ]);
    }

    // Determine if this volume should be read-only in the UI
    // File/directory mounts can be edited even for services
    public function shouldBeReadOnlyInUI(): bool
    {
        // Check for explicit :ro flag in compose (existing logic)
        return $this->isReadOnlyVolume();
    }

    // Check if this volume is read-only by parsing the docker-compose content
    public function isReadOnlyVolume(): bool
    {
        try {
            // Only check for services
            $service = $this->service;
            if (! $service || ! method_exists($service, 'service')) {
                return false;
            }

            $actualService = $service->service;
            if (! $actualService || ! $actualService->docker_compose_raw) {
                return false;
            }

            // Parse the docker-compose content
            $compose = Yaml::parse($actualService->docker_compose_raw);
            if (! isset($compose['services'])) {
                return false;
            }

            // Find the service that this volume belongs to
            $serviceName = $service->name;
            if (! isset($compose['services'][$serviceName]['volumes'])) {
                return false;
            }

            $volumes = $compose['services'][$serviceName]['volumes'];

            // Check each volume to find a match
            // Note: We match on mount_path (container path) only, since fs_path gets transformed
            // from relative (./file) to absolute (/data/coolify/services/uuid/file) during parsing
            foreach ($volumes as $volume) {
                // Volume can be string like "host:container:ro" or "host:container"
                if (is_string($volume)) {
                    $parts = explode(':', $volume);

                    // Check if this volume matches our mount_path
                    if (count($parts) >= 2) {
                        $containerPath = $parts[1];
                        $options = $parts[2] ?? null;

                        // Match based on mount_path
                        // Remove leading slash from mount_path if present for comparison
                        $mountPath = str($this->mount_path)->ltrim('/')->toString();
                        $containerPathClean = str($containerPath)->ltrim('/')->toString();

                        if ($mountPath === $containerPathClean || $this->mount_path === $containerPath) {
                            return $options === 'ro';
                        }
                    }
                } elseif (is_array($volume)) {
                    // Long-form syntax: { type: bind, source: ..., target: ..., read_only: true }
                    $containerPath = data_get($volume, 'target');
                    $readOnly = data_get($volume, 'read_only', false);

                    // Match based on mount_path
                    // Remove leading slash from mount_path if present for comparison
                    $mountPath = str($this->mount_path)->ltrim('/')->toString();
                    $containerPathClean = str($containerPath)->ltrim('/')->toString();

                    if ($mountPath === $containerPathClean || $this->mount_path === $containerPath) {
                        return $readOnly === true;
                    }
                }
            }

            return false;
        } catch (\Throwable $e) {
            ray($e->getMessage(), 'Error checking read-only volume');

            return false;
        }
    }
}
