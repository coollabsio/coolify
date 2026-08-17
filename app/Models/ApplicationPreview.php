<?php

namespace App\Models;

use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Url\Url;

class ApplicationPreview extends BaseModel
{
    use SoftDeletes;

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
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
    ];

    protected static function booted()
    {
        static::forceDeleting(function ($preview) {
            $server = $preview->application->destination->server;
            $application = $preview->application;

            if (data_get($preview, 'application.build_pack') === 'dockercompose') {
                // Docker Compose volume and network cleanup
                $composeFile = $application->parse(pull_request_id: $preview->pull_request_id);
                $volumes = data_get($composeFile, 'volumes');
                $networks = data_get($composeFile, 'networks');
                $networkKeys = collect($networks)->keys();
                $volumeKeys = collect($volumes)->keys();
                $volumeKeys->each(function ($key) use ($server) {
                    if (! preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $key)) {
                        return;
                    }
                    instant_remote_process(['docker volume rm -f '.escapeshellarg($key)], $server, false);
                });
                $networkKeys->each(function ($key) use ($server) {
                    if (! preg_match(ValidationPatterns::DOCKER_NETWORK_PATTERN, $key)) {
                        return;
                    }
                    $k = escapeshellarg($key);
                    instant_remote_process(["docker network disconnect {$k} coolify-proxy"], $server, false);
                    instant_remote_process(["docker network rm {$k}"], $server, false);
                });
            } else {
                // Regular application volume cleanup
                $persistentStorages = $preview->persistentStorages()->get() ?? collect();
                if ($persistentStorages->count() > 0) {
                    foreach ($persistentStorages as $storage) {
                        instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
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
        });
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

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function generate_preview_fqdn()
    {
        if ($this->application->fqdn) {
            if (str($this->application->fqdn)->contains(',')) {
                $url = Url::fromString(str($this->application->fqdn)->explode(',')[0]);
            } else {
                $url = Url::fromString($this->application->fqdn);
            }
            $template = $this->application->preview_url_template;
            $host = $url->getHost();
            $schema = $url->getScheme();
            $portInt = $url->getPort();
            $port = $portInt !== null ? ':'.$portInt : '';
            $urlPath = $url->getPath();
            $path = ($urlPath !== '' && $urlPath !== '/') ? $urlPath : '';
            $random = new_public_id();
            $preview_fqdn = str_replace('{{random}}', $random, $template);
            $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
            $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
            $preview_fqdn = "$schema://$preview_fqdn{$port}{$path}";
            $this->fqdn = $preview_fqdn;
            $this->save();
        }

        return $this;
    }

    public function generate_preview_fqdn_compose()
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
        foreach ($serviceNames as $service_name) {
            $domain_string = getComposeServiceDomainString($applicationDomains, $service_name);

            // If domain string is empty or null, don't auto-generate domain
            // Only generate domains when main app already has domains set
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

                $url = Url::fromString($domain);
                $template = $this->application->preview_url_template;
                $host = $url->getHost();
                $schema = $url->getScheme();
                $portInt = $url->getPort();
                $port = $portInt !== null ? ':'.$portInt : '';
                $urlPath = $url->getPath();
                $path = ($urlPath !== '' && $urlPath !== '/') ? $urlPath : '';
                $random = new_public_id();
                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
                $preview_fqdn = "$schema://$preview_fqdn{$port}{$path}";
                $preview_domains[] = $preview_fqdn;
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

        $this->save();
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
