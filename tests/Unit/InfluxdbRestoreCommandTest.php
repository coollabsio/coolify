<?php

use App\Livewire\Project\Database\Import;
use App\Livewire\Project\Database\ImportForm;
use App\Models\StandaloneInfluxdb;
use App\Support\InfluxdbRestoreCommand;
use Tests\TestCase;

uses(TestCase::class);

function influxdbImportForm(): ImportForm
{
    $component = new class extends ImportForm
    {
        public $resource;
    };

    $database = Mockery::mock(StandaloneInfluxdb::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandaloneInfluxdb::class);
    $component->resource = $database;

    return $component;
}

test('the restore script unpacks the archive before handing influx a directory', function () {
    $script = InfluxdbRestoreCommand::script('/tmp/restore_abc');

    expect($script)->toContain("tar -xzf '/tmp/restore_abc' -C \"\$RESTORE_DIR\"")
        ->and($script)->toContain('influx restore "$SRC"')
        ->and($script)->toContain('--host http://127.0.0.1:8086');
});

test('the restore script drops the target bucket first because influx refuses to overwrite it', function () {
    $script = InfluxdbRestoreCommand::script('/tmp/restore_abc');

    expect($script)->toContain('influx bucket delete --name "$DOCKER_INFLUXDB_INIT_BUCKET"')
        ->and($script)->toContain('|| true')
        ->and(mb_strpos($script, 'influx bucket delete'))
        ->toBeLessThan(mb_strpos($script, 'influx restore'));
});

test('the restore stays scoped to the bucket and never uses --full', function () {
    $script = InfluxdbRestoreCommand::script('/tmp/restore_abc');

    expect($script)->toContain('--bucket "$DOCKER_INFLUXDB_INIT_BUCKET"')
        ->and($script)->not->toContain('--full');
});

test('credentials are read from the container environment, never interpolated by Coolify', function () {
    $script = InfluxdbRestoreCommand::script('/tmp/restore_abc');

    expect($script)->toContain('--token "$DOCKER_INFLUXDB_INIT_ADMIN_TOKEN"')
        ->and($script)->toContain('--org "$DOCKER_INFLUXDB_INIT_ORG"');
});

test('the archive path is shell-escaped', function () {
    $script = InfluxdbRestoreCommand::script("/tmp/x'; rm -rf /; echo '");

    expect($script)->toContain('tar -xzf '.escapeshellarg("/tmp/x'; rm -rf /; echo '"))
        ->and($script)->not->toContain("tar -xzf /tmp/x'; rm -rf /");
});

test('the temporary extraction directory is always cleaned up', function () {
    expect(InfluxdbRestoreCommand::script('/tmp/restore_abc'))
        ->toContain('trap \'rm -rf "$RESTORE_DIR"\' EXIT');
});

test('ImportForm builds the influxdb restore script for a standalone influxdb', function () {
    $result = influxdbImportForm()->buildRestoreCommand('/tmp/restore_abc');

    expect($result)->toBe(InfluxdbRestoreCommand::script('/tmp/restore_abc'))
        ->and($result)->not->toBe('');
});

test('influxdb is no longer flagged as an unsupported restore target', function () {
    $method = new ReflectionMethod(Import::class, 'isUnsupportedResource');
    $method->setAccessible(true);

    expect($method->invoke(new Import, new StandaloneInfluxdb))->toBeFalse();
});
