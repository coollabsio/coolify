<?php

namespace App\Actions\Database;

use App\Data\ResourcePlacement;
use App\Enums\NewDatabaseTypes;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateDatabase
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(
        ResourcePlacement $placement,
        NewDatabaseTypes $type,
        array $data = [],
        ?string $image = null,
        bool $instantDeploy = false,
    ): Model {
        $environmentId = $placement->environment->id;
        $destination = $placement->destination;

        $database = match ($type) {
            NewDatabaseTypes::POSTGRESQL => create_standalone_postgresql($environmentId, $destination, $data, $image ?? 'postgres:16-alpine'),
            NewDatabaseTypes::MYSQL => create_standalone_mysql($environmentId, $destination, $data),
            NewDatabaseTypes::MARIADB => create_standalone_mariadb($environmentId, $destination, $data),
            NewDatabaseTypes::MONGODB => create_standalone_mongodb($environmentId, $destination, $data),
            NewDatabaseTypes::REDIS => create_standalone_redis($environmentId, $destination, $data),
            NewDatabaseTypes::KEYDB => create_standalone_keydb($environmentId, $destination, $data),
            NewDatabaseTypes::DRAGONFLY => create_standalone_dragonfly($environmentId, $destination, $data),
            NewDatabaseTypes::CLICKHOUSE => create_standalone_clickhouse($environmentId, $destination, $data),
        };

        if ($instantDeploy) {
            StartDatabase::dispatch($database);
        }

        return $database;
    }
}
