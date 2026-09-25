<?php

namespace App\Services\Dns;

use App\Enums\ManagedDnsDeletionResult;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\ManagedDnsRecord;
use App\Models\ManagedDnsRecordReference;
use App\Models\ServiceApplication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases resource references to managed DNS records and deletes a provider record only when
 * Coolify owns it, no resource references it and no application, preview or service application
 * (in any team) still uses its hostname.
 */
class ManagedDnsRecordCleanup
{
    public function __construct(private CloudflareDnsProvider $provider) {}

    /**
     * Releases the resource's reference after one of its URLs was removed.
     * Nothing is released while the resource still uses the hostname through another URL.
     */
    public function releaseHostname(Model $resource, string $hostname, int $teamId, bool $deleteRecord = true): ?ManagedDnsDeletionResult
    {
        $hostname = $this->normalizeHostname($hostname);
        $current = $resource->fresh() ?? $resource;
        if (in_array($hostname, $this->hostnamesOf($current), true)) {
            return null;
        }

        $keys = [[$resource->getMorphClass(), $resource->getKey()]];
        $records = ManagedDnsRecord::query()
            ->where('team_id', $teamId)
            ->where('name', $hostname)
            ->whereHas('references', fn (Builder $query) => $this->whereResourceKeys($query, $keys))
            ->get();

        $worst = null;
        foreach ($records as $record) {
            $result = $this->release($record, $keys, $deleteRecord, $resource);
            if ($result === ManagedDnsDeletionResult::ChangedExternally || $result === ManagedDnsDeletionResult::Failed) {
                $worst = $worst === ManagedDnsDeletionResult::Failed ? $worst : $result;
            }
        }

        return $worst;
    }

