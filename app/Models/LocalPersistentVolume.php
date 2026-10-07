<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class LocalPersistentVolume extends BaseModel
{
    protected static function booted(): void
    {
        static::deleting(function (LocalPersistentVolume $volume): void {
            if ($volume->scheduledBackups()->exists()) {
                throw new \RuntimeException('Delete this volume backup schedule and its archives before deleting the volume.');
            }
        });

        // A copy is a new Docker volume, so Docker Compose creates it with the driver options.
        static::replicating(function (LocalPersistentVolume $volume): void {
            $volume->ignores_compose_driver_options = false;
        });
    }

    protected $fillable = [
        'name',
        'mount_path',
        'host_path',
        'standalone_sqlite_id',
        'container_id',
        'resource_type',
        'resource_id',
        'is_preview_suffix_enabled',
    ];

    protected $casts = [
        'is_preview_suffix_enabled' => 'boolean',
        'ignores_compose_driver_options' => 'boolean',
    ];

    public function resource()
    {
        return $this->morphTo('resource');
    }

    public function application()
    {
        return $this->morphTo('resource');
    }

    public function service()
    {
        return $this->morphTo('resource');
    }

    public function database()
    {
        return $this->morphTo('resource');
    }

    public function scheduledBackups(): MorphMany
    {
        return $this->morphMany(ScheduledVolumeBackup::class, 'backupable');
    }

    /**
     * The SQLite database this volume was connected from, if any.
     */
    public function standaloneSqlite(): BelongsTo
    {
        return $this->belongsTo(StandaloneSqlite::class);
    }

    public function abortIfScheduledBackupsExist(): void
    {
        if ($this->scheduledBackups()->exists()) {
            abort(422, 'Delete this volume backup schedule and its archives before deleting the volume.');
        }
    }

    /**
     * Whether another resource mounts the same Docker volume, e.g. an application that uses a SQLite database volume.
     */
    public function isSharedWithAnotherResource(): bool
    {
        if ($this->standalone_sqlite_id !== null) {
            return true;
        }

        if ($this->resource_type !== StandaloneSqlite::class) {
            return false;
        }

        $isConnected = static::query()
            ->where('standalone_sqlite_id', $this->resource_id)
            ->where('name', $this->name)
            ->exists();

        return $isConnected
            || ($this->resource instanceof StandaloneSqlite && $this->resource->composeApplicationsUsingDataVolume($this->name)->isNotEmpty());
    }

    protected function customizeName($value)
    {
        return str($value)->trim()->value;
    }

    protected function mountPath(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => str($value)->trim()->start('/')->value
        );
    }

    protected function hostPath(): Attribute
    {
        return Attribute::make(
            set: function (?string $value) {
                if ($value) {
                    return str($value)->trim()->start('/')->value;
                } else {
                    return $value;
                }
            }
        );
    }

    // Check if this volume belongs to a service resource
    public function isServiceResource(): bool
    {
        return in_array($this->resource_type, [
            'App\Models\ServiceApplication',
            'App\Models\ServiceDatabase',
        ]);
    }

    // Check if this volume belongs to a dockercompose application
    public function isDockerComposeResource(): bool
    {
        if ($this->resource_type !== 'App\Models\Application') {
            return false;
        }

        // Only access relationship if already eager loaded to avoid N+1
        if (! $this->relationLoaded('resource')) {
            return false;
        }

        $application = $this->resource;
        if (! $application) {
            return false;
        }

        return data_get($application, 'build_pack') === 'dockercompose';
    }

    // Determine if this volume should be read-only in the UI
    // Service volumes and dockercompose application volumes are read-only
    // (users should edit compose file directly)
    public function shouldBeReadOnlyInUI(): bool
    {
        // All service volumes should be read-only in UI
        if ($this->isServiceResource()) {
            return true;
        }

        // All dockercompose application volumes should be read-only in UI
        if ($this->isDockerComposeResource()) {
            return true;
        }

        // Check for explicit :ro flag in compose (existing logic)
        return $this->isReadOnlyVolume();
    }

    public function isDeclaredInCompose(): bool
    {
        try {
            $resource = $this->resource;
            if (! $resource) {
                return true;
            }

            $composeContent = $resource instanceof Application
                ? $resource->docker_compose_raw
                : data_get($resource, 'service.docker_compose_raw');

            if (blank($composeContent)) {
                return true;
            }

            $compose = parseDockerComposeYaml($composeContent);
            $services = data_get($compose, 'services', []);
            $topLevelVolumes = collect(data_get($compose, 'volumes') ?? []);

            if ($this->isServiceResource()) {
                $services = array_intersect_key($services, [$resource->name => true]);
            }

            foreach ($services as $service) {
                foreach (data_get($service, 'volumes', []) as $volume) {
                    $parsedVolume = is_array($volume) ? $volume : parseDockerVolumeString($volume);
                    $source = data_get($parsedVolume, 'source');
                    if (is_string($volume) && $source) {
                        $source = composeNamedVolumeSource(str($source));
                    }
                    $target = data_get($parsedVolume, 'target');
                    if ($source && isComposeExternalVolume($topLevelVolumes->get((string) $source))) {
                        // The parsers use an external volume as written. A storage entry with the generated
                        // name is an old entry that the user can delete (see replacedExternalComposeVolume()).
                        continue;
                    }
                    $resourceUuid = $resource instanceof Application ? $resource->uuid : data_get($resource, 'service.uuid');
                    $generatedName = $source ? $resourceUuid.'_'.Str::slug($source, '-') : null;

                    if ($generatedName === $this->name && $target && str($target)->start('/')->value() === $this->mount_path) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * The external Compose volume that this storage entry still replaces, or null. Before Coolify
     * used external volumes as written, the parsers gave them a generated name, for example
     * "{uuid}_{volume}". While this storage entry exists, the parsers keep that name so that the
     * resource keeps its data (see useComposeExternalVolumeAsWritten()). A preview volume
     * ("{uuid}_{volume}-pr-{id}") replaces nothing: a preview never uses the external volume.
     */
    public function replacedExternalComposeVolume(): ?string
    {
        try {
            $resource = $this->resource;
            if (! $resource) {
                return null;
            }

            $composeContent = $resource instanceof Application
                ? $resource->docker_compose_raw
                : data_get($resource, 'service.docker_compose_raw');
            if (blank($composeContent)) {
                return null;
            }

            foreach (data_get(parseDockerComposeYaml($composeContent), 'volumes') ?? [] as $key => $declaration) {
                $key = (string) $key;
                if (! isComposeExternalVolume($declaration)) {
                    continue;
                }

                $legacyName = match (true) {
                    $resource instanceof Application && (int) $resource->compose_parsing_version < 3 => legacyApplicationComposeVolumeName($resource, $key, 0),
                    $resource instanceof Application => $resource->uuid.'_'.Str::slug($key, '-'),
                    default => data_get($resource, 'service.uuid').'_'.Str::slug($key, '-'),
                };
                if ($legacyName !== $key && $this->name === $legacyName) {
                    return $key;
                }
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether the Compose file gives this volume `driver`, `driver_opts` or `labels` that the parsers
     * do not apply, because the volume was created before Coolify kept them. Docker Compose would ask
     * to recreate such a volume, so the parsers keep its old name-only declaration.
     */
    public function ignoresComposeDriverOptionsOfDeclaration(): bool
    {
        if (! $this->ignores_compose_driver_options) {
            return false;
        }

        try {
            $resource = $this->resource;
            if (! $resource) {
                return false;
            }

            $composeContent = $resource instanceof Application
                ? $resource->docker_compose_raw
                : data_get($resource, 'service.docker_compose_raw');
            if (blank($composeContent)) {
                return false;
            }

            $resourceUuid = $resource instanceof Application ? $resource->uuid : data_get($resource, 'service.uuid');
            foreach (data_get(parseDockerComposeYaml($composeContent), 'volumes') ?? [] as $key => $declaration) {
                if (! is_array($declaration) || isComposeExternalVolume($declaration)) {
                    continue;
                }
                $generatedName = $resourceUuid.'_'.Str::slug((string) $key, '-');
                if ($this->name !== $generatedName && preg_match('/^'.preg_quote($generatedName, '/').'-pr-\d+$/', $this->name) !== 1) {
                    continue;
                }

                return filled(data_get($declaration, 'driver'))
                    || filled(data_get($declaration, 'driver_opts'))
                    || filled(data_get($declaration, 'labels'));
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    // Check if this volume is read-only by parsing the docker-compose content
    public function isReadOnlyVolume(): bool
    {
        try {
            // Get the resource (can be application, service, or database)
            $resource = $this->resource;
            if (! $resource) {
                return false;
            }

            // Only check for services
            if (! method_exists($resource, 'service')) {
                return false;
            }

            $actualService = $resource->service;
            if (! $actualService || ! $actualService->docker_compose_raw) {
                return false;
            }

            // Parse the docker-compose content
            $compose = parseDockerComposeYaml($actualService->docker_compose_raw);
            if (! isset($compose['services'])) {
                return false;
            }

            // Find the service that this volume belongs to
            $serviceName = $resource->name;
            if (! isset($compose['services'][$serviceName]['volumes'])) {
                return false;
            }

            $volumes = $compose['services'][$serviceName]['volumes'];

            // Check each volume to find a match
            // Note: We match on mount_path (container path) only, since host paths get transformed
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
                    // Long-form syntax: { type: bind/volume, source: ..., target: ..., read_only: true }
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

            return false;
        }
    }
}
