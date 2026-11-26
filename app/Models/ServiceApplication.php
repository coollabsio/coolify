<?php

namespace App\Models;

use App\Support\DomainPortOverrides;
use App\Support\DomainUrlParts;
use App\Support\ServiceEndpoint;
use App\Support\ServiceEndpoints;
use App\Traits\HasImageScans;
use App\Traits\HasNoindexDomains;
use App\Traits\HasRestartLimit;
use App\Traits\ReleasesManagedDnsRecords;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Yaml\Exception\ParseException;

class ServiceApplication extends BaseModel
{
    use HasFactory, HasImageScans, HasNoindexDomains, HasRestartLimit, ReleasesManagedDnsRecords, SoftDeletes;

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
        'domain_endpoints',
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
        'is_permanent_redirect_enabled',
        'traefik_certificate_resolver',
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
        'domain_endpoints',
    ];

    protected $attributes = [
        'is_force_https_enabled' => true,
        'max_restart_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'domain_dns_statuses' => 'array',
            'domain_port_overrides' => 'array',
            'domain_endpoints' => 'array',
            'noindex_domains' => 'array',
            'is_force_https_enabled' => 'boolean',
            'is_permanent_redirect_enabled' => 'boolean',
            'is_removed_from_compose' => 'boolean',
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
            $service->normalizeDomainEndpoints();
        });
    }

    public function restart()
    {
        $container_id = $this->name.'-'.$this->service->uuid;
        instant_remote_process(['docker restart '.escapeshellarg($container_id)], $this->service->server);
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

    public function isPermanentRedirectEnabled(): bool
    {
        return (bool) $this->is_permanent_redirect_enabled;
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
     * Return editable URLs with persisted overrides or legacy embedded ports.
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
                            return $url;
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
    public function portRequiresConfirmation(string $fqdn, ?int $requiredPort): bool
    {
        if ($requiredPort === null) {
            return false;
        }

        $fqdn = trim($fqdn);
        if ($fqdn === '') {
            return false;
        }

        $explicit = self::extractPortFromUrl($fqdn);

        // A domain without a port routes to the required port.
        if ($explicit === null || $explicit === $requiredPort) {
            return false;
        }

        $existingOverride = $this->domain_port_overrides[DomainPortOverrides::withoutPort($fqdn)] ?? null;

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
     * Only the first application of the service with such an image owns the endpoints, because their
     * environment variables are shared by the whole service (for example a MinIO bucket setup container).
     * A stack that embeds the image without all endpoint variables (for example a MinIO with only a
     * SERVICE_URL) is a normal application.
     *
     * @return list<ServiceEndpoint>
     */
    public function serviceEndpoints(): array
    {
        $endpoints = ServiceEndpoints::for($this);
        if ($endpoints === [] || $this->service === null) {
            return $endpoints;
        }
        if ($this->hasLegacyServiceSpecificRouting()) {
            return [];
        }

        $envKeys = array_map(fn (ServiceEndpoint $endpoint): string => $endpoint->envKey, $endpoints);
        if ($this->service->environment_variables()->whereIn('key', $envKeys)->distinct()->count('key') !== count($envKeys)) {
            return [];
        }

        $ownerId = $this->service->applications()->orderBy('id')->get(['id', 'image'])
            ->first(fn (ServiceApplication $application): bool => ServiceEndpoints::for($application) !== [])?->id;

        return $ownerId === $this->id ? $endpoints : [];
    }

    /**
     * The legacy Compose parser still routes these images from their URL variables, so their domains stay unused.
     */
    public function hasLegacyServiceSpecificRouting(): bool
    {
        return $this->serviceType() !== null && (int) $this->service?->compose_parsing_version < 3;
    }

    public function endpointForUrl(string $url): ?ServiceEndpoint
    {
        $key = ($this->domain_endpoints ?? [])[DomainPortOverrides::withoutPort($url)] ?? null;

        return collect($this->serviceEndpoints())->firstWhere('key', $key);
    }

    /**
     * Takes over the endpoint URLs from their environment variables once, generating the empty ones.
     * The fqdn is replaced because only the environment variables were routed before.
     */
    public function importServiceEndpoints(): void
    {
        $endpoints = $this->serviceEndpoints();
        $server = $this->service?->server;
        if ($endpoints === [] || $this->domain_endpoints !== null || $server === null) {
            return;
        }

        $variables = $this->service->environment_variables()
            ->whereIn('key', array_map(fn (ServiceEndpoint $endpoint) => $endpoint->envKey, $endpoints))
            ->get()
            ->keyBy('key');

        $map = [];
        foreach ($endpoints as $endpoint) {
            $variable = $variables->get($endpoint->envKey);
            if ($variable === null) {
                continue;
            }

            $url = filled($variable->value)
                ? DomainPortOverrides::withoutPort(trim($variable->value))
                : generateUrl(server: $server, random: $endpoint->urlPrefix.$this->uuid, forceHttps: $endpoint->forceHttps);
            if (blank($variable->value)) {
                $variable->update(['value' => $url]);
            }
            $map[$url] ??= $endpoint->key;
        }

        $this->fqdn = $map === [] ? null : implode(',', array_keys($map));
        $this->domain_port_overrides = null;
        $this->domain_endpoints = $map;
        $this->save();
    }

    /**
     * Writes the first URL of each endpoint to its environment variable, or an empty value when it has none.
     *
     * @return array<string, string> the synced values by variable key
     */
    public function syncServiceEndpointVariables(): array
    {
        $endpoints = $this->serviceEndpoints();
        if ($endpoints === [] || $this->domain_endpoints === null) {
            return [];
        }

        $variables = $this->service->environment_variables()
            ->whereIn('key', array_map(fn (ServiceEndpoint $endpoint) => $endpoint->envKey, $endpoints))
            ->get()
            ->keyBy('key');

        $synced = [];
        foreach ($endpoints as $endpoint) {
            $variable = $variables->get($endpoint->envKey);
            if ($variable === null) {
                continue;
            }

            $url = array_search($endpoint->key, $this->domain_endpoints, true);
            $value = $url === false ? '' : (string) $url;
            if ((string) $variable->value !== $value) {
                $variable->update(['value' => $value]);
            }
            $synced[$endpoint->envKey] = $value;
        }

        return $synced;
    }

    /**
     * Maps each domain to an endpoint and gives it that endpoint's port. New domains take the endpoint
     * of their port, then of their www counterpart, then the primary endpoint.
     */
    protected function normalizeDomainEndpoints(): void
    {
        $endpoints = collect($this->serviceEndpoints())->keyBy('key');
        if ($endpoints->isEmpty() || $this->domain_endpoints === null) {
            return;
        }

        $previous = $this->domain_endpoints;
        $overrides = $this->domain_port_overrides ?? [];
        $map = [];
        foreach ($this->fqdns as $url) {
            $url = DomainPortOverrides::withoutPort(trim($url));
            if ($url === '') {
                continue;
            }
            $counterpart = DomainPortOverrides::wwwCounterpart($url) ?? '';
            $endpoint = $endpoints->get($previous[$url] ?? '')
                ?? $endpoints->first(fn (ServiceEndpoint $endpoint): bool => $endpoint->port === (int) ($overrides[$url] ?? 0))
                ?? $endpoints->get($map[$counterpart] ?? $previous[$counterpart] ?? '')
                ?? $endpoints->first();
            $map[$url] = $endpoint->key;
        }

        $this->domain_endpoints = $map;
        $this->domain_port_overrides = array_map(fn (string $key): int => $endpoints[$key]->port, $map) ?: null;
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
     * Whether the proxy labels of this container in the Compose file add HTTP basic auth
     * (a Traefik `basicauth` middleware or a Caddy `basic_auth` directive).
     */
    public function hasProxyBasicAuth(): bool
    {
        try {
            $dockerCompose = parseDockerComposeYaml((string) data_get($this->service, 'docker_compose_raw'));
        } catch (ParseException) {
            return false;
        }

        $labels = $dockerCompose['services'][$this->name]['labels'] ?? [];
        if (! is_array($labels)) {
            return false;
        }

        foreach ($labels as $key => $value) {
            $label = is_int($key) ? (string) $value : (string) $key;
            if (preg_match('/basic_?auth/i', $label) === 1) {
                return true;
            }
        }

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

            $dockerCompose = parseDockerComposeYaml($dockerComposeRaw);
            $serviceConfig = $dockerCompose['services'][$this->name] ?? null;
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

            $composePort = firstDockerComposeServicePort($serviceConfig);
            if ($composePort !== null) {
                return $composePort;
            }

            // HTTP-facing compose services that only declare SERVICE_URL/FQDN (no _PORT
            // suffix), such as WordPress, inherit the one-click template `# port:`.
            if ($declaresHttpUrl) {
                if (blank($this->service->service_type)) {
                    $savedPort = $this->getSavedLegacyRoutingPort($serviceConfig);
                    if ($savedPort !== null) {
                        return $savedPort;
                    }
                }

                return $this->service->getRequiredPort();
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Preserve only an unambiguous upstream from this legacy container's saved labels.
     */
    private function getSavedLegacyRoutingPort(array $serviceConfig): ?int
    {
        $savedCompose = parseDockerComposeYaml($this->service->docker_compose ?? '');
        $savedService = $savedCompose['services'][$this->name] ?? null;
        $image = $serviceConfig['image'] ?? null;
        if (! is_string($image) || $image === '' || ($savedService['image'] ?? null) !== $image) {
            return null;
        }

        $labels = $savedService['labels'] ?? [];
        if (! is_array($labels)) {
            return null;
        }

        $ports = [];
        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value)) {
                    return null;
                }
                [$key, $value] = array_pad(explode('=', $value, 2), 2, null);
            }

            if (preg_match('/^traefik\.http\.services\.[^.]+\.loadbalancer\.server\.port$/', $key)) {
                $port = $value;
            } elseif (preg_match('/^caddy(?:_\d+)?\..*reverse_proxy$/', $key)) {
                if (! is_string($value) || ! preg_match('/^\{\{upstreams ([0-9]+)\}\}$/', $value, $matches)) {
                    return null;
                }
                $port = $matches[1];
            } else {
                continue;
            }

            if ((! is_string($port) && ! is_int($port)) || ! preg_match('/^[0-9]+$/', (string) $port) || (int) $port < 1 || (int) $port > 65535) {
                return null;
            }
            $ports[] = (int) $port;
        }

        $ports = array_values(array_unique($ports));

        return count($ports) === 1 ? $ports[0] : null;
    }
}