    /**
     * Releases every reference of a deleted resource (and, for applications, of their previews).
     * Never throws; returns false when a provider call failed and the release should be retried.
     */
    public function releaseResource(string $resourceType, int|string $resourceId): bool
    {
        $keys = $this->resourceKeys($resourceType, $resourceId);
        $records = ManagedDnsRecord::query()
            ->whereHas('references', fn (Builder $query) => $this->whereResourceKeys($query, $keys))
            ->get();

        $successful = true;
        foreach ($records as $record) {
            try {
                if ($this->release($record, $keys, true) === ManagedDnsDeletionResult::Failed) {
                    $successful = false;
                }
            } catch (Throwable $e) {
                $successful = false;
                Log::warning('Managed DNS record cleanup failed after resource deletion.', [
                    'managed_dns_record_id' => $record->id,
                    'hostname' => $record->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $successful;
    }

    /**
     * @param  array<int, array{0: string, 1: int|string}>  $releasingKeys
     */
    public function release(ManagedDnsRecord $record, array $releasingKeys, bool $deleteRecord, ?Model $contextResource = null): ?ManagedDnsDeletionResult
    {
        $releasing = collect($releasingKeys)->map(fn (array $key): string => $key[0].'|'.$key[1]);
        $otherReferences = $record->references()->get()
            ->reject(fn (ManagedDnsRecordReference $reference): bool => $releasing->contains($reference->resource_type.'|'.$reference->resource_id));

        $liveReferences = $otherReferences->filter(function (ManagedDnsRecordReference $reference): bool {
            if ($reference->resource !== null) {
                return true;
            }
            $reference->delete();

            return false;
        });
        if ($liveReferences->isNotEmpty()) {
            $this->deleteReferences($record, $releasingKeys);

            return null;
        }

        $users = $this->resourcesUsingHostname($record->name, $releasing->all());
        if ($users->isNotEmpty()) {
            $sameTeamUsers = $users->filter(fn (Model $user): bool => $this->teamIdOf($user) === (int) $record->team_id);
            if ($sameTeamUsers->isEmpty()) {
                $this->forget($record, 'hostname_used_by_another_team');

                return null;
            }
            $sameTeamUsers->each(fn (Model $user) => $record->addReference($user));
            $this->deleteReferences($record, $releasingKeys);

            return null;
        }

        if (! $record->owned || ! $deleteRecord) {
            $record->delete();

            return $record->owned ? null : ManagedDnsDeletionResult::NotOwned;
        }

        $result = $this->provider->deleteRecord($record, $contextResource);
        if ($result === ManagedDnsDeletionResult::ChangedExternally) {
            $this->forget($record, 'remote_record_changed');
        } elseif ($result === ManagedDnsDeletionResult::Failed) {
            $this->auditSkipped($record, 'provider_unavailable');
        }

        return $result;
    }

    /**
     * Live applications, previews and service applications (any team) that use the hostname, except the given resource keys.
     *
     * @param  array<int, string>  $exceptKeys  "morph-class|id" keys
     * @return Collection<int, Model>
     */
    public function resourcesUsingHostname(string $hostname, array $exceptKeys = []): Collection
    {
        $hostname = $this->normalizeHostname($hostname);
        $pattern = '%'.$hostname.'%';
        $matchesFqdnOrCompose = fn (Builder $query) => $query->where(fn (Builder $query) => $query
            ->whereRaw('LOWER(fqdn) LIKE ?', [$pattern])
            ->orWhereRaw('LOWER(docker_compose_domains) LIKE ?', [$pattern]));

        return collect()
            ->concat(Application::query()->where($matchesFqdnOrCompose)->get())
            ->concat(ApplicationPreview::query()->where($matchesFqdnOrCompose)->get())
            ->concat(ServiceApplication::query()->whereRaw('LOWER(fqdn) LIKE ?', [$pattern])->get())
            ->reject(fn (Model $resource): bool => in_array($resource->getMorphClass().'|'.$resource->getKey(), $exceptKeys, true))
            ->filter(fn (Model $resource): bool => in_array($hostname, $this->hostnamesOf($resource), true))
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function hostnamesOf(Model $resource): array
    {
        $attributes = $resource->getAttributes();
        $values = $this->splitUrls($attributes['fqdn'] ?? null);

        $composeDomains = json_decode((string) ($attributes['docker_compose_domains'] ?? ''), true);
        if (is_array($composeDomains)) {
            foreach ($composeDomains as $service) {
                $domain = is_array($service) ? ($service['domain'] ?? null) : null;
                array_push($values, ...$this->splitUrls(is_string($domain) ? $domain : null));
            }
        }

        return collect($values)
            ->map(fn (string $url) => parse_url(str_contains($url, '://') ? $url : 'http://'.$url, PHP_URL_HOST))
            ->filter(fn ($host): bool => is_string($host) && $host !== '')
            ->map(fn (string $host): string => $this->normalizeHostname($host))
            ->unique()
            ->values()
            ->all();
    }

    private function teamIdOf(Model $resource): ?int
    {
        $teamId = match (true) {
            $resource instanceof ApplicationPreview => data_get($resource, 'application.environment.project.team_id'),
            $resource instanceof ServiceApplication => data_get($resource, 'service.environment.project.team_id'),
            default => data_get($resource, 'environment.project.team_id'),
        };

        return $teamId === null ? null : (int) $teamId;
    }

    /**
     * @return array<int, array{0: string, 1: int|string}>
     */
    private function resourceKeys(string $resourceType, int|string $resourceId): array
    {
        $keys = [[$resourceType, $resourceId]];
        if ($resourceType === (new Application)->getMorphClass()) {
            $previewType = (new ApplicationPreview)->getMorphClass();
            ApplicationPreview::withTrashed()->where('application_id', $resourceId)->pluck('id')
                ->each(function ($previewId) use (&$keys, $previewType): void {
                    $keys[] = [$previewType, $previewId];
                });
        }

        return $keys;
    }

    /**
     * @param  array<int, array{0: string, 1: int|string}>  $keys
     */
    private function whereResourceKeys(Builder $query, array $keys): Builder
    {
        return $query->where(function (Builder $query) use ($keys): void {
            foreach ($keys as [$type, $id]) {
                $query->orWhere(fn (Builder $query) => $query->where('resource_type', $type)->where('resource_id', $id));
            }
        });
    }

    /**
     * @param  array<int, array{0: string, 1: int|string}>  $keys
     */
    private function deleteReferences(ManagedDnsRecord $record, array $keys): void
    {
        $this->whereResourceKeys($record->references()->getQuery(), $keys)->delete();
    }

    /**
     * Stops tracking the record without touching the provider.
     */
    private function forget(ManagedDnsRecord $record, string $reason): void
    {
        $record->delete();
        $this->auditSkipped($record, $reason);
    }

    private function auditSkipped(ManagedDnsRecord $record, string $reason): void
    {
        $source = auth()->check() ? 'ui' : 'system';
        auditLog("{$source}.dns_record.delete_skipped", [
            'team_id' => $record->team_id,
            'hostname' => $record->name,
            'provider' => 'cloudflare',
            'reason' => $reason,
        ], 'warning');
    }

    /**
     * @return array<int, string>
     */
    private function splitUrls(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return collect(explode(',', $value))->map(fn ($url) => trim((string) $url))->filter()->values()->all();
    }

    private function normalizeHostname(string $hostname): string
    {
        return strtolower(rtrim($hostname, '.'));
    }
}
