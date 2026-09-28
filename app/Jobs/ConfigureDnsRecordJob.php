<?php

namespace App\Jobs;

use App\Events\DnsRecordConfigurationFinished;
use App\Models\DnsProviderZone;
use App\Services\Dns\CloudflareDnsProvider;
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

    public function handle(CloudflareDnsProvider $provider): void
    {
        $zone = DnsProviderZone::query()
            ->with('integrationToken')
            ->whereKey($this->zoneId)
            ->whereHas('integrationToken', fn ($query) => $query->where('team_id', $this->teamId))
            ->firstOrFail();

        try {
            $provider->createRecord($zone, $this->hostname, $this->content, $this->resource());
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

        $this->finished($zone, true, "DNS record added for {$this->hostname}.");
    }

    private function finished(DnsProviderZone $zone, bool $successful, string $message): void
    {
        DnsRecordConfigurationFinished::dispatch(
            $this->teamId,
            $this->resourceType,
            $this->resourceId,
            $this->hostname,
            $successful,
            $zone->integrationToken->name,
            $message,
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
