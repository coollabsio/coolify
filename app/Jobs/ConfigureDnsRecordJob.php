<?php

namespace App\Jobs;

use App\Events\DnsRecordConfigurationFinished;
use App\Models\DnsProviderZone;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ConfigureDnsRecordJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $teamId,
        public int $zoneId,
        public ?string $resourceType,
        public int|string|null $resourceId,
        public string $hostname,
        public string $content,
    ) {}

    public function handle(CloudflareDnsProvider $provider): void
    {
        $zone = DnsProviderZone::query()
            ->with('integrationToken')
            ->whereKey($this->zoneId)
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $this->teamId))
            ->firstOrFail();

        try {
            $provider->createRecord($zone, $this->hostname, $this->content, $this->resource());

            DnsRecordConfigurationFinished::dispatch(
                $this->teamId,
                $this->resourceType,
                $this->resourceId,
                $this->hostname,
                true,
                $zone->integrationToken->name,
                "DNS record added for {$this->hostname}.",
            );
        } catch (Throwable $exception) {
            DnsRecordConfigurationFinished::dispatch(
                $this->teamId,
                $this->resourceType,
                $this->resourceId,
                $this->hostname,
                false,
                $zone->integrationToken->name,
                $exception->getMessage(),
            );
        }
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
