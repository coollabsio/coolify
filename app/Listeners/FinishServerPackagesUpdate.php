<?php

namespace App\Listeners;

use App\Enums\ProcessStatus;
use App\Events\ServerPackagesUpdateFinished;
use App\Jobs\ImageRescanJob;
use App\Jobs\ScanHostPackagesJob;
use App\Jobs\ServerPatchCheckJob;
use App\Models\Server;
use Spatie\Activitylog\Models\Activity;

/**
 * Records the result of a package update in the audit log, then checks the updates and the OS package
 * vulnerabilities of the server again.
 */
class FinishServerPackagesUpdate
{
    public function handle(ServerPackagesUpdateFinished $event): void
    {
        $server = Server::query()->where('uuid', $event->data['server_uuid'] ?? null)->first();
        if ($server === null) {
            return;
        }

        $activity = Activity::query()->where('properties->type_uuid', $event->data['update_id'] ?? null)->first();
        $status = data_get($activity, 'properties.status');
        $packages = $event->data['packages'] ?? [];

        auditLog('ui.server.packages_update_finished', [
            'team_id' => $server->team_id,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'packages' => ($event->data['action'] ?? null) === 'remove_unused' ? 'unused' : ($packages === [] ? 'all' : $packages),
            'package_manager' => $event->data['package_manager'] ?? null,
            'activity_id' => $activity?->id,
            'result' => $status === ProcessStatus::FINISHED->value ? 'success' : 'failed',
            'exit_code' => data_get($activity, 'properties.exitCode'),
            'actor_id' => $event->data['actor_id'] ?? null,
            'actor_name' => $event->data['actor_name'] ?? null,
            'actor_email' => $event->data['actor_email'] ?? null,
        ], $status === ProcessStatus::FINISHED->value ? 'info' : 'error');

        ServerPatchCheckJob::dispatchManual($server);
        if (ImageRescanJob::canRunOn($server)) {
            ScanHostPackagesJob::dispatchManual($server);
        }
    }
}
