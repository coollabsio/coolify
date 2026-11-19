<?php

namespace App\Services\Dns;

use App\Enums\ManagedDnsDeletionResult;
use App\Exceptions\DnsRecordConflictException;
use App\Jobs\ConfigureDnsRecordJob;
use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\ManagedDnsRecord;
use App\Models\Service;
use App\Support\DnsRecordHints;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Managed DNS records for the saved domains of an application or a service (the DNS API).
 * A service domain belongs to one of its service applications; that application references the record.
 * Only zones of the resource's team are used, and records only point to the public addresses of the resource's server.
 */
class ResourceDnsRecords
{
    public function __construct(
        private CloudflareDnsProvider $provider,
        private ManagedDnsRecordCleanup $cleanup,
    ) {}

    /**
     * Hostnames of the resource with the applications that use them (the application, or service applications).
     *
     * @return array<string, array<int, Model>>
     */
    public function hostnameUsers(Application|Service $resource): array
    {
        $users = [];
        foreach ($this->domainModels($resource) as $model) {
            foreach ($this->cleanup->hostnamesOf($model) as $hostname) {
                $users[$hostname][] = $model;
            }
        }
        ksort($users);

        return $users;
    }

    public function addresses(Application|Service $resource): ServerDnsAddresses
    {
        return ServerDnsAddresses::forServer($resource instanceof Service ? $resource->server : $resource->destination?->server);
    }

    /**
     * DNS providers and managed records for every hostname of the resource. Reads only the database.
     *
     * @return array{default_address: ?string, public_addresses: array<int, string>, hostnames: array<int, array<string, mixed>>}
     */
    public function status(Application|Service $resource): array
    {
        $teamId = $this->teamId($resource);
        $hostnames = array_keys($this->hostnameUsers($resource));
        $records = ManagedDnsRecord::query()->with('integrationToken')
            ->where('team_id', $teamId)->whereIn('name', $hostnames)->get()->groupBy('name');
        $addresses = $this->addresses($resource);

        return [
            'default_address' => $addresses->recordContent(),
            'public_addresses' => $addresses->publicAddresses(),
            'hostnames' => collect($hostnames)->map(fn (string $hostname): array => [
                'hostname' => $hostname,
                'providers' => $this->provider->findZones($teamId, $hostname)->map(fn (DnsProviderZone $zone): array => [
                    'integration_token_uuid' => $zone->integrationToken->uuid,
                    'integration_token_name' => $zone->integrationToken->name,
                    'zone' => $zone->name,
                    'automatic_dns' => $zone->integrationToken->automaticDnsEnabled(),
                    'proxied_dns' => $zone->integrationToken->proxiedDnsEnabled(),
                ])->values()->all(),
                'records' => $records->get($hostname, collect())->map(fn (ManagedDnsRecord $record): array => $this->recordData($record))->values()->all(),
            ])->all(),
        ];
    }

    /**
     * @throws ValidationException when the hostname, provider, or content is not valid for the resource
     * @throws DnsRecordConflictException when another record already uses the hostname
     * @throws LockTimeoutException when another DNS change for the hostname is in progress
     * @throws RuntimeException when Cloudflare rejects the change
     */
    public function create(Application|Service $resource, string $hostname, ?string $integrationTokenUuid, ?string $content): ManagedDnsRecord
    {
        [$hostname, $users, $zone, $content] = $this->prepare($resource, $hostname, $integrationTokenUuid, $content);

        return $this->provider->createRecord($zone, $hostname, $content, $users[0], array_slice($users, 1));
    }

    /**
     * Points the conflicting record from a create response at the server. The record must still have the ID and
     * the content that the conflict reported.
     *
     * @throws ValidationException|LockTimeoutException|RuntimeException
     */
    public function replace(
        Application|Service $resource,
        string $hostname,
        ?string $integrationTokenUuid,
        string $recordId,
        string $currentContent,
        ?string $content,
    ): ManagedDnsRecord {
        [$hostname, $users, $zone, $content] = $this->prepare($resource, $hostname, $integrationTokenUuid, $content);

        return $this->provider->replaceRecord($zone, $recordId, $hostname, $content, $users[0], $currentContent, array_slice($users, 1));
    }

    /**
     * Releases the resource's references to the managed record of a hostname that the resource no longer uses.
     * The provider record is deleted only when $deleteRecord is set, Coolify created it, and nothing else uses the hostname.
     * Null means the reference was released and the provider record was kept.
     *
     * @throws ValidationException when the resource still uses the hostname
     * @throws ModelNotFoundException when no managed record of the hostname references the resource
     */
    public function release(Application|Service $resource, string $hostname, bool $deleteRecord): ?ManagedDnsDeletionResult
    {
        $hostname = $this->normalizeHostname($hostname);
        if (isset($this->hostnameUsers($resource)[$hostname])) {
            throw ValidationException::withMessages([
                'hostname' => "The resource still uses {$hostname}. Remove the domain first; its DNS record is then released.",
            ]);
        }

        $teamId = $this->teamId($resource);
        $models = collect($this->domainModels($resource))->filter(fn (Model $model): bool => ManagedDnsRecord::query()
            ->where('team_id', $teamId)->where('name', $hostname)
            ->whereHas('references', fn (Builder $query) => $query
                ->where('resource_type', $model->getMorphClass())->where('resource_id', $model->getKey()))
            ->exists());
        if ($models->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(ManagedDnsRecord::class);
        }

        $results = $models->map(fn (Model $model) => $this->cleanup->releaseHostname($model, $hostname, $teamId, $deleteRecord))->filter();

        return $results->first(fn (ManagedDnsDeletionResult $result): bool => $result->shouldRetry())
            ?? $results->first(fn (ManagedDnsDeletionResult $result): bool => $result === ManagedDnsDeletionResult::ChangedExternally)
            ?? $results->first();
    }

