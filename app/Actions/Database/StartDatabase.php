<?php

namespace App\Actions\Database;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Exceptions\DatabaseStartException;
use App\Jobs\DatabaseStartJob;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Support\ResourceStartActivity;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class StartDatabase
{
    use AsAction;

    public function configureJob(JobDecorator $job): void
    {
        $job->onQueue(deployment_queue());
    }

    public function handle(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite $database): Activity|string
    {
        $server = $database->destination->server;
        if (! $server->isFunctional()) {
            return 'Server is not functional';
        }
        $prerequisiteError = self::prerequisiteError($database);
        if ($prerequisiteError !== null) {
            return $prerequisiteError;
        }
        $database->update([
            'restart_count' => 0,
            'last_restart_at' => null,
            'last_restart_type' => null,
        ]);

        $activity = activity()
            ->withProperties([
                'server_uuid' => $server->uuid,
                'type' => ActivityTypes::INLINE->value,
                'type_uuid' => $database->uuid,
                'status' => ProcessStatus::QUEUED->value,
                'team_id' => $server->team_id,
                'operation' => ResourceStartActivity::DATABASE_START_OPERATION,
            ])
            ->performedOn($database)
            ->event(ActivityTypes::INLINE->value)
            ->log('[]');

        if ($activity === null) {
            return 'Database start could not be queued because activity logging is disabled.';
        }

        try {
            DatabaseStartJob::dispatch(
                $database->getMorphClass(),
                (int) $database->getKey(),
                (int) $database->team()->id,
                (int) $activity->getKey(),
                auth()->id(),
            );
        } catch (Throwable $e) {
            ResourceStartActivity::markFailed($activity, 'Database start could not be queued.');

            throw $e;
        }

        if ($database->is_public && $database->public_port) {
            StartDatabaseProxy::dispatch($database);
        }

        return $activity;
    }

    /**
     * Check prerequisites that would make the queued start fail, so the user gets the
     * error immediately instead of a queued start that can only fail later.
     */
    public static function prerequisiteError(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): ?string
    {
        if (! $database->enable_ssl) {
            return null;
        }

        if (! $database->destination->server->ensureCaCertificate()) {
            return DatabaseStartException::missingCaCertificate()->getMessage();
        }

        return null;
    }
}
