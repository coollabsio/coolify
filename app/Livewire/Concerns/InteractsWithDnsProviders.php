<?php

namespace App\Livewire\Concerns;

use App\Enums\ManagedDnsDeletionResult;
use App\Exceptions\DnsRecordConflictException;
use App\Jobs\ConfigureDnsRecordJob;
use App\Models\DnsProviderZone;
use App\Models\ManagedDnsRecord;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsRecordCleanup;
use App\Support\DnsRecordHints;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use RuntimeException;

trait InteractsWithDnsProviders
{
    public bool $showDnsProviderModal = false;

    #[Locked]
    public array $dnsProviderProposals = [];

    #[Locked]
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
        $hostname = strtolower($hostname);
        $cloudflare = app(CloudflareDnsProvider::class);
        if ($this->publicServerIpsForDnsProvider() === []) {
            $this->dispatch('error', DnsRecordHints::NO_PUBLIC_ADDRESS_MESSAGE);

            return;
        }
        $zone = $this->findTeamZone($zoneId, $hostname);
        $content = $this->dnsRecordContent($content);
        if ($zone === null || $content === null) {
            $this->dispatch('error', 'No connected DNS provider or public server IP is available for this domain.');

            return;
        }
        try {
            $cloudflare->createRecord(
                $zone,
                $hostname,
                $content,
                $this->dnsResourceForHostname($hostname),
                $this->otherDnsResourcesForHostname($hostname),
            );
            $this->markDnsManaged($hostname, $zone->integrationToken->name);
            $this->dispatch('success', "DNS record created for {$hostname}.");
            $this->loadDnsProviderProposals();
        } catch (DnsRecordConflictException $e) {
            $this->dnsProviderConflicts[$hostname.'|'.$zoneId] = [
                'record_id' => $e->providerRecordId, 'current' => $e->currentValue, 'proposed' => $e->proposedValue,
            ];
        } catch (LockTimeoutException) {
            $this->dispatch('error', $this->dnsHostnameBusyMessage($hostname));
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

            return is_string($hostname) && $provider->findZones($this->dnsTeamId(), $hostname)->isNotEmpty();
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
        $content = $this->dnsRecordContent();
        if ($content === null) {
            $this->dispatch('warning', DnsRecordHints::NO_PUBLIC_ADDRESS_MESSAGE);

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
                $this->dnsTeamId(),
                $zone->id,
                $resource?->getMorphClass(),
                $resource?->getKey(),
                $proposal['hostname'],
                $content,
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
        $hostname = strtolower($hostname);
        $key = $hostname.'|'.$zoneId;
        $conflict = $this->dnsProviderConflicts[$key] ?? null;
        if ($this->publicServerIpsForDnsProvider() === []) {
            $this->dispatch('error', DnsRecordHints::NO_PUBLIC_ADDRESS_MESSAGE);

            return;
        }
        $zone = $this->findTeamZone($zoneId, $hostname);
        $content = $this->dnsRecordContent($conflict['proposed'] ?? null);
        if ($conflict === null || $zone === null || $content === null) {
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
                $this->otherDnsResourcesForHostname($hostname),
            );
            unset($this->dnsProviderConflicts[$key]);
            $this->dispatch('success', "DNS record replaced for {$hostname}.");
            $this->loadDnsProviderProposals();
        } catch (LockTimeoutException) {
            $this->dispatch('error', $this->dnsHostnameBusyMessage($hostname));
        } catch (\Throwable $e) {
            unset($this->dnsProviderConflicts[$key]);
            $this->dispatch('error', $e->getMessage());
        }
    }

