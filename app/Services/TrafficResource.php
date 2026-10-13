<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;

/**
 * A team resource that Sentinel records traffic for: an Application or a Service stack.
 * Gives the traffic views one way to read its name, server, analytics link, and the
 * domain behind each of its Sentinel keys (see SentinelTrafficClient::keyBelongsTo()).
 */
final class TrafficResource
{
    public function __construct(public readonly Application|Service $model) {}

    public static function for(Application|Service $model): self
    {
        return new self($model);
    }

    public function uuid(): string
    {
        return (string) $this->model->uuid;
    }

    public function name(): string
    {
        return (string) $this->model->name;
    }

    public function isService(): bool
    {
        return $this->model instanceof Service;
    }

    public function server(): ?Server
    {
        if ($this->model instanceof Service) {
            return $this->model->server ?? $this->model->destination?->server;
        }

        return $this->model->destination?->server;
    }

    public function projectName(): string
    {
        return (string) (data_get($this->model, 'environment.project.name') ?: 'Ungrouped');
    }

    public function analyticsLink(): ?string
    {
        $projectUuid = data_get($this->model, 'environment.project.uuid');
        $environmentUuid = data_get($this->model, 'environment.uuid');
        if (! $projectUuid || ! $environmentUuid) {
            return null;
        }

        if ($this->model instanceof Service) {
            return route('project.service.analytics', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'service_uuid' => $this->model->uuid,
            ]);
        }

        return route('project.application.analytics', [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'application_uuid' => $this->model->uuid,
        ]);
    }

    /**
     * First configured domain host of the resource, or null when it has none.
     */
    public function primaryDomain(): ?string
    {
        if ($this->model instanceof Service) {
            foreach ($this->model->applications as $serviceApplication) {
                if ($host = self::firstHost($serviceApplication->fqdn)) {
                    return $host;
                }
            }

            return null;
        }

        if ($host = self::firstHost($this->model->fqdn)) {
            return $host;
        }

        foreach (self::composeDomains($this->model->docker_compose_domains) as $domain) {
            if ($host = self::firstHost($domain)) {
                return $host;
            }
        }

        return null;
    }

    /**
     * Domain host served under one of this resource's Sentinel keys: the compose service
     * (`{uuid}-{service}`), the preview (`{uuid}-pr-{pr}` or `{uuid}-{pr}`), or the preview compose service
     * (`{uuid}-{pr}-{service}`). Falls back to the primary domain.
     */
    public function domainForKey(string $key): ?string
    {
        if (! SentinelTrafficClient::keyBelongsTo($key, $this->uuid()) || $key === $this->uuid()) {
            return $this->primaryDomain();
        }

        $suffix = substr($key, strlen($this->uuid()) + 1);

        if ($this->model instanceof Service) {
            $serviceApplication = $this->model->applications
                ->first(fn (ServiceApplication $app) => self::matchesServiceName($suffix, (string) $app->name));

            return self::firstHost($serviceApplication?->fqdn) ?? $this->primaryDomain();
        }

        // Normal previews use `{uuid}-pr-{id}`, compose previews `{uuid}-{id}` and `{uuid}-{id}-{service}`.
        if (preg_match('/\A(?:pr-)?(\d+)(?:-(.+))?\z/', $suffix, $matches)) {
            /** @var ApplicationPreview|null $preview */
            $preview = $this->model->previews()->where('pull_request_id', (int) $matches[1])->first();
            if ($preview) {
                $host = isset($matches[2])
                    ? self::composeDomainFor($preview->docker_compose_domains, $matches[2])
                    : self::firstHost($preview->fqdn);
                if ($host) {
                    return $host;
                }
            }
        }

        return self::composeDomainFor($this->model->docker_compose_domains, $suffix) ?? $this->primaryDomain();
    }

    /**
     * Key suffixes use the label-safe compose service name; accept the raw name too.
     */
    private static function matchesServiceName(string $suffix, string $serviceName): bool
    {
        return $serviceName !== ''
            && ($suffix === $serviceName || $suffix === traefikSafeServiceNameSegment($serviceName));
    }

    private static function composeDomainFor(?string $composeDomains, string $suffix): ?string
    {
        foreach (self::composeDomains($composeDomains) as $serviceName => $domain) {
            if (self::matchesServiceName($suffix, (string) $serviceName)) {
                return self::firstHost($domain);
            }
        }

        return null;
    }

    /**
     * @return array<string, string> compose service name => comma-separated domains
     */
    private static function composeDomains(?string $json): array
    {
        $decoded = json_decode($json ?: '[]', true);
        if (! is_array($decoded)) {
            return [];
        }

        $domains = [];
        foreach ($decoded as $serviceName => $config) {
            $domain = data_get($config, 'domain');
            if (is_string($domain) && trim($domain) !== '') {
                $domains[(string) $serviceName] = $domain;
            }
        }

        return $domains;
    }

    private static function firstHost(?string $domains): ?string
    {
        $first = trim((string) str($domains ?? '')->explode(',')->first());

        return $first !== '' ? (parse_url($first, PHP_URL_HOST) ?: null) : null;
    }
}
