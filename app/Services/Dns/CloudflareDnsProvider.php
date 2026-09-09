<?php

namespace App\Services\Dns;

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

class CloudflareDnsProvider
{
    public function syncZones(IntegrationToken $token): int
    {
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
        $matches = DnsProviderZone::query()
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $teamId)->where('provider', 'cloudflare'))
            ->with('integrationToken')->get()
            ->filter(fn (DnsProviderZone $zone) => $hostname === $zone->name || str_ends_with($hostname, '.'.$zone->name));
        $longest = $matches->max(fn (DnsProviderZone $zone) => strlen($zone->name));

        return $matches->filter(fn (DnsProviderZone $zone) => strlen($zone->name) === $longest)->values();
    }

    public function createRecord(DnsProviderZone $zone, string $hostname, string $content, ?Model $resource = null): ManagedDnsRecord
    {
        $hostname = strtolower(rtrim($hostname, '.'));
        $type = filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
        $token = $zone->integrationToken;
        $existing = $this->client($token)->get("https://api.cloudflare.com/client/v4/zones/{$zone->provider_zone_id}/dns_records", [
            'type' => $type, 'name' => $hostname, 'per_page' => 100,
        ]);
        if (! $existing->successful()) {
            throw new RuntimeException('Cloudflare DNS records could not be checked.');
        }
        $remote = collect($existing->json('result', []))->first();
        if ($remote !== null) {
            if (($remote['content'] ?? null) === $content) {
                throw new RuntimeException('DNS is already configured. Coolify left the existing record unchanged.');
            }
            throw new DnsRecordConflictException((string) ($remote['id'] ?? ''), (string) ($remote['content'] ?? ''), $content);
        }
        $response = $this->client($token)->post("https://api.cloudflare.com/client/v4/zones/{$zone->provider_zone_id}/dns_records", [
            'type' => $type, 'name' => $hostname, 'content' => $content, 'ttl' => 1, 'proxied' => false,
        ]);
        if (! $response->successful() || ! is_string($response->json('result.id'))) {
            throw new RuntimeException('Cloudflare could not create the DNS record.');
        }

        return $this->trackRecord($zone, $response->json('result.id'), $type, $hostname, $content, $resource);
    }

    public function replaceRecord(DnsProviderZone $zone, string $recordId, string $hostname, string $content, ?Model $resource = null): ManagedDnsRecord
    {
        $type = filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
        $response = $this->client($zone->integrationToken)->put(
            "https://api.cloudflare.com/client/v4/zones/{$zone->provider_zone_id}/dns_records/{$recordId}",
            ['type' => $type, 'name' => $hostname, 'content' => $content, 'ttl' => 1, 'proxied' => false],
        );
        if (! $response->successful()) {
            throw new RuntimeException('Cloudflare could not replace the conflicting DNS record.');
        }

        return $this->trackRecord($zone, $recordId, $type, strtolower($hostname), $content, $resource);
    }

    public function deleteRecord(ManagedDnsRecord $record): bool
    {
        $record->loadMissing(['zone', 'integrationToken']);
        $url = "https://api.cloudflare.com/client/v4/zones/{$record->zone->provider_zone_id}/dns_records/{$record->provider_record_id}";
        $response = $this->client($record->integrationToken)->get($url);
        $remote = $response->json('result');
        if (! $response->successful() || ($remote['type'] ?? null) !== $record->type
            || strtolower((string) ($remote['name'] ?? '')) !== $record->name || ($remote['content'] ?? null) !== $record->content) {
            return false;
        }
        if (! $this->client($record->integrationToken)->delete($url)->successful()) {
            return false;
        }
        $record->delete();

        return true;
    }

    private function trackRecord(DnsProviderZone $zone, string $recordId, string $type, string $name, string $content, ?Model $resource): ManagedDnsRecord
    {
        return ManagedDnsRecord::query()->updateOrCreate(
            ['dns_provider_zone_id' => $zone->id, 'provider_record_id' => $recordId],
            ['team_id' => $zone->integrationToken->team_id, 'integration_token_id' => $zone->integration_token_id,
                'resource_type' => $resource?->getMorphClass(), 'resource_id' => $resource?->getKey(),
                'type' => $type, 'name' => $name, 'content' => $content],
        );
    }

    private function client(IntegrationToken $token): PendingRequest
    {
        return Http::withToken($token->token)->acceptJson()->connectTimeout(5)->timeout(10);
    }
}
