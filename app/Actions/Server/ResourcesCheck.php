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
use Throwable;

class ResourcesCheck
{
    use AsAction;

    public function handle()
    {
        $seconds = 60;
        try {
            Application::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            ServiceApplication::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            ServiceDatabase::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandalonePostgresql::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneRedis::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneMongodb::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneMysql::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneMariadb::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneKeydb::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneDragonfly::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
            StandaloneClickhouse::query()->where('last_online_at', '<', now()->subSeconds($seconds))->update(['status' => 'exited']);
        } catch (Throwable $e) {
            return handleError($e);
        }

        return null;
    }
}
