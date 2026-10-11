<?php

use App\Actions\Database\StartDatabaseImport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
});

function stageDatabaseImportUploadForCleanupTest(string $databaseUuid, string $uploadId, Carbon $modifiedAt): string
{
    $directory = "upload/imports/1/{$databaseUuid}/{$uploadId}";
    Storage::put("{$directory}/restore", 'dump');
    touch(Storage::path("{$directory}/restore"), $modifiedAt->getTimestamp());

    return $directory;
}

it('deletes staged API import uploads older than a day and keeps newer ones', function () {
    $old = stageDatabaseImportUploadForCleanupTest('db-old', 'f4c1d3a2-0000-4000-8000-000000000001', now()->subHours(25));
    $fresh = stageDatabaseImportUploadForCleanupTest('db-fresh', 'f4c1d3a2-0000-4000-8000-000000000002', now()->subHours(23));

    $this->artisan('cleanup:database-import-uploads')->assertSuccessful();

    expect(Storage::exists($old))->toBeFalse()
        ->and(Storage::exists('upload/imports/1/db-old'))->toBeFalse()
        ->and(Storage::exists("{$fresh}/restore"))->toBeTrue();
});

it('keeps an old staged upload while an import of its database holds the import lock', function () {
    $directory = stageDatabaseImportUploadForCleanupTest('db-busy', 'f4c1d3a2-0000-4000-8000-000000000003', now()->subDays(2));
    $lock = Cache::lock(StartDatabaseImport::lockKey('db-busy'), 60);
    $lock->get();

    $this->artisan('cleanup:database-import-uploads')->assertSuccessful();

    expect(Storage::exists("{$directory}/restore"))->toBeTrue();

    $lock->release();
    $this->artisan('cleanup:database-import-uploads')->assertSuccessful();

    expect(Storage::exists($directory))->toBeFalse();
});
