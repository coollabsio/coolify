<?php

namespace App\Actions\Database;

use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Lorisleiva\Actions\Concerns\AsAction;

class StopDatabaseProxy
{
    use AsAction;

    public function handle(StandaloneRedis|StandalonePostgresql $database)
    {
        instant_remote_process(["docker rm -f {$database->uuid}-proxy"], $database->destination->server);
        $database->is_public = false;
        $database->save();
    }
}
