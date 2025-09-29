<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class ApplicationPreview extends BaseModel
{
    use SoftDeletes;

    protected $guarded = [];

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
                    instant_remote_process(["docker volume rm -f $key"], $server, false);
                });
                $networkKeys->each(function ($key) use ($server) {
                    instant_remote_process(["docker network disconnect $key coolify-proxy"], $server, false);
                    instant_remote_process(["docker network rm $key"], $server, false);
                });
            } else {
                // Regular application volume cleanup
                $persistentStorages = $preview->persistentStorages()->get() ?? collect();
                if ($persistentStorages->count() > 0) {
                    foreach ($persistentStorages as $storage) {
                        instant_remote_process(["docker volume rm -f $storage->name"], $server, false);
                    }
                }
            }

            // Clean up persistent storage records
            $preview->persistentStorages()->delete();
        });
        static::saving(function ($preview) {
            if ($preview->isDirty('status')) {
                $preview->forceFill(['last_online_at' => now()]);
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
        return $this->morphMany(\App\Models\LocalPersistentVolume::class, 'resource');
    }

    public function generate_preview_fqdn()
    {
        if ($this->application->fqdn) {
            if (str($this->application->fqdn)->contains(',')) {
                $url = Url::fromString(str($this->application->fqdn)->explode(',')[0]);
                $preview_fqdn = getFqdnWithoutPort(str($this->application->fqdn)->explode(',')[0]);
            } else {
                $url = Url::fromString($this->application->fqdn);
                if ($this->fqdn) {
                    $preview_fqdn = getFqdnWithoutPort($this->fqdn);
                }
            }
            $template = $this->application->preview_url_template;
            $host = $url->getHost();
            $schema = $url->getScheme();
            $random = new Cuid2;
            $preview_fqdn = str_replace('{{random}}', $random, $template);
            $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
            $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
            $preview_fqdn = "$schema://$preview_fqdn";
            $this->fqdn = $preview_fqdn;
            $this->save();
        }

        return $this;
    }

    public function generate_preview_fqdn_compose()
    {
        $services = collect(json_decode($this->application->docker_compose_domains)) ?? collect();
        $docker_compose_domains = data_get($this, 'docker_compose_domains');
        $docker_compose_domains = json_decode($docker_compose_domains, true) ?? [];

        // Get all services from the parsed compose file to ensure all services have entries
        $parsedServices = $this->application->parse(pull_request_id: $this->pull_request_id);
        if (isset($parsedServices['services'])) {
            foreach ($parsedServices['services'] as $serviceName => $service) {
                if (! isDatabaseImage(data_get($service, 'image'))) {
                    // Remove PR suffix from service name to get original service name
                    $originalServiceName = str($serviceName)->replaceLast('-pr-'.$this->pull_request_id, '')->toString();

                    // Ensure all services have an entry, even if empty
                    if (! $services->has($originalServiceName)) {
                        $services->put($originalServiceName, ['domain' => '']);
                    }
                }
            }
        }

        foreach ($services as $service_name => $service_config) {
            $domain_string = data_get($service_config, 'domain');

            // If domain string is empty or null, don't auto-generate domain
            // Only generate domains when main app already has domains set
            if (empty($domain_string)) {
                // Ensure service has an empty domain entry for form binding
                $docker_compose_domains[$service_name]['domain'] = '';

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
                $random = new Cuid2;
                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
                $preview_fqdn = "$schema://$preview_fqdn";
                $preview_domains[] = $preview_fqdn;
            }

            if (! empty($preview_domains)) {
                $docker_compose_domains[$service_name]['domain'] = implode(',', $preview_domains);
            } else {
                // Ensure service has an empty domain entry for form binding
                $docker_compose_domains[$service_name]['domain'] = '';
            }
        }

        $this->docker_compose_domains = json_encode($docker_compose_domains);
        $this->save();
    }
}
