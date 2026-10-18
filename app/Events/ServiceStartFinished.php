<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The remote process that starts a service (or one of its parts) finished.
 * RefreshServiceStatusAfterStart stores the new status and broadcasts ServiceStatusChanged.
 */
class ServiceStartFinished
{
    use Dispatchable;

    public function __construct(public int $serviceId) {}
}
