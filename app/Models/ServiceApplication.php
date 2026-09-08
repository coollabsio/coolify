<?php

namespace App\Models;

use App\Support\DomainPortOverrides;
use App\Support\DomainUrlParts;
use App\Traits\HasNoindexDomains;
use App\Traits\HasRestartLimit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Yaml\Yaml;

class ServiceApplication extends BaseModel
{
    use HasFactory, HasNoindexDomains, HasRestartLimit, SoftDeletes;

    protected $appends = ['url'];

    protected $fillable = [
        'service_id',
        'name',
        'human_name',
        'description',
        'fqdn',
        'noindex_domains',
        'redirect',
        'domain_dns_statuses',
        'domain_port_overrides',
        'ports',
        'exposes',
        'status',
        'exclude_from_status',
        'required_fqdn',
        'image',
        'is_log_drain_enabled',
        'is_include_timestamps',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'is_force_https_enabled',
        'last_online_at',
        'is_migrated',
    ];

    /**
     * Internal DNS check cache — not part of the public API surface.
     *
     * @var list<string>
     */
    protected $hidden = [
        'domain_dns_statuses',
        'domain_port_overrides',
    ];

    protected $attributes = [
        'is_force_https_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'domain_dns_statuses' => 'array',
            'domain_port_overrides' => 'array',
            'noindex_domains' => 'array',
            'is_force_https_enabled' => 'boolean',
        ];
    }

    protected static function booted()
    {
        static::deleting(function ($service) {
            $service->update(['fqdn' => null]);
            $service->persistentStorages()->delete();
            $service->fileStorages()->delete();
        });
        static::saving(function ($service) {
            if ($service->isDirty('status')) {
                $service->last_online_at = now();
            }
            if ($service->isDirty('fqdn')) {
                $service->normalizeDomainPortOverrides();
                $service->syncNoindexDomains();
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

    /**
     * Get query builder for service applications owned by current team.
     * If you need all service applications without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    public static function ownedByCurrentTeam()
    {
        return ServiceApplication::whereRelation('service.environment.project.team', 'id', currentTeam()->id)->orderBy('name');
    }

    /**
     * Get all service applications owned by current team (cached for request duration).
     */
    public static function ownedByCurrentTeamCached()
    {
        return once(function () {
            return ServiceApplication::ownedByCurrentTeam()->get();
        });
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

    public function isForceHttpsEnabled(): bool
    {
        return $this->is_force_https_enabled;
    }

    public function type()
    {
        return 'service';
    }

    public function team()
    {
        return data_get($this, 'service.environment.project.team');
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
     * Return the public URLs with their persisted internal port overrides.
     */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (blank($this->fqdn)) {
                    return null;
                }

                $overrides = $this->domain_port_overrides ?? [];

                return collect(explode(',', $this->fqdn))
                    ->map(function (string $url) use ($overrides): string {
                        $url = trim($url);
                        $canonical = DomainPortOverrides::withoutPort($url);
                        $port = $overrides[$canonical] ?? null;

                        if ($port === null) {
                            return $canonical;
                        }

                        $parts = DomainUrlParts::split($canonical);

                        return DomainUrlParts::compose($parts['scheme'], $parts['host'], (string) $port, $parts['path']);
                    })
                    ->implode(',');
            },
        );
    }

    public function setEditableUrls(?string $urls): void
    {
        $normalized = DomainPortOverrides::normalize($urls, null);
        $this->fqdn = $normalized['fqdn'];
        $this->domain_port_overrides = $normalized['overrides'];
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
     * True when saving this URL should confirm that it does not use the required template port.
     */
    public function portRequiresConfirmation(string $fqdn, ?int $requiredPort, ?string $previousFqdn = null): bool
    {
        if ($requiredPort === null) {
            return false;
        }

        $fqdn = trim($fqdn);
        if ($fqdn === '') {
            return false;
        }

        $canonical = DomainPortOverrides::withoutPort($fqdn);
        $explicit = self::extractPortFromUrl($fqdn);

        if ($explicit === $requiredPort) {
            return false;
        }

        if ($explicit === null) {
            $previous = collect(explode(',', (string) $previousFqdn))
                ->filter();
            $previousUrl = $previous->first(
                fn (string $url): bool => DomainPortOverrides::withoutPort(trim($url)) === $canonical
            );

            if (is_string($previousUrl) && self::extractPortFromUrl($previousUrl) !== null) {
                return true;
            }

            return $previousUrl === null;
        }

        $existingOverride = $this->domain_port_overrides[$canonical] ?? null;

        return (int) $existingOverride !== $explicit;
    }

    public static function withoutPort(string $url): string
    {
        return DomainPortOverrides::withoutPort($url);
    }

    protected function normalizeDomainPortOverrides(): void
    {
        $normalized = DomainPortOverrides::normalize($this->fqdn, $this->domain_port_overrides);
        $this->fqdn = $normalized['fqdn'];
        $this->domain_port_overrides = $normalized['overrides'];
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
            // Parse the Docker Compose to find SERVICE_URL/SERVICE_FQDN variables DIRECTLY DECLARED
            // for this specific service container (not just referenced from other containers)
            $dockerComposeRaw = data_get($this->service, 'docker_compose_raw');
            if (! $dockerComposeRaw) {
                // Fall back to service-level port if no compose file
                return $this->service->getRequiredPort();
            }

            $dockerCompose = Yaml::parse($dockerComposeRaw);
            $serviceConfig = data_get($dockerCompose, "services.{$this->name}");
            if (! $serviceConfig) {
                return $this->service->getRequiredPort();
            }

            $environment = data_get($serviceConfig, 'environment', []);

            // Extract SERVICE_URL and SERVICE_FQDN variables DIRECTLY DECLARED in this service's environment
            // (not variables that are merely referenced with ${VAR} syntax)
            $portFound = null;
            $declaresHttpUrl = false;
            foreach ($environment as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    // List-style: "- SERVICE_URL_APP_3000" or "- SERVICE_URL_APP_3000=value"
                    // Extract variable name (before '=' if present)
                    $envVarName = str($value)->before('=')->trim();

                    // Only process direct declarations
                    if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                        $declaresHttpUrl = true;
                        // Parse to check if it has a port suffix
                        $parsed = parseServiceEnvironmentVariable($envVarName->value());
                        if ($parsed['has_port'] && $parsed['port']) {
                            // Found a port-specific variable for this service
                            $portFound = (int) $parsed['port'];
                            break;
                        }
                    }
                } elseif (is_string($key)) {
                    // Map-style: "SERVICE_URL_APP_3000: value" or "SERVICE_FQDN_DB: localhost"
                    $envVarName = str($key);

                    // Only process direct declarations
                    if ($envVarName->startsWith('SERVICE_FQDN_') || $envVarName->startsWith('SERVICE_URL_')) {
                        $declaresHttpUrl = true;
                        // Parse to check if it has a port suffix
                        $parsed = parseServiceEnvironmentVariable($envVarName->value());
                        if ($parsed['has_port'] && $parsed['port']) {
                            // Found a port-specific variable for this service
                            $portFound = (int) $parsed['port'];
                            break;
                        }
                    }
                }
            }

            // If a port was found in the template, return it
            if ($portFound !== null) {
                return $portFound;
            }

            // HTTP-facing compose services that only declare SERVICE_URL/FQDN (no _PORT
            // suffix), such as WordPress, inherit the one-click template `# port:`.
            if ($declaresHttpUrl) {
                return $this->service->getRequiredPort();
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
