<?php

namespace App\Listeners;

use App\Events\ServiceStartFinished;
use App\Events\ServiceStatusChanged;
use App\Models\Service;
use App\Services\ResourceStatusRefresher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs in the task that started the service, so the status is stored without waiting for the
 * regular status check on a shared queue.
 */
class RefreshServiceStatusAfterStart
{
    public function __construct(private ResourceStatusRefresher $refresher) {}

    public function handle(ServiceStartFinished $event): void
    {
        $service = Service::query()->find($event->serviceId);
        if (! $service) {
            return;
        }

        try {
            $this->refresher->refreshService($service);
        } catch (Throwable $e) {
            Log::warning('Could not refresh the status of a started service.', ['service' => $service->uuid, 'error' => $e->getMessage()]);
        }

        ServiceStatusChanged::dispatch($service->server?->team_id);
    }
}
