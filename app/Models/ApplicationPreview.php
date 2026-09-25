<?php

namespace App\Models;

use App\Support\DomainPortOverrides;
use App\Support\ValidationPatterns;
use App\Traits\HasRestartLimit;
use App\Traits\ReleasesManagedDnsRecords;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RuntimeException;
use Spatie\Url\Url;
use Throwable;

class ApplicationPreview extends BaseModel
{
    use HasRestartLimit, ReleasesManagedDnsRecords, SoftDeletes;

    protected $attributes = [
        'max_restart_count' => 0,
    ];

    protected $fillable = [
        'uuid',
        'application_id',
        'pull_request_id',
        'pull_request_html_url',
        'pull_request_issue_comment_id',
        'fqdn',
        'status',
        'git_type',
        'docker_compose_domains',
        'docker_registry_image_tag',
        'last_online_at',
        'domain_dns_statuses',
        'domain_port_overrides',
    ];

    protected $hidden = [
        'domain_port_overrides',
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
        'domain_dns_statuses' => 'array',
        'domain_port_overrides' => 'array',
    ];

    protected static function booted(): void
    {
        static::forceDeleting(function (ApplicationPreview $preview): void {
            $server = $preview->application->destination->server;
            $application = $preview->application;

            if (data_get($preview, 'application.build_pack') === 'dockercompose') {
                $composeFile = $application->parse(pull_request_id: $preview->pull_request_id);
                $volumeNames = $preview->generatedComposeVolumeNames($application, collect(data_get($composeFile, 'volumes', [])));
                foreach ($volumeNames as $volumeName) {
                    // Docker does not remove a volume that a container still uses, also with -f.
                    instant_remote_process(['docker volume rm -f '.escapeshellarg($volumeName)], $server, false);
                }
                // The compose parser stores preview volumes on the application, not on the preview
                $application->persistentStorages()->whereIn('name', $volumeNames)->delete();
                $preview->removeComposePreviewNetwork($application, $server);
            } else {
                // Regular application volume cleanup
                $persistentStorages = $application->persistentStorages()
                    ->get()
                    ->filter(fn (LocalPersistentVolume $storage): bool => blank($storage->host_path)
                        && $storage->is_preview_suffix_enabled);

                foreach ($persistentStorages as $storage) {
                    $volumeName = addPreviewDeploymentSuffix($storage->name, $preview->pull_request_id);
                    try {
                        instant_remote_process(['docker volume rm -f '.escapeshellarg($volumeName)], $server);
                    } catch (RuntimeException $exception) {
                        if (! preg_match('/\bvolume\b.*\bnot found\b/i', $exception->getMessage())) {
                            throw $exception;
                        }
                    }
                }
            }

            // Clean up persistent storage records
            $preview->persistentStorages()->delete();
        });
        static::saving(function ($preview) {
            if ($preview->isDirty('status')) {
                $preview->last_online_at = now();
            }
            if ($preview->isDirty('fqdn')) {
                if ($preview->fqdn === '') {
                    $preview->fqdn = null;
                }
                $normalized = DomainPortOverrides::normalize($preview->fqdn, $preview->domain_port_overrides);
                $preview->fqdn = $normalized['fqdn'];
                $preview->domain_port_overrides = $normalized['overrides'];
            }
        });
    }

