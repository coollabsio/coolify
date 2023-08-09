<?php

use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use Visus\Cuid2\Cuid2;

function generate_database_name(string $type): string
{
    $cuid = new Cuid2(7);
    return $type . '-database-' . $cuid;
}

function create_standalone_postgresql($environment_id, $destination_uuid): StandalonePostgresql
{
    // TODO: If another type of destination is added, this will need to be updated.
    $destination = StandaloneDocker::where('uuid', $destination_uuid)->first();
    if (!$destination) {
        throw new Exception('Destination not found');
    }
    return StandalonePostgresql::create([
        'name' => generate_database_name('postgresql'),
        'postgres_password' => \Illuminate\Support\Str::password(),
        'environment_id' => $environment_id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

}
