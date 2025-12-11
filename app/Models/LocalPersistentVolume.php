<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Yaml\Yaml;

class LocalPersistentVolume extends Model
{
    protected $guarded = [];

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
            $compose = Yaml::parse($actualService->docker_compose_raw);
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
            ray($e->getMessage(), 'Error checking read-only persistent volume');

            return false;
        }
    }
}