    protected function loadDnsProviderProposals(?array $hostnames = null): void
    {
        $provider = app(CloudflareDnsProvider::class);
        $hostnames ??= $this->allDomainHostnames();
        $teamId = $this->dnsTeamId();
        $target = $this->dnsRecordContent();
        $managed = ManagedDnsRecord::query()->where('team_id', $teamId)->whereIn('name', $hostnames)->pluck('id', 'name');
        $this->dnsProviderProposals = collect($hostnames)->flatMap(fn (string $hostname) => $provider->findZones($teamId, $hostname)
            ->map(fn (DnsProviderZone $zone) => [
                'hostname' => $hostname, 'zone_id' => $zone->id, 'zone' => $zone->name,
                'credential' => $zone->integrationToken->name, 'target' => $target,
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

    /**
     * The payload comes from the browser; members without update access also receive it and are ignored.
     */
    public function dnsRecordConfigurationFinished(array $event): void
    {
        if (! auth()->user()?->can('update', $this->dnsResource())) {
            return;
        }

        if (! is_string($event['hostname'] ?? null) || ! is_string($event['resourceType'] ?? null)
            || ! is_scalar($event['resourceId'] ?? null) || ! is_bool($event['successful'] ?? null)
            || ! is_string($event['credential'] ?? null) || ! is_string($event['message'] ?? null)
            || ! is_numeric($event['teamId'] ?? null) || (int) $event['teamId'] !== $this->dnsTeamId()) {
            return;
        }

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

    /**
     * Releases the resource's reference to the managed DNS record of a removed URL. The provider record is deleted only when
     * $deleteRecord is set, Coolify created it, and no other resource or URL (in any team) still uses the hostname.
     */
    protected function releaseManagedDnsForUrl(string $url, ?Model $resource = null, bool $deleteRecord = true): void
    {
        $hostname = parse_url($url, PHP_URL_HOST);
        if (! is_string($hostname)) {
            return;
        }

        $resource ??= $this->dnsResourceForHostname($hostname);
        if ($resource === null) {
            return;
        }

        $result = app(ManagedDnsRecordCleanup::class)->releaseHostname($resource, $hostname, $this->dnsTeamId(), $deleteRecord);
        $this->reportManagedDnsRelease($result, 'removed');
    }

    /**
     * Captures the hostnames of a resource before a domain edit, for releaseManagedDnsForEditedDomains().
     *
     * @return array<int, string>
     */
    protected function managedDnsHostnamesOf(Model $resource): array
    {
        return app(ManagedDnsRecordCleanup::class)->hostnamesOf($resource->fresh() ?? $resource);
    }

    /**
     * Releases the hostnames that the resource no longer uses after a domain edit, like a removed domain.
     * An edit has no "also delete the DNS record" choice, so the safe default applies: only records that Coolify
     * created and that no live resource uses any more are deleted; records changed outside Coolify stay.
     *
     * @param  array<int, string>  $previousHostnames
     */
    protected function releaseManagedDnsForEditedDomains(Model $resource, array $previousHostnames): void
    {
        $result = app(ManagedDnsRecordCleanup::class)->releaseRemovedHostnames($resource, $previousHostnames, $this->dnsTeamId());
        $this->reportManagedDnsRelease($result, 'updated');
    }

    private function reportManagedDnsRelease(?ManagedDnsDeletionResult $result, string $change): void
    {
        if ($result === ManagedDnsDeletionResult::ChangedExternally) {
            $this->dispatch('warning', "The domain was {$change}, but its DNS record changed externally and was left untouched.");
        } elseif ($result === ManagedDnsDeletionResult::Failed) {
            $this->dispatch('warning', "The domain was {$change}, but its DNS record could not be deleted because Cloudflare did not respond. It was left untouched.");
        } elseif ($result === ManagedDnsDeletionResult::Busy) {
            $this->dispatch('warning', "The domain was {$change}, but another DNS change for the same hostname was in progress. Its DNS record was left untouched.");
        }
    }

    private function dnsHostnameBusyMessage(string $hostname): string
    {
        return "Another DNS change for {$hostname} is in progress. Try again in a few seconds.";
    }

    /**
     * Service domains can share a hostname across several service applications; each of them references the record.
     *
     * @return array<int, Model>
     */
    protected function otherDnsResourcesForHostname(string $hostname): array
    {
        if (property_exists($this, 'application') || ! property_exists($this, 'service') || $this->service === null) {
            return [];
        }

        $cleanup = app(ManagedDnsRecordCleanup::class);

        return $this->service->applications()->get()
            ->filter(fn (Model $application): bool => in_array(strtolower($hostname), $cleanup->hostnamesOf($application), true))
            ->values()
            ->all();
    }

    protected function authorizeDnsProviderChange(): void
    {
        $this->authorize('update', $this->dnsResource());
    }

    /** The application or service whose domains this component manages. */
    protected function dnsResource(): Model
    {
        return property_exists($this, 'application') ? $this->application : $this->service;
    }

    /**
     * Team of the resource. The session team can differ: a user can switch teams in another tab
     * while this component is still open.
     */
    protected function dnsTeamId(): int
    {
        return $this->dnsResource()->team()?->id ?? throw new RuntimeException('The resource has no team.');
    }

    /**
     * Zone of the resource team. With a hostname, only a zone that manages that hostname.
     */
    protected function findTeamZone(int $zoneId, ?string $hostname = null): ?DnsProviderZone
    {
        if ($hostname !== null) {
            return app(CloudflareDnsProvider::class)->findZones($this->dnsTeamId(), $hostname)->firstWhere('id', $zoneId);
        }

        return DnsProviderZone::query()->whereKey($zoneId)
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $this->dnsTeamId()))->first();
    }

    /**
     * Server addresses that a DNS provider may publish. Private, reserved, CGNAT, and link-local addresses are excluded:
     * a public DNS record must not expose them, and the record would not be reachable anyway.
     *
     * @return array<int, string>
     */
    protected function publicServerIpsForDnsProvider(): array
    {
        return array_values(array_filter(
            $this->serverIpsForDnsHints(),
            fn (?string $address): bool => is_string($address) && DnsRecordHints::isPublicAddress($address),
        ));
    }

    /**
     * Content of a DNS record for this resource: one of its public server addresses (IPv4 or IPv6),
     * the first one when none is requested. Any other address is rejected.
     */
    protected function dnsRecordContent(?string $requested = null): ?string
    {
        $addresses = $this->publicServerIpsForDnsProvider();

        if ($requested === null) {
            return $addresses[0] ?? null;
        }

        return collect($addresses)->first(fn (string $address): bool => DnsRecordHints::sameAddress($address, $requested));
    }

    abstract protected function persistDomainDnsStatuses(): void;

    abstract protected function dnsResourceForHostname(string $hostname): ?Model;
}
