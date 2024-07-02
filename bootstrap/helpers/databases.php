<?php

use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDocker;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Visus\Cuid2\Cuid2;

function generate_database_name(string $type): string
{
    $cuid = new Cuid2(7);

    return $type.'-database-'.$cuid;
}

function create_standalone_postgresql($environmentId, $destinationUuid, ?array $otherData = null): StandalonePostgresql
{
    $destination = StandaloneDocker::where('uuid', $destinationUuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandalonePostgresql();
    $database->name = generate_database_name('postgresql');
    $database->postgres_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environmentId;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_redis($environment_id, $destination_uuid, ?array $otherData = null): StandaloneRedis
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandaloneRedis();
    $database->name = generate_database_name('redis');
    $database->redis_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_mongodb($environment_id, $destination_uuid, ?array $otherData = null): StandaloneMongodb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandaloneMongodb();
    $database->name = generate_database_name('mongodb');
    $database->mongo_initdb_root_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}
function create_standalone_mysql($environment_id, $destination_uuid, ?array $otherData = null): StandaloneMysql
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandaloneMysql();
    $database->name = generate_database_name('mysql');
    $database->mysql_root_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->mysql_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}
function create_standalone_mariadb($environment_id, $destination_uuid, ?array $otherData = null): StandaloneMariadb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandaloneMariadb();
    $database->name = generate_database_name('mariadb');
    $database->mariadb_root_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->mariadb_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();

    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}
function create_standalone_keydb($environment_id, $destination_uuid, ?array $otherData = null): StandaloneKeydb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandaloneKeydb();
    $database->name = generate_database_name('keydb');
    $database->keydb_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function create_standalone_dragonfly($environment_id, $destination_uuid, ?array $otherData = null): StandaloneDragonfly
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandaloneDragonfly();
    $database->name = generate_database_name('dragonfly');
    $database->dragonfly_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}
function create_standalone_clickhouse($environment_id, $destination_uuid, ?array $otherData = null): StandaloneClickhouse
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }
    $database = new StandaloneClickhouse();
    $database->name = generate_database_name('clickhouse');
    $database->clickhouse_admin_password = \Illuminate\Support\Str::password(length: 64, symbols: false);
    $database->environment_id = $environment_id;
    $database->destination_id = $destination->id;
    $database->destination_type = $destination->getMorphClass();
    if ($otherData) {
        $database->fill($otherData);
    }
    $database->save();

    return $database;
}

function delete_backup_locally(?string $filename, Server $server): void
{
    if (empty($filename)) {
        return;
    }
    instant_remote_process(["rm -f \"{$filename}\""], $server, throwError: false);
}

function isPublicPortAlreadyUsed(Server $server, int $port, ?string $id = null): bool
{
    if ($id) {
        $foundDatabase = $server->databases()->where('public_port', $port)->where('is_public', true)->where('id', '!=', $id)->first();
    } else {
        $foundDatabase = $server->databases()->where('public_port', $port)->where('is_public', true)->first();
    }
    if ($foundDatabase) {
        return true;
    }

    return false;
}
