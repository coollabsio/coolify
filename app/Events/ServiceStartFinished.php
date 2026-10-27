<?php

namespace App\Events;

use App\Models\Service;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The remote process that starts a service (or one of its parts) finished.
 * RefreshServiceStatusAfterStart stores the new status and broadcasts ServiceStatusChanged.
 */
class ServiceStartFinished
{
    use Dispatchable;

    /**
     * @param  array{service_id: int, team_id: int}  $data  Build it with dataFor(); the listener ignores a service of another team.
     */
    public function __construct(public array $data) {}

    /**
     * @return array{service_id: int, team_id: int}
     */
    public static function dataFor(Service $service): array
    {
        return ['service_id' => $service->id, 'team_id' => $service->team()?->id];
    }
}