    /**
     * Names of the Docker Compose volumes that Coolify generated for this preview. The parser names
     * them "{uuid}_{volume}-pr-{id}" ("{uuid}-{volume}-pr-{id}" before parser version 3). Volumes
     * named in the Compose file (external, shared, or of the main application) are not included.
     *
     * @param  Collection<array-key, mixed>  $volumes  top-level volumes of the parsed preview Compose file
     * @return list<string>
     */
    private function generatedComposeVolumeNames(Application $application, Collection $volumes): array
    {
        $prefix = $application->uuid.((int) $application->compose_parsing_version >= 3 ? '_' : '-');
        $suffix = addPreviewDeploymentSuffix('', $this->pull_request_id);

        return $volumes
            ->map(function (mixed $volume, int|string $key) use ($prefix, $suffix): ?string {
                $key = (string) $key;
                if (isComposeExternalVolume($volume)) {
                    return null;
                }
                if (data_get($volume, 'name', $key) !== $key) {
                    return null;
                }
                if (strlen($key) <= strlen($prefix.$suffix)
                    || ! str_starts_with($key, $prefix)
                    || ! str_ends_with($key, $suffix)
                    || ! preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $key)) {
                    return null;
                }

                return $key;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Removes the network that the deployment created for this preview ("{uuid}-{id}"). Networks
     * named in the Compose file (such as coolify or an external proxy network) are never removed.
     * The network stays when a container other than coolify-proxy still uses it.
     */
    private function removeComposePreviewNetwork(Application $application, Server $server): void
    {
        $network = "{$application->uuid}-{$this->pull_request_id}";
        if (! isUsableDockerNetworkName($network) || $network === $application->destination?->network) {
            return;
        }

        $quotedNetwork = escapeshellarg($network);
        try {
            $output = instant_remote_process(["docker network inspect --format '{{json .Containers}}' {$quotedNetwork}"], $server);
        } catch (Throwable) {
            // The network does not exist or its state is not known.
            return;
        }

        $containers = json_decode($output ?? '{}', true);
        if (! is_array($containers)) {
            return;
        }
        $otherContainers = collect($containers)
            ->map(fn (mixed $container): mixed => data_get($container, 'Name'))
            ->reject(fn (mixed $name): bool => $name === 'coolify-proxy');
        if ($otherContainers->isNotEmpty()) {
            return;
        }

        instant_remote_process(["docker network disconnect {$quotedNetwork} coolify-proxy"], $server, false);
        instant_remote_process(["docker network rm {$quotedNetwork}"], $server, false);
    }

    public static function findPreviewByApplicationAndPullId(int $application_id, int $pull_request_id)
    {
        return self::where('application_id', $application_id)->where('pull_request_id', $pull_request_id)->firstOrFail();
    }

    public function isRunning()
    {
        return (bool) str($this->status)->startsWith('running');
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function restartLimitMaximum(): int
    {
        return $this->application->max_restart_count ?? $this->max_restart_count ?? 0;
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function generate_preview_fqdn(bool $generateWithoutApplicationDomain = false)
    {
        $applicationFqdn = $this->application->fqdn;
        if (! $applicationFqdn && $generateWithoutApplicationDomain) {
            $applicationFqdn = generateUrl(
                server: $this->application->destination->server,
                random: $this->application->uuid,
            );
        }

        if ($applicationFqdn) {
            $sourceDomain = str($applicationFqdn)->contains(',')
                ? str($applicationFqdn)->explode(',')[0]
                : $applicationFqdn;
            $generated = $this->generatedPreviewDomain((string) $sourceDomain);
            $this->fqdn = $generated['url'];
            $this->domain_port_overrides = filled($generated['port'])
                ? [$generated['url'] => $generated['port']]
                : null;
            $this->save();
        }

        return $this;
    }

    public function generate_preview_fqdn_compose(bool $generateWithoutApplicationDomain = false)
    {
        $applicationDomains = json_decode($this->application->docker_compose_domains ?: '[]', true) ?: [];
        $previewDomains = json_decode(data_get($this, 'docker_compose_domains') ?: '[]', true) ?: [];

        $composeServiceNames = $this->composeServiceNamesForPreview();
        // Canonical compose names first; collapse leftover domain-map twins when parse is empty/missing.
        $domainKeys = collect(array_keys($applicationDomains))
            ->merge(array_keys($previewDomains))
            ->map(fn ($name) => (string) $name);
        $unmappedDomainKeys = $domainKeys
            ->reject(fn (string $key) => findComposeServiceName($key, $composeServiceNames) !== null)
            ->all();
        $knownServiceNames = collect($composeServiceNames)
            ->merge(preferredComposeServiceNamesFromDomainKeys(
                $composeServiceNames === [] ? $domainKeys->all() : $unmappedDomainKeys
            ))
            ->unique()
            ->values()
            ->all();

        $applicationDomains = rekeyComposeDomainsToServiceNames($applicationDomains, $knownServiceNames);
        $previewDomains = rekeyComposeDomainsToServiceNames($previewDomains, $knownServiceNames);

        // Ensure every non-database compose service has a domain slot (empty if unset).
        foreach ($composeServiceNames as $serviceName) {
            if (! array_key_exists($serviceName, $applicationDomains)) {
                $applicationDomains[$serviceName] = ['domain' => ''];
            }
        }

        $serviceNames = collect(array_keys($applicationDomains))
            ->merge($composeServiceNames)
            ->map(fn ($name) => (string) $name)
            ->unique()
            ->values()
            ->all();

        $docker_compose_domains = [];
        $previewPortOverrides = [];
        foreach ($serviceNames as $service_name) {
            $domain_string = getComposeServiceDomainString($applicationDomains, $service_name);

            if (empty($domain_string)) {
                if ($generateWithoutApplicationDomain) {
                    $domain_string = generateUrl(
                        server: $this->application->destination->server,
                        random: str($service_name)->slug().'-'.$this->application->uuid,
                    );
                }
            }

            if (empty($domain_string)) {
                $docker_compose_domains = putComposeServiceDomain(
                    $docker_compose_domains,
                    $service_name,
                    '',
                    $serviceNames,
                );

                continue;
            }

            $service_domains = str($domain_string)->explode(',')->map(fn ($d) => trim($d));

            $preview_domains = [];
            foreach ($service_domains as $domain) {
                if (empty($domain)) {
                    continue;
                }

                $generated = $this->generatedPreviewDomain((string) $domain);
                $preview_domains[] = $generated['url'];
                if (filled($generated['port'])) {
                    $previewPortOverrides[$generated['url']] = $generated['port'];
                }
            }

            $docker_compose_domains = putComposeServiceDomain(
                $docker_compose_domains,
                $service_name,
                ! empty($preview_domains) ? implode(',', $preview_domains) : '',
                $serviceNames,
            );
        }

        // Drop any leftover twin keys that were not rewritten above.
        $docker_compose_domains = rekeyComposeDomainsToServiceNames($docker_compose_domains, $serviceNames);

        $this->docker_compose_domains = json_encode($docker_compose_domains);

        // Populate fqdn from generated domains so webhook notifications can read it
        $allDomains = collect($docker_compose_domains)
            ->map(fn ($entry) => composeDomainEntryString($entry))
            ->filter(fn ($d) => ! empty($d))
            ->flatMap(fn ($d) => explode(',', $d))
            ->implode(',');

        $this->fqdn = ! empty($allDomains) ? $allDomains : null;
        $this->domain_port_overrides = $previewPortOverrides ?: null;

        $this->save();
    }

    /**
     * @return array{url: string, port: ?int}
     */
    public function generatedPreviewDomain(string $sourceDomain): array
    {
        $url = Url::fromString($sourceDomain);
        $template = $this->application->preview_url_template;
        $host = $url->getHost();
        $schema = $url->getScheme();
        $urlPath = $url->getPath();
        $path = ($urlPath !== '' && $urlPath !== '/') ? $urlPath : '';
        $random = new_public_id();
        $previewFqdn = str_replace('{{random}}', $random, $template);
        $previewFqdn = str_replace('{{domain}}', $host, $previewFqdn);
        $previewFqdn = str_replace('{{pr_id}}', (string) $this->pull_request_id, $previewFqdn);
        $previewUrl = "{$schema}://{$previewFqdn}{$path}";
        $sourceCanonical = DomainPortOverrides::withoutPort($sourceDomain);
        $port = $url->getPort() ?? ($this->application->domain_port_overrides[$sourceCanonical] ?? null);

        return [
            'url' => $previewUrl,
            'port' => $port !== null ? (int) $port : null,
        ];
    }

    /**
     * Original compose service names for this preview (PR suffix stripped), excluding database images.
     *
     * @return list<string>
     */
    private function composeServiceNamesForPreview(): array
    {
        $parsedServices = $this->application->parse(pull_request_id: $this->pull_request_id);
        $services = data_get($parsedServices, 'services', []);
        if (! is_iterable($services)) {
            return [];
        }

        $names = [];
        foreach ($services as $serviceName => $service) {
            if (isDatabaseImage(data_get($service, 'image'))) {
                continue;
            }

            $names[] = str((string) $serviceName)
                ->replaceLast('-pr-'.$this->pull_request_id, '')
                ->toString();
        }

        return array_values(array_unique($names));
    }
}
