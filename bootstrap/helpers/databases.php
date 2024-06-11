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

function create_standalone_postgresql($environment_id, $destination_uuid): StandalonePostgresql
{
    // TODO: If another type of destination is added, this will need to be updated.
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandalonePostgresql::create([
        'name' => generate_database_name('postgresql'),
        'postgres_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function create_standalone_redis($environment_id, $destination_uuid): StandaloneRedis
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandaloneRedis::create([
        'name' => generate_database_name('redis'),
        'redis_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function create_standalone_mongodb($environment_id, $destination_uuid): StandaloneMongodb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandaloneMongodb::create([
        'name' => generate_database_name('mongodb'),
        'mongo_initdb_root_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}
function create_standalone_mysql($environment_id, $destination_uuid): StandaloneMysql
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandaloneMysql::create([
        'name' => generate_database_name('mysql'),
        'mysql_root_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'mysql_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}
function create_standalone_mariadb($environment_id, $destination_uuid): StandaloneMariadb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandaloneMariadb::create([
        'name' => generate_database_name('mariadb'),
        'mariadb_root_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'mariadb_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}
function create_standalone_keydb($environment_id, $destination_uuid): StandaloneKeydb
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandaloneKeydb::create([
        'name' => generate_database_name('keydb'),
        'keydb_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function create_standalone_dragonfly($environment_id, $destination_uuid): StandaloneDragonfly
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandaloneDragonfly::create([
        'name' => generate_database_name('dragonfly'),
        'dragonfly_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}
function create_standalone_clickhouse($environment_id, $destination_uuid): StandaloneClickhouse
{
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (! $destination) {
        throw new Exception('Destination not found');
    }

    return StandaloneClickhouse::create([
        'name' => generate_database_name('clickhouse'),
        'clickhouse_admin_password' => \Illuminate\Support\Str::password(length: 64, symbols: false),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

/**
 * Delete file locally on the filesystem.
 */
function delete_backup_locally(?string $filename, Server $server): void
{
    if (empty($filename)) {
        return;
    }
    instant_remote_process(["rm -f \"{$filename}\""], $server, throwError: false);
}
