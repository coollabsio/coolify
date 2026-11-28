<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function localFileVolumesUniqueIndexMigration(): object
{
    return require database_path('migrations/2026_08_18_120000_update_local_file_volumes_unique_index.php');
}

it('refuses to roll back when sibling file mounts violate the previous uniqueness contract', function () {
    $migration = localFileVolumesUniqueIndexMigration();

    foreach (['/host/one', '/host/two'] as $index => $fsPath) {
        DB::table('local_file_volumes')->insert([
            'uuid' => "volume-{$index}",
            'fs_path' => $fsPath,
            'fs_path_hash' => hash('sha256', $fsPath),
            'mount_path' => '/container/file',
            'resource_id' => 123,
            'resource_type' => 'App\\Models\\Application',
        ]);
    }

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back');

    expect(Schema::hasColumn('local_file_volumes', 'fs_path_hash'))->toBeTrue();
    expect(collect(Schema::getIndexes('local_file_volumes'))->pluck('name'))
        ->toContain('local_file_volumes_source_mount_resource_unique');
});

it('restores the previous unique index when rollback data is compatible', function () {
    $migration = localFileVolumesUniqueIndexMigration();

    $migration->down();

    expect(Schema::hasColumn('local_file_volumes', 'fs_path_hash'))->toBeFalse();
    expect(collect(Schema::getIndexes('local_file_volumes'))->pluck('name'))
        ->toContain('local_file_volumes_mount_path_resource_id_resource_type_unique');
});