    /**
     * Queues a DNS record for each hostname added since $previousHostnames, like the Domains page does, when exactly
     * one zone of the team manages the hostname and its token has automatic DNS on.
     *
     * @param  array<int, string>  $previousHostnames
     * @return array<int, string> the hostnames that were queued
     */
    public function queueAutomaticRecords(Application|Service $resource, array $previousHostnames): array
    {
        $addedUsers = array_diff_key($this->hostnameUsers($resource), array_flip($previousHostnames));
        if ($addedUsers === []) {
            return [];
        }

        $teamId = $this->teamId($resource);
        $automaticZones = collect($addedUsers)
            ->map(fn (array $users, string $hostname) => $this->provider->findZones($teamId, $hostname))
            ->filter(fn ($zones): bool => $zones->count() === 1 && $zones->first()->integrationToken->automaticDnsEnabled())
            ->map(fn ($zones): DnsProviderZone => $zones->first());
        $content = $automaticZones->isEmpty() ? null : $this->addresses($resource)->recordContent();
        if ($content === null) {
            return [];
        }

        foreach ($automaticZones as $hostname => $zone) {
            $user = $addedUsers[$hostname][0];
            ConfigureDnsRecordJob::dispatch($teamId, $zone->id, $user->getMorphClass(), $user->getKey(), $hostname, $content)->afterCommit();
        }

        return $automaticZones->keys()->all();
    }

    /**
     * @return array{uuid: string, hostname: string, type: string, address: string, owned: bool, integration_token_uuid: ?string}
     */
    public function recordData(ManagedDnsRecord $record): array
    {
        return [
            'uuid' => $record->uuid,
            'hostname' => $record->name,
            'type' => $record->type,
            'address' => $record->content,
            'owned' => $record->owned,
            'integration_token_uuid' => $record->integrationToken?->uuid,
        ];
    }

    /**
     * @return array{0: string, 1: array<int, Model>, 2: DnsProviderZone, 3: string}
     *
     * @throws ValidationException
     */
    private function prepare(Application|Service $resource, string $hostname, ?string $integrationTokenUuid, ?string $content): array
    {
        $hostname = $this->normalizeHostname($hostname);
        $users = $this->hostnameUsers($resource)[$hostname] ?? null;
        if ($users === null) {
            throw ValidationException::withMessages(['hostname' => "{$hostname} is not a domain of this resource."]);
        }

        $addresses = $this->addresses($resource);
        if ($addresses->publicAddresses() === []) {
            throw ValidationException::withMessages(['address' => DnsRecordHints::NO_PUBLIC_ADDRESS_MESSAGE]);
        }
        $recordContent = $addresses->recordContent($content);
        if ($recordContent === null) {
            throw ValidationException::withMessages([
                'address' => 'The address must be a public address of the server: '.implode(', ', $addresses->publicAddresses()).'.',
            ]);
        }

        $zones = $this->provider->findZones($this->teamId($resource), $hostname);
        if ($integrationTokenUuid !== null) {
            $zones = $zones->filter(fn (DnsProviderZone $zone): bool => $zone->integrationToken->uuid === $integrationTokenUuid)->values();
        }
        if ($zones->isEmpty()) {
            throw ValidationException::withMessages(['integration_token_uuid' => "No connected DNS provider of the team manages {$hostname}."]);
        }
        if ($zones->count() > 1) {
            throw ValidationException::withMessages([
                'integration_token_uuid' => "Several DNS providers manage {$hostname}. Set integration_token_uuid to one of: "
                    .$zones->map(fn (DnsProviderZone $zone): string => $zone->integrationToken->uuid)->implode(', ').'.',
            ]);
        }

        return [$hostname, $users, $zones->first(), $recordContent];
    }

    /**
     * Models that hold the domains: the application, or the applications of a service.
     *
     * @return array<int, Model>
     */
    private function domainModels(Application|Service $resource): array
    {
        return $resource instanceof Service ? $resource->applications()->get()->all() : [$resource];
    }

    private function teamId(Application|Service $resource): int
    {
        return $resource->team()?->id ?? throw new RuntimeException('The resource has no team.');
    }

    private function normalizeHostname(string $hostname): string
    {
        return strtolower(rtrim(trim($hostname), '.'));
    }
}
