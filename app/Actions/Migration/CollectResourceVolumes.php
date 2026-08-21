<?php

namespace App\Actions\Migration;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class CollectResourceVolumes
{
    use AsAction;

    /**
     * @return list<LocalPersistentVolume>
     */
    public function handle(Model $resource): array
    {
        if ($resource instanceof Application) {
            return $resource->persistentStorages()->get()->all();
        }

        if ($this->isStandaloneDatabase($resource) && method_exists($resource, 'persistentStorages')) {
            return $resource->persistentStorages()->get()->all();
        }

        if ($resource instanceof Service) {
            $volumes = [];
            foreach ($resource->applications as $application) {
                foreach ($application->persistentStorages as $volume) {
                    $volumes[] = $volume;
                }
            }
            foreach ($resource->databases as $database) {
                foreach ($database->persistentStorages as $volume) {
                    $volumes[] = $volume;
                }
            }

            return $volumes;
        }

        return [];
    }

    private function isStandaloneDatabase(Model $resource): bool
    {
        return $resource instanceof StandalonePostgresql
            || $resource instanceof StandaloneMongodb
            || $resource instanceof StandaloneMysql
            || $resource instanceof StandaloneMariadb
            || $resource instanceof StandaloneRedis
            || $resource instanceof StandaloneKeydb
            || $resource instanceof StandaloneDragonfly
            || $resource instanceof StandaloneClickhouse;
    }
}
