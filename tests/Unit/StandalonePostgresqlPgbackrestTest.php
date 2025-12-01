<?php

use App\Models\StandalonePostgresql;

it('isPgbackrestEnabled returns boolean based on attribute', function () {
    $database = new StandalonePostgresql;

    $database->setRawAttributes(['pgbackrest_enabled' => true]);
    expect($database->isPgbackrestEnabled())->toBeTrue();

    $database->setRawAttributes(['pgbackrest_enabled' => false]);
    expect($database->isPgbackrestEnabled())->toBeFalse();

    $database->setRawAttributes(['pgbackrest_enabled' => null]);
    expect($database->isPgbackrestEnabled())->toBeFalse();
});

it('getPgbackrestStanzaName returns correct format', function () {
    $database = new StandalonePostgresql;
    $database->setRawAttributes(['uuid' => 'abc123xyz']);

    expect($database->getPgbackrestStanzaName())->toBe('db-abc123xyz');
});

it('getPgbackrestConfigDir returns path based on workdir', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('workdir')->andReturn('/data/coolify/databases/abc123xyz');

    expect($database->getPgbackrestConfigDir())->toBe('/data/coolify/databases/abc123xyz/pgbackrest');
});

it('getPgbackrestRepoDir returns path based on workdir', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('workdir')->andReturn('/data/coolify/databases/abc123xyz');

    expect($database->getPgbackrestRepoDir())->toBe('/data/coolify/databases/abc123xyz/pgbackrest-repo');
});

it('has pgbackrest_enabled in casts', function () {
    $database = new StandalonePostgresql;
    $casts = $database->getCasts();

    expect($casts['pgbackrest_enabled'])->toBe('boolean');
});

afterEach(function () {
    Mockery::close();
});
