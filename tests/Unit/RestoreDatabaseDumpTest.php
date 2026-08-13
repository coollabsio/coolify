<?php

use App\Actions\Database\RestoreDatabaseDump;
use App\Models\StandalonePostgresql;

test('builds a pg_restore command for postgresql dumps', function () {
    $database = new class
    {
        public function getMorphClass(): string
        {
            return StandalonePostgresql::class;
        }
    };

    $command = (new RestoreDatabaseDump)->buildRestoreCommand($database, '/tmp/dump', false);

    expect($command)->toContain('pg_restore')
        ->and($command)->toContain('/tmp/dump');
});
