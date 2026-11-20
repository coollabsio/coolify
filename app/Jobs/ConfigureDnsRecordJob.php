<?php

namespace App\Jobs;

use App\Events\DnsRecordConfigurationFinished;
use App\Exceptions\DnsRecordConflictException;
use App\Models\DnsProviderZone;
use App\Models\ManagedDnsRecord;
use App\Models\ManagedDnsRecordReference;
use App\Services\Dns\CloudflareDnsProvider;
use App\Services\Dns\ManagedDnsRecordCleanup;
use App\Support\DnsRecordHints;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ConfigureDnsRecordJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Only a busy hostname lock is retried; any other exception fails the job at once. */
    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $timeout = 30;

    public function __construct(
        public int $teamId,
        public int $zoneId,
        public ?string $resourceType,
        public int|string|null $resourceId,
        public string $hostname,
        public string $content,
    ) {}

    /**
     * A record with another value blocks the create. Coolify replaces it only when it is a record that Coolify created
     * and that no other resource uses. Otherwise the conflict goes to the UI, where the user can replace it.
     */
    public function handle(CloudflareDnsProvider $provider): void
    {
        $zone = DnsProviderZone::query()
            ->with('integrationToken')
            ->whereKey($this->zoneId)
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $this->teamId))
            ->firstOrFail();

        if (! DnsRecordHints::isPublicAddress($this->content)) {
            $this->finished($zone, false, DnsRecordHints::NO_PUBLIC_ADDRESS_MESSAGE);

            return;
        }

        $resource = $this->resource();
        try {
            try {
                $provider->createRecord($zone, $this->hostname, $this->content, $resource);
                $message = "DNS record added for {$this->hostname}.";
            } catch (DnsRecordConflictException $conflict) {
                if (! $this->isUnusedCoolifyRecord($zone, $conflict, $resource)) {
                    $this->finished($zone, false, $conflict->getMessage(), [
                        'zone_id' => $zone->id, 'record_id' => $conflict->providerRecordId,
                        'current' => $conflict->currentValue, 'proposed' => $conflict->proposedValue,
                    ]);

                    return;
                }

                $provider->replaceRecord($zone, $conflict->providerRecordId, $this->hostname, $this->content, $resource, $conflict->currentValue);
                $message = "DNS record replaced for {$this->hostname}. It pointed to {$conflict->currentValue}, which no other resource uses.";
            }
        } catch (LockTimeoutException) {
            if ($this->attempts() < $this->tries) {
                $this->release(10 * $this->attempts());

                return;
            }

            $this->finished($zone, false, 'Another DNS change for this hostname is still in progress. Try again later.');

            return;
        } catch (Throwable $exception) {
            $this->finished($zone, false, $exception->getMessage());

            return;
        }

        $this->finished($zone, true, $message);
    }

    /**
     * True when Coolify created the record (owned, and the provider comment still proves it) and no resource other
     * than this one references the record or uses the hostname.
     */
    private function isUnusedCoolifyRecord(DnsProviderZone $zone, DnsRecordConflictException $conflict, ?Model $resource): bool
    {
        if ($resource === null) {
            return false;
        }

        $record = ManagedDnsRecord::query()->with('references.resource')
            ->where('dns_provider_zone_id', $zone->id)
            ->where('provider_record_id', $conflict->providerRecordId)
            ->first();
        if ($record === null || ! $record->owned || $conflict->currentComment !== $record->ownershipComment()) {
            return false;
        }

        $resourceKey = $resource->getMorphClass().'|'.$resource->getKey();
        $usedByOtherResource = $record->references->contains(fn (ManagedDnsRecordReference $reference): bool => $reference->resource !== null
            && $reference->resource_type.'|'.$reference->resource_id !== $resourceKey);

        return ! $usedByOtherResource && app(ManagedDnsRecordCleanup::class)->resourcesUsingHostname($this->hostname, [$resourceKey])->isEmpty();
    }

    /** @param  array{zone_id: int, record_id: string, current: string, proposed: string}|null  $conflict */
    private function finished(DnsProviderZone $zone, bool $successful, string $message, ?array $conflict = null): void
    {
        DnsRecordConfigurationFinished::dispatch(
            $this->teamId,
            $this->resourceType,
            $this->resourceId,
            $this->hostname,
            $successful,
            $zone->integrationToken->name,
            $message,
            $conflict,
        );
    }

    public function failed(?Throwable $exception): void
    {
        DnsRecordConfigurationFinished::dispatch(
            $this->teamId,
            $this->resourceType,
            $this->resourceId,
            $this->hostname,
            false,
            '',
            'The DNS zone is no longer available.',
        );
    }

    private function resource(): ?Model
    {
        $resourceClass = $this->resourceType === null ? null : (Relation::getMorphedModel($this->resourceType) ?? $this->resourceType);
        if ($resourceClass === null || $this->resourceId === null || ! is_subclass_of($resourceClass, Model::class)) {
            return null;
        }

        return $resourceClass::query()->find($this->resourceId);
    }
}
