<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceApplication extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->update(['fqdn' => null]);
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->forceFill(['last_online_at' => now()]);
            }
        });
    }

    public function restart()
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        instant_remote_process(["docker restart {$container_id}"], $this->service->server);
    }

    public static function ownedByCurrentTeamAPI(int $teamId)
    {
        return ServiceApplication::whereRelation('service.environment.project.team', 'id', $teamId)->orderBy('name');
    }

    public static function ownedByCurrentTeam()
    {
        return ServiceApplication::whereRelation('service.environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    public function isRunning()
    {
        return str($this->status)->contains('running');
    }

    public function isExited()
    {
        return str($this->status)->contains('exited');
    }

    public function isLogDrainEnabled()
    {
        return data_get($this, 'is_log_drain_enabled', false);
    }

    public function isStripprefixEnabled()
    {
        return data_get($this, 'is_stripprefix_enabled', true);
    }

    public function isGzipEnabled()
    {
        return data_get($this, 'is_gzip_enabled', true);
    }

    public function type()
    {
        return 'service';
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function workdir()
    {
        return service_configuration_dir()."/{$this->service->uuid}";
    }

    public function serviceType()
    {
        $found = str(collect(SPECIFIC_SERVICES)->filter(function ($service) {
            return str($this->image)->before(':')->value() === $service;
        })->first());
        if ($found->isNotEmpty()) {
            return $found;
        }

        return null;
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function fileStorages()
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function environment_variables()
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable');
    }

    public function fqdns(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->fqdn)
                ? []
                : explode(',', $this->fqdn),
        );
    }

    /**
     * Extract port number from a given FQDN URL.
     * Returns null if no port is specified.
     */
    public static function extractPortFromUrl(string $url): ?int
    {
        try {
            // Ensure URL has a scheme for proper parsing
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'http://'.$url;
            }

            $parsed = parse_url($url);
            $port = $parsed['port'] ?? null;

            return $port ? (int) $port : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Check if all FQDNs have a port specified.
     */
    public function allFqdnsHavePort(): bool
    {
        if (is_null($this->fqdn) || $this->fqdn === '') {
            return false;
        }

        $fqdns = explode(',', $this->fqdn);

        foreach ($fqdns as $fqdn) {
            $fqdn = trim($fqdn);
            if (empty($fqdn)) {
                continue;
            }

            $port = self::extractPortFromUrl($fqdn);
            if ($port === null) {
                return false;
            }
        }

        return true;
    }

    public function getFilesFromServer(bool $isInit = false)
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function isBackupSolutionAvailable()
    {
        return false;
    }

    /**
     * Get the required port for this service application.
     * Extracts port from SERVICE_URL_* or SERVICE_FQDN_* environment variables
     * stored at the Service level, filtering by normalized container name.
     * Falls back to service-level port if no port-specific variable is found.
     */
    public function getRequiredPort(): ?int
    {
        try {
            // Normalize container name same way as variable creation
            // (uppercase, replace - and . with _)
            $normalizedName = str($this->name)
                ->upper()
                ->replace('-', '_')
                ->replace('.', '_')
                ->value();
            // Get all environment variables from the service
            $serviceEnvVars = $this->service->environment_variables()->get();

            // Look for SERVICE_FQDN_* or SERVICE_URL_* variables that match this container
            foreach ($serviceEnvVars as $envVar) {
                $key = str($envVar->key);

                // Check if this is a SERVICE_FQDN_* or SERVICE_URL_* variable
                if (! $key->startsWith('SERVICE_FQDN_') && ! $key->startsWith('SERVICE_URL_')) {
                    continue;
                }
                // Extract the part after SERVICE_FQDN_ or SERVICE_URL_
                if ($key->startsWith('SERVICE_FQDN_')) {
                    $suffix = $key->after('SERVICE_FQDN_');
                } else {
                    $suffix = $key->after('SERVICE_URL_');
                }

                // Check if this variable starts with our normalized container name
                // Format: {NORMALIZED_NAME}_{PORT} or just {NORMALIZED_NAME}
                if (! $suffix->startsWith($normalizedName)) {
                    \Log::debug('[ServiceApplication::getRequiredPort] Suffix does not match container', [
                        'expected_start' => $normalizedName,
                        'actual_suffix' => $suffix->value(),
                    ]);

                    continue;
                }

                // Check if there's a port suffix after the container name
                // The suffix should be exactly NORMALIZED_NAME or NORMALIZED_NAME_PORT
                $afterName = $suffix->after($normalizedName)->value();

                // If there's content after the name, it should start with underscore
                if ($afterName !== '' && str($afterName)->startsWith('_')) {
                    // Extract port: _3210 -> 3210
                    $port = str($afterName)->after('_')->value();
                    // Validate that the extracted port is numeric
                    if (is_numeric($port)) {
                        \Log::debug('[ServiceApplication::getRequiredPort] MATCH FOUND - Returning port', [
                            'port' => (int) $port,
                        ]);

                        return (int) $port;
                    }
                }
            }

            // Fall back to service-level port if no port-specific variable is found
            $fallbackPort = $this->service->getRequiredPort();

            return $fallbackPort;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
