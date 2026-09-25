<?php

namespace App\Services\Dns;

use App\Enums\ManagedDnsDeletionResult;
use App\Exceptions\DnsRecordConflictException;
use App\Models\DnsProviderZone;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class CloudflareDnsProvider
{
    /** @var array<int, Collection<int, DnsProviderZone>> */
    private array $zoneCache = [];

    public function syncZones(IntegrationToken $token): int
    {
        unset($this->zoneCache[$token->team_id]);
        $zones = [];
        $page = 1;
        do {
            $response = $this->client($token)->get('https://api.cloudflare.com/client/v4/zones', ['page' => $page, 'per_page' => 50]);
            if (! $response->successful() || $response->json('success') !== true) {
                throw new RuntimeException('Cloudflare zones could not be synchronized.');
            }
            array_push($zones, ...$response->json('result', []));
            $totalPages = max(1, (int) $response->json('result_info.total_pages', 1));
            $page++;
        } while ($page <= $totalPages);

        DB::transaction(function () use ($token, $zones): void {
            $ids = [];
            foreach ($zones as $zone) {
                $ids[] = $zone['id'];
                $token->dnsZones()->updateOrCreate(['provider_zone_id' => $zone['id']], [
                    'name' => strtolower($zone['name']), 'account_id' => data_get($zone, 'account.id'),
                    'account_name' => data_get($zone, 'account.name'),
                ]);
            }
            $token->dnsZones()->whereNotIn('provider_zone_id', $ids)->whereDoesntHave('managedRecords')->delete();
            $metadata = $token->metadata ?? [];
            $metadata['zones_synced_at'] = now()->toIso8601String();
            $token->update(['metadata' => $metadata]);
        });

        return count($zones);
    }

    /** @return Collection<int, DnsProviderZone> */
    public function findZones(int $teamId, string $hostname): Collection
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $matches = $this->zonesForTeam($teamId)->filter(
            fn (DnsProviderZone $zone) => $hostname === $zone->name || str_ends_with($hostname, '.'.$zone->name)
        );
        $longest = $matches->max(fn (DnsProviderZone $zone) => strlen($zone->name));

        return $matches->filter(fn (DnsProviderZone $zone) => strlen($zone->name) === $longest)->values();
    }

    /** @return Collection<int, DnsProviderZone> */
    private function zonesForTeam(int $teamId): Collection
    {
        return $this->zoneCache[$teamId] ??= DnsProviderZone::query()
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $teamId)->where('provider', 'cloudflare'))
            ->with('integrationToken')
            ->get();
    }

    /**
     * All records of the given type for a hostname (several records form a round-robin set).
     *
     * @return array<int, array{id: string, type: string, name: string, content: string, proxied: bool|null, ttl: int|null, comment: string|null}>
     */
    public function findRecords(DnsProviderZone $zone, string $hostname, string $type): array
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $response = $this->client($zone->integrationToken)->get(
            "https://api.cloudflare.com/client/v4/zones/{$zone->provider_zone_id}/dns_records",
            ['type' => $type, 'name' => $hostname, 'per_page' => 100],
        );
        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare DNS records could not be checked.');
        }

        return collect($response->json('result', []))
            ->filter(fn ($remote): bool => is_array($remote))
            ->map(fn (array $remote): array => $this->normalizeRemoteRecord($remote, $hostname, $type))
            ->values()
            ->all();
    }

    /**
     * Creates the record with Coolify's ownership comment, or references an existing record that already has the wanted content.
     * Existing records that Coolify did not create are only referenced and never marked as owned.
     */
    public function createRecord(DnsProviderZone $zone, string $hostname, string $content, ?Model $resource = null): ManagedDnsRecord
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $type = $this->recordType($content);
        $remoteRecords = $this->findRecords($zone, $hostname, $type);
        $matching = collect($remoteRecords)->first(fn (array $remote): bool => $remote['content'] === $content);
        if ($matching !== null) {
            if ($matching['id'] === '') {
                throw new RuntimeException('Cloudflare DNS records could not be checked.');
            }

            $record = $this->trackExistingRecord($zone, $matching, $type, $hostname, $content, $resource);
            $this->auditDnsRecord('adopted', $zone, $hostname, $resource);

            return $record;
        }
        if (count($remoteRecords) > 1) {
            throw new RuntimeException("Several DNS records already exist for {$hostname}. Update them in Cloudflare.");
        }
        if (count($remoteRecords) === 1) {
            throw new DnsRecordConflictException($remoteRecords[0]['id'], $remoteRecords[0]['content'], $content);
        }

        $uuid = new_public_id();
        $response = $this->client($zone->integrationToken)->post("https://api.cloudflare.com/client/v4/zones/{$zone->provider_zone_id}/dns_records", [
            'type' => $type, 'name' => $hostname, 'content' => $content, 'ttl' => 1, 'proxied' => false,
            'comment' => ManagedDnsRecord::ownershipCommentFor($uuid),
        ]);
        if (! $response->successful() || ! is_string($response->json('result.id'))) {
            throw new RuntimeException('Cloudflare could not create the DNS record.');
        }

        $record = ManagedDnsRecord::query()->create([
            'uuid' => $uuid, 'team_id' => $zone->integrationToken->team_id, 'integration_token_id' => $zone->integration_token_id,
            'dns_provider_zone_id' => $zone->id, 'provider_record_id' => $response->json('result.id'),
            'type' => $type, 'name' => $hostname, 'content' => $content, 'owned' => true,
        ]);
        if ($resource !== null) {
            $record->addReference($resource);
        }
        $this->auditDnsRecord('created', $zone, $hostname, $resource);

        return $record;
    }

    /**
     * Points an existing (conflicting) record at the new content after explicit user confirmation.
     * Only the content changes: proxy status, TTL, comment and other settings stay as they are.
     */
    public function replaceRecord(
        DnsProviderZone $zone,
        string $recordId,
        string $hostname,
        string $content,
        ?Model $resource = null,
        ?string $expectedCurrent = null,
    ): ManagedDnsRecord {
        $hostname = strtolower(rtrim($hostname, '.'));
        $type = $this->recordType($content);
        $remoteRecords = $this->findRecords($zone, $hostname, $type);
        if (count($remoteRecords) > 1) {
            throw new RuntimeException("Several DNS records already exist for {$hostname}. Update them in Cloudflare.");
        }
        $remote = $remoteRecords[0] ?? null;
        if ($remote === null
            || $remote['id'] === ''
            || $remote['id'] !== $recordId
            || ($expectedCurrent !== null && $remote['content'] !== $expectedCurrent)
            || $remote['name'] !== $hostname) {
            throw new RuntimeException('The DNS conflict is no longer available. Check the record again.');
        }

        $response = $this->client($zone->integrationToken)->patch(
            "https://api.cloudflare.com/client/v4/zones/{$zone->provider_zone_id}/dns_records/{$remote['id']}",
            ['content' => $content],
        );
        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare could not replace the conflicting DNS record.');
        }

        $record = $this->trackExistingRecord($zone, $remote, $type, $hostname, $content, $resource);
        $this->auditDnsRecord('replaced', $zone, $hostname, $resource);

        return $record;
    }

    /**
     * Deletes the provider record only when Coolify created it and it still carries Coolify's ownership comment and value.
     * Removes the local row when the provider record is gone.
     */
    public function deleteRecord(ManagedDnsRecord $record, ?Model $resource = null): ManagedDnsDeletionResult
    {
        if (! $record->owned) {
            return ManagedDnsDeletionResult::NotOwned;
        }

        $record->loadMissing(['zone', 'integrationToken']);
        $url = "https://api.cloudflare.com/client/v4/zones/{$record->zone->provider_zone_id}/dns_records/{$record->provider_record_id}";

        try {
            $response = $this->client($record->integrationToken)->get($url);
            if ($response->status() === 404) {
                return $this->forgetDeletedRecord($record, ManagedDnsDeletionResult::AlreadyGone, $resource);
            }
            $remote = $response->json('result');
            if (! $response->successful() || ! is_array($remote)) {
                return ManagedDnsDeletionResult::Failed;
            }
            if (($remote['type'] ?? null) !== $record->type
                || strtolower((string) ($remote['name'] ?? '')) !== $record->name
                || ($remote['content'] ?? null) !== $record->content
                || ($remote['comment'] ?? null) !== $record->ownershipComment()) {
                return ManagedDnsDeletionResult::ChangedExternally;
            }

            $deleteResponse = $this->client($record->integrationToken)->delete($url);
            if ($deleteResponse->status() === 404) {
                return $this->forgetDeletedRecord($record, ManagedDnsDeletionResult::AlreadyGone, $resource);
            }
            if (! $deleteResponse->successful()) {
                return ManagedDnsDeletionResult::Failed;
            }
        } catch (Throwable) {
            return ManagedDnsDeletionResult::Failed;
        }

        return $this->forgetDeletedRecord($record, ManagedDnsDeletionResult::Deleted, $resource);
    }

    private function forgetDeletedRecord(ManagedDnsRecord $record, ManagedDnsDeletionResult $result, ?Model $resource): ManagedDnsDeletionResult
    {
        $record->delete();
        if ($result === ManagedDnsDeletionResult::Deleted) {
            $this->auditDnsRecord('deleted', $record->zone, $record->name, $resource);
        }

        return $result;
    }

    /**
     * Tracks a record that already exists in the provider. New rows are never owned; an owned row whose
     * provider comment no longer matches loses its ownership.
     *
     * @param  array{id: string, comment: string|null}  $remote
     */
    private function trackExistingRecord(DnsProviderZone $zone, array $remote, string $type, string $name, string $content, ?Model $resource): ManagedDnsRecord
    {
        $record = ManagedDnsRecord::query()->firstOrNew(['dns_provider_zone_id' => $zone->id, 'provider_record_id' => $remote['id']]);
        $record->fill([
            'team_id' => $zone->integrationToken->team_id, 'integration_token_id' => $zone->integration_token_id,
            'type' => $type, 'name' => $name, 'content' => $content,
        ]);
        if (! $record->exists || $remote['comment'] !== $record->ownershipComment()) {
            $record->owned = false;
        }
        $record->save();
        if ($resource !== null) {
            $record->addReference($resource);
        }

        return $record;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array{id: string, type: string, name: string, content: string, proxied: bool|null, ttl: int|null, comment: string|null}
     */
    private function normalizeRemoteRecord(array $remote, string $hostname, string $type): array
    {
        return [
            'id' => (string) ($remote['id'] ?? ''),
            'type' => (string) ($remote['type'] ?? $type),
            'name' => strtolower((string) ($remote['name'] ?? $hostname)),
            'content' => (string) ($remote['content'] ?? ''),
            'proxied' => isset($remote['proxied']) ? (bool) $remote['proxied'] : null,
            'ttl' => isset($remote['ttl']) ? (int) $remote['ttl'] : null,
            'comment' => isset($remote['comment']) && is_string($remote['comment']) ? $remote['comment'] : null,
        ];
    }

    private function recordType(string $content): string
    {
        return filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
    }

    private function auditDnsRecord(string $action, DnsProviderZone $zone, string $hostname, ?Model $resource): void
    {
        $resourceType = $resource ? str(class_basename($resource))->snake()->value() : 'dns_record';

        $source = auth()->check() ? 'ui' : 'system';
        auditLog("{$source}.dns_record.{$action}", [
            'team_id' => $zone->integrationToken->team_id,
            'resource' => $resourceType,
            "{$resourceType}_uuid" => $resource?->getAttribute('uuid'),
            "{$resourceType}_name" => $resource?->getAttribute('name'),
            'hostname' => $hostname,
            'provider' => 'cloudflare',
            'zone' => $zone->name,
        ]);
    }

    private function client(IntegrationToken $token): PendingRequest
    {
        return Http::withToken($token->token)->acceptJson()->connectTimeout(5)->timeout(10);
    }
}
