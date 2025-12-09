<?php

use App\Models\StandalonePostgresql;

it('isPgbackrestEnabled returns true when any enabled scheduled backup uses pgbackrest', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('enabled', true)->andReturnSelf();
    $mockRelation->shouldReceive('where')->with('use_pgbackrest', true)->andReturnSelf();
    $mockRelation->shouldReceive('exists')->once()->andReturn(true);

    $database->shouldReceive('scheduledBackups')->once()->andReturn($mockRelation);
    expect($database->isPgbackrestEnabled())->toBeTrue();
});

it('isPgbackrestEnabled returns false when no scheduled backup uses pgbackrest', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();

    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('enabled', true)->andReturnSelf();
    $mockRelation->shouldReceive('where')->with('use_pgbackrest', true)->andReturnSelf();
    $mockRelation->shouldReceive('exists')->once()->andReturn(false);

    $database->shouldReceive('scheduledBackups')->once()->andReturn($mockRelation);
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
    $database->shouldReceive('getPgbackrestConfigDir')->andReturnUsing(function () use ($database) {
        return $database->workdir().'/pgbackrest';
    });

    expect($database->getPgbackrestConfigDir())->toBe('/data/coolify/databases/abc123xyz/pgbackrest');
});

it('getPgbackrestRepoDir returns path based on workdir', function () {
    $database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $database->shouldReceive('workdir')->andReturn('/data/coolify/databases/abc123xyz');
    $database->shouldReceive('getPgbackrestRepoDir')->andReturnUsing(function () use ($database) {
        return $database->workdir().'/pgbackrest-repo';
    });

    expect($database->getPgbackrestRepoDir())->toBe('/data/coolify/databases/abc123xyz/pgbackrest-repo');
});

it('pgbackrestUsesS3 returns true for s3 and s3+posix modes', function () {
    $database = new StandalonePostgresql;

    $database->setRawAttributes(['pgbackrest_repo_type' => 'posix']);
    expect($database->pgbackrestUsesS3())->toBeFalse();

    $database->setRawAttributes(['pgbackrest_repo_type' => 's3']);
    expect($database->pgbackrestUsesS3())->toBeTrue();

    $database->setRawAttributes(['pgbackrest_repo_type' => 's3+posix']);
    expect($database->pgbackrestUsesS3())->toBeTrue();

    $database->setRawAttributes(['pgbackrest_repo_type' => null]);
    expect($database->pgbackrestUsesS3())->toBeFalse();
});

it('pgbackrestHasLocalRepo returns true for posix and s3+posix modes', function () {
    $database = new StandalonePostgresql;

    $database->setRawAttributes(['pgbackrest_repo_type' => 'posix']);
    expect($database->pgbackrestHasLocalRepo())->toBeTrue();

    $database->setRawAttributes(['pgbackrest_repo_type' => 's3']);
    expect($database->pgbackrestHasLocalRepo())->toBeFalse();

    $database->setRawAttributes(['pgbackrest_repo_type' => 's3+posix']);
    expect($database->pgbackrestHasLocalRepo())->toBeTrue();

    $database->setRawAttributes(['pgbackrest_repo_type' => null]);
    expect($database->pgbackrestHasLocalRepo())->toBeTrue();
});

afterEach(function () {
    Mockery::close();
});
