<?php

namespace App\Traits;

use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;

trait ResolvesDatabaseTeamId
{
    protected function resolveDatabaseTeamId(
        StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|ServiceDatabase|StandaloneDragonfly|StandaloneClickhouse $database
    ): ?int {
        if ($database->getMorphClass() === ServiceDatabase::class) {
            $teamId = data_get($database, 'service.environment.project.team_id');
            if (! is_null($teamId)) {
                return (int) $teamId;
            }
        }

        $teamId = data_get($database, 'environment.project.team_id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        $teamId = data_get($database, 'team.id');
        if (! is_null($teamId)) {
            return (int) $teamId;
        }

        return null;
    }
}
