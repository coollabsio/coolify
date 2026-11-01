<?php

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackupExecution;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Filesystem\FilesystemAdapter;
use Tests\TestCase;

uses(TestCase::class);

it('defers replica reads until the download is sent', function (bool $valid) {
    $stream = $valid ? fopen('php://memory', 'r+') : false;
    if ($valid) {
        fwrite($stream, 'backup contents');
        rewind($stream);
    }
    $read = false;
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('readStream')->once()->with('/backup.sql')->andReturnUsing(function () use (&$read, $stream) {
        $read = true;

        return $stream;
    });
    $storage = Mockery::mock(S3Storage::class)->makePartial();
    $storage->shouldReceive('filesystem')->andReturn($disk);
    $storage->shouldReceive('isS3')->andReturnFalse();
    $replica = (object) ['s3' => $storage, 's3_storage_id' => 1];
    $relation = Mockery::mock(HasMany::class);
    foreach (['with', 'where', 'whereHas', 'orderBy'] as $method) {
        $relation->shouldReceive($method)->andReturnSelf();
    }
    $relation->shouldReceive('get')->andReturn(collect([$replica]));
    $execution = Mockery::mock(ScheduledDatabaseBackupExecution::class)->makePartial();
    $execution->filename = '/backup.sql';
    $execution->shouldReceive('s3Replicas')->andReturn($relation);

    $response = $execution->s3Download(1, 1);

    expect($read)->toBeFalse();
    ob_start();
    try {
        $response->sendContent();
        $contents = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect($contents)->toBe($valid ? 'backup contents' : '')
        ->and($read)->toBeTrue();
    if ($valid) {
        expect(is_resource($stream))->toBeFalse();
    }
})->with([true, false]);
