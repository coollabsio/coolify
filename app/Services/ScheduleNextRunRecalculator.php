<?php

namespace App\Services;

use App\Models\ScheduledVolumeBackup;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandalonePostgresql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Recalculates next_run_at of schedules after their server timezone can have changed:
 * a server timezone change, or a resource that moved to another server.
 */
class ScheduleNextRunRecalculator
{
    /**
     * @param  string|null  $timezone  The timezone of the resource's server. Read from the database when null.
     */
    public function forResource(Model $resource, ?string $timezone = null): void
    {
        $timezone ??= $this->serverTimezone($resource);

        foreach (['scheduled_tasks', 'scheduledBackups'] as $relation) {
            if (method_exists($resource, $relation)) {
                $this->recalculate($resource->{$relation}(), $timezone);
            }
        }

        foreach (['persistentStorages', 'fileStorages'] as $relation) {
            if (method_exists($resource, $relation)) {
                $storages = $resource->{$relation}();
                $this->recalculate(ScheduledVolumeBackup::query()
                    ->where('backupable_type', $storages->getRelated()->getMorphClass())
                    ->whereIn('backupable_id', $storages->select('id')), $timezone);
            }
        }

        if ($resource instanceof Service) {
            $resource->applications()->get()->each(fn (Model $application) => $this->forResource($application, $timezone));
            $resource->databases()->get()->each(fn (Model $database) => $this->forResource($database, $timezone));
        }
    }

    /**
     * Each resource uses the timezone of its own main server: Server::applications() also returns
     * applications for which this server is only an additional server.
     */
    public function forServer(Server $server): void
    {
        $server->applications()->each(fn (Model $application) => $this->forResource($application));
        $server->databases()->each(fn (Model $database) => $this->forResource($database));
        $server->services()->get()->each(fn (Service $service) => $this->forResource($service));

        // Server::databases() leaves out the instance database, which has the instance backup.
        if ($server->id === 0 && ($instanceDatabase = StandalonePostgresql::find(0))) {
            $this->forResource($instanceDatabase);
        }
    }

    /**
     * Move enabled schedules to their next due time after now. The current minute is excluded,
     * so an occurrence that already ran in this minute does not run again.
     */
    private function recalculate(Relation|Builder $schedules, ?string $timezone): void
    {
        $schedules->where('enabled', true)->get()->each(fn (Model $schedule) => $schedule->forceFill([
            'next_run_at' => next_cron_run_at((string) $schedule->frequency, $timezone, now()),
        ])->saveQuietly());
    }

    private function serverTimezone(Model $resource): ?string
    {
        $owner = $resource instanceof ServiceApplication || $resource instanceof ServiceDatabase
            ? $resource->service()->first()
            : $resource;
        $server = $owner?->destination()->first()?->server()->first();

        return $server?->settings()->value('server_timezone');
    }
}
