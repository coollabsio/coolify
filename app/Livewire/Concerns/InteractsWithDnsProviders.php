<?php

namespace App\Livewire\Concerns;

use App\Exceptions\DnsRecordConflictException;
use App\Jobs\ConfigureDnsRecordJob;
use App\Models\DnsProviderZone;
use App\Models\ManagedDnsRecord;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithDnsProviders
{
    public bool $showDnsProviderModal = false;

    public array $dnsProviderProposals = [];

    public array $dnsProviderConflicts = [];

    public bool $deleteManagedDns = true;

    public function openDnsProviderModal(): void
    {
        $this->authorizeDnsProviderChange();
        $this->loadDnsProviderProposals();
        if ($this->dnsProviderProposals === []) {
            $this->dispatch('error', 'No connected DNS provider can manage the configured domains.');

            return;
        }
        $this->showDnsProviderModal = true;
    }

    public function closeDnsProviderModal(): void
    {
        $this->showDnsProviderModal = false;
    }

    public function createManagedDnsRecord(string $hostname, int $zoneId, ?string $content = null): void
    {
        $this->authorizeDnsProviderChange();
        $cloudflare = app(CloudflareDnsProvider::class);
        $zone = $this->findTeamZone($zoneId);
        $content ??= $this->serverIp;
        if ($zone === null || blank($content) || filter_var($content, FILTER_VALIDATE_IP) === false) {
            $this->dispatch('error', 'No connected DNS provider or public server IP is available for this domain.');

            return;
        }
        try {
            $cloudflare->createRecord($zone, $hostname, $content, $this->dnsResourceForHostname($hostname));
            $this->markDnsManaged($hostname, $zone->integrationToken->name);
            $this->dispatch('success', "DNS record created for {$hostname}.");
            $this->loadDnsProviderProposals();
        } catch (DnsRecordConflictException $e) {
            $this->dnsProviderConflicts[$hostname.'|'.$zoneId] = [
                'record_id' => $e->providerRecordId, 'current' => $e->currentValue, 'proposed' => $e->proposedValue,
            ];
        } catch (\Throwable $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    /** @param array<int, string> $urls */
    protected function hasDnsProviderForUrls(array $urls): bool
    {
        $provider = app(CloudflareDnsProvider::class);

        return collect($urls)->contains(function (string $url) use ($provider): bool {
            $hostname = parse_url($url, PHP_URL_HOST);

            return is_string($hostname) && $provider->findZones(currentTeam()->id, $hostname)->isNotEmpty();
        });
    }

    /** @param array<int, string> $urls */
    protected function configureDnsAfterDomainAdd(array $urls): bool
    {
        $hostnames = collect($urls)->map(fn (string $url) => parse_url($url, PHP_URL_HOST))
            ->filter(fn ($hostname) => is_string($hostname))->map(fn (string $hostname) => strtolower($hostname))
            ->unique()->values()->all();
        $this->loadDnsProviderProposals($hostnames);
        if ($this->dnsProviderProposals === []) {
            return false;
        }
        if (blank($this->serverIp) || filter_var($this->serverIp, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $this->markDnsPending($hostnames);

        $proposalsByHostname = collect($this->dnsProviderProposals)->groupBy('hostname');
        $canConfigureAutomatically = $proposalsByHostname->every(function ($proposals): bool {
            if ($proposals->count() !== 1) {
                return false;
            }

            $zone = $this->findTeamZone((int) $proposals->first()['zone_id']);

            return $zone?->integrationToken->automaticDnsEnabled() === true;
        });

        if (! $canConfigureAutomatically) {
            $this->showDnsProviderModal = true;

            return true;
        }

        foreach ($this->dnsProviderProposals as $proposal) {
            $zone = $this->findTeamZone((int) $proposal['zone_id']);
            if ($zone === null) {
                continue;
            }

            $resource = $this->dnsResourceForHostname($proposal['hostname']);
            ConfigureDnsRecordJob::dispatch(
                currentTeam()->id,
                $zone->id,
                $resource?->getMorphClass(),
                $resource?->getKey(),
                $proposal['hostname'],
                $this->serverIp,
            );
            $this->dispatch('info', "Adding DNS record for {$proposal['hostname']}.");
        }

        return true;
    }

    public function openManualDnsRecords(): void
    {
        $this->authorizeDnsProviderChange();
        $this->loadDnsProviderProposals();
        $this->dispatch('open-dns-records-modal');
    }

    public function replaceManagedDnsRecord(string $hostname, int $zoneId, string $password = ''): void
    {
        $this->authorizeDnsProviderChange();
        $key = $hostname.'|'.$zoneId;
        $conflict = $this->dnsProviderConflicts[$key] ?? null;
        $zone = $this->findTeamZone($zoneId);
        $content = $this->serverIp;
        if ($conflict === null || $zone === null || blank($content) || filter_var($content, FILTER_VALIDATE_IP) === false) {
            $this->dispatch('error', 'The DNS conflict is no longer available. Check the record again.');

            return;
        }
        try {
            app(CloudflareDnsProvider::class)->replaceRecord(
                $zone,
                (string) ($conflict['record_id'] ?? ''),
                $hostname,
                $content,
                $this->dnsResourceForHostname($hostname),
                (string) ($conflict['current'] ?? ''),
            );
            unset($this->dnsProviderConflicts[$key]);
            $this->dispatch('success', "DNS record replaced for {$hostname}.");
            $this->loadDnsProviderProposals();
        } catch (\Throwable $e) {
            unset($this->dnsProviderConflicts[$key]);
            $this->dispatch('error', $e->getMessage());
        }
    }

    protected function loadDnsProviderProposals(?array $hostnames = null): void
    {
        $provider = app(CloudflareDnsProvider::class);
        $hostnames ??= $this->allDomainHostnames();
        $managed = ManagedDnsRecord::query()->where('team_id', currentTeam()->id)->whereIn('name', $hostnames)->pluck('id', 'name');
        $this->dnsProviderProposals = collect($hostnames)->flatMap(fn (string $hostname) => $provider->findZones(currentTeam()->id, $hostname)
            ->map(fn (DnsProviderZone $zone) => [
                'hostname' => $hostname, 'zone_id' => $zone->id, 'zone' => $zone->name,
                'credential' => $zone->integrationToken->name, 'target' => (string) $this->serverIp,
                'managed' => $managed->has($hostname),
            ])->all())->values()->all();
    }

    protected function markDnsPending(array $hostnames): void
    {
        foreach ($this->domainRows as $index => $row) {
            $hostname = parse_url((string) ($row['url'] ?? ''), PHP_URL_HOST);
            if (is_string($hostname) && in_array(strtolower($hostname), $hostnames, true)) {
                $this->domainRows[$index]['dns_status'] = 'pending';
                $this->domainRows[$index]['dns_message'] = 'A connected DNS provider can create this record.';
                $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
            }
        }
        $this->persistDomainDnsStatuses();
    }

    protected function markDnsManaged(string $hostname, string $credential): void
    {
        foreach ($this->domainRows as $index => $row) {
            $rowHostname = parse_url((string) ($row['url'] ?? ''), PHP_URL_HOST);
            if (is_string($rowHostname) && strtolower($rowHostname) === strtolower($hostname)) {
                $this->domainRows[$index]['dns_status'] = 'ok';
                $this->domainRows[$index]['dns_message'] = "DNS record created through {$credential}.";
                $this->domainRows[$index]['checked_at'] = now()->toIso8601String();
            }
        }
        $this->persistDomainDnsStatuses();
    }

    public function dnsRecordConfigurationFinished(array $event): void
    {
        $resource = $this->dnsResourceForHostname($event['hostname']);
        if ($resource === null || $resource->getMorphClass() !== $event['resourceType']
            || (string) $resource->getKey() !== (string) $event['resourceId']) {
            return;
        }

        if ($event['successful']) {
            $this->markDnsManaged($event['hostname'], $event['credential']);
            $this->dispatch('success', $event['message']);

            return;
        }

        $this->dispatch('error', "DNS record could not be added for {$event['hostname']}: {$event['message']}");
    }

    protected function deleteManagedDnsForUrl(string $url): void
    {
        $hostname = parse_url($url, PHP_URL_HOST);
        if (! is_string($hostname)) {
            return;
        }

        $resource = $this->dnsResourceForHostname($hostname);
        if ($resource === null) {
            return;
        }

        $record = ManagedDnsRecord::query()
            ->where('team_id', currentTeam()->id)
            ->where('name', strtolower($hostname))
            ->where('resource_type', $resource->getMorphClass())
            ->where('resource_id', $resource->getKey())
            ->first();

        if ($record !== null && ! app(CloudflareDnsProvider::class)->deleteRecord($record)) {
            $this->dispatch('warning', 'The domain was removed, but its DNS record changed externally and was left untouched.');
        }
    }

    protected function authorizeDnsProviderChange(): void
    {
        $this->authorize('update', property_exists($this, 'application') ? $this->application : $this->service);
    }

    protected function findTeamZone(int $zoneId): ?DnsProviderZone
    {
        return DnsProviderZone::query()->whereKey($zoneId)
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', currentTeam()->id))->first();
    }

    abstract protected function persistDomainDnsStatuses(): void;

    abstract protected function dnsResourceForHostname(string $hostname): ?Model;
}
