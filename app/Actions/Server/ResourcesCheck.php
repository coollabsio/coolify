<?php

namespace App\Actions\Server;

use App\Models\Application;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Lorisleiva\Actions\Concerns\AsAction;

class ResourcesCheck
{
    use AsAction;

    public function handle()
    {
        $seconds = 60;
        try {
            Application::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            ServiceApplication::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            ServiceDatabase::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandalonePostgresql::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneRedis::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneMongodb::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneMysql::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneMariadb::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneKeydb::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneDragonfly::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneClickhouse::where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
