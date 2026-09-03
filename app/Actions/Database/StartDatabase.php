<?php

namespace App\Actions\Database;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Jobs\DatabaseStartJob;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Spatie\Activitylog\Models\Activity;

class StartDatabase
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): Activity|string
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        $database->resetRestartLimit();

        $activity = activity()
            ->withProperties([
                'server_uuid' => $server->uuid,
                'type' => ActivityTypes::INLINE->value,
                'type_uuid' => $database->uuid,
                'status' => ProcessStatus::QUEUED->value,
                'team_id' => $server->team_id,
                'operation' => 'database-start',
            ])
            ->performedOn($database)
            ->event(ActivityTypes::INLINE->value)
            ->log('[]');

        if ($activity === null) {
            return 'Database start could not be queued because activity logging is disabled.';

        }

        DatabaseStartJob::dispatch(
            $database->getMorphClass(),
            (int) $database->getKey(),
            (int) $database->team()->id,
            (int) $activity->getKey(),
            auth()->id(),
        );

        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }
}
