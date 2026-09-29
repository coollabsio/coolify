<?php

use App\Jobs\RevalidateUnusableS3StoragesJob;
use App\Models\S3Storage;
use App\Models\Team;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function revalidationStorage(Team $team, array $attributes = []): S3Storage
{
    return S3Storage::create(array_merge([
        'name' => 'Storage',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'bucket',
        'endpoint' => 'https://93.184.216.34',
        'team_id' => $team->id,
        'is_usable' => false,
        'unusable_email_sent' => true,
    ], $attributes));
}

it('marks an unusable storage as usable when the connection works again', function () {
    $storage = revalidationStorage(Team::factory()->create());
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('files')->once()->andReturn([]);
    Storage::shouldReceive('build')->once()->andReturn($disk);

    (new RevalidateUnusableS3StoragesJob)->handle();

    $storage->refresh();
    expect($storage->is_usable)->toBeTrue()
        ->and($storage->unusable_email_sent)->toBeFalsy();
});

it('keeps a storage unusable when the connection still fails and continues with the next one', function () {
    $team = Team::factory()->create();
    $failing = revalidationStorage($team, ['bucket' => 'failing-bucket']);
    $working = revalidationStorage($team, ['bucket' => 'working-bucket']);
    $failingDisk = Mockery::mock(FilesystemAdapter::class);
    $failingDisk->shouldReceive('files')->once()->andThrow(new RuntimeException('Access denied'));
    $workingDisk = Mockery::mock(FilesystemAdapter::class);
    $workingDisk->shouldReceive('files')->once()->andReturn([]);
    Storage::shouldReceive('build')->twice()->andReturnUsing(
        fn (array $config) => $config['bucket'] === 'failing-bucket' ? $failingDisk : $workingDisk
    );

    (new RevalidateUnusableS3StoragesJob)->handle();

    expect($failing->fresh()->is_usable)->toBeFalse()
        ->and($working->fresh()->is_usable)->toBeTrue();
});

it('does not test storages that are already usable', function () {
    revalidationStorage(Team::factory()->create(), ['is_usable' => true]);
    Storage::shouldReceive('build')->never();

    (new RevalidateUnusableS3StoragesJob)->handle();
});
