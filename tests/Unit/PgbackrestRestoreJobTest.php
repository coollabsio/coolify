<?php

use App\Models\StandalonePostgresql;
use App\Services\PgbackrestService;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
});

afterEach(function () {
    Mockery::close();
});

it('service buildRestoreCommand includes type=immediate when no target time', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand('backup-label');

    expect($command)->toContain('--type=immediate');
    expect($command)->toContain('--target-action=promote');
    expect($command)->toContain('--delta');
    expect($command)->toContain('--link-all');
});

it('service buildRestoreCommand includes type=time when target time is provided', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand('backup-label', '2024-12-01 12:00:00');

    expect($command)->toContain('--type=time');
    expect($command)->toContain("--target='2024-12-01 12:00:00'");
    expect($command)->not->toContain('--type=immediate');
});

it('service buildRestoreCommand includes backup label when provided', function () {
    $service = PgbackrestService::for($this->database);

    $command = $service->buildRestoreCommand('my-backup-20241201');

    expect($command)->toContain("--set='my-backup-20241201'");
});
