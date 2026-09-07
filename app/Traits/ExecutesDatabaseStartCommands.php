<?php

namespace App\Traits;

use App\Services\DatabaseStartCommandExecutor;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

trait ExecutesDatabaseStartCommands
{
    private function executeDatabaseStartCommands(array $commands, Model $database, ?Activity $activity = null): Activity
    {
        if ($activity) {
            return app(DatabaseStartCommandExecutor::class)->execute($commands, $database, $activity);
        }

        return remote_process($commands, $database->destination->server, callEventOnFinish: 'DatabaseStatusChanged');
    }
}
