<?php

use App\Actions\Database\Pgbackrest\GeneratePgbackrestConfig;
use App\Models\StandalonePostgresql;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
    $this->database->postgres_user = 'testuser';
    $this->database->postgres_db = 'testdb';
});

afterEach(function () {
    Mockery::close();
});

it('generates config with default retention values', function () {
    $this->database->pgbackrest_retention_full = null;
    $this->database->pgbackrest_retention_diff = null;
    $this->database->pgbackrest_retention_full_type = null;
    $this->database->pgbackrest_retention_archive = null;
    $this->database->pgbackrest_retention_archive_type = null;
    $this->database->pgbackrest_compress_type = null;
    $this->database->pgbackrest_compress_level = null;
    $this->database->pgbackrest_log_level = null;

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('repo1-retention-full-type=count');
    expect($config)->toContain('repo1-retention-full=2');
    expect($config)->toContain('repo1-retention-diff=7');
    expect($config)->toContain('repo1-retention-archive-type=full');
    expect($config)->not->toContain('repo1-retention-archive=');
});

it('generates config with custom retention full type as time', function () {
    $this->database->pgbackrest_retention_full = 30;
    $this->database->pgbackrest_retention_diff = 14;
    $this->database->pgbackrest_retention_full_type = 'time';
    $this->database->pgbackrest_retention_archive = null;
    $this->database->pgbackrest_retention_archive_type = 'full';
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('repo1-retention-full-type=time');
    expect($config)->toContain('repo1-retention-full=30');
    expect($config)->toContain('repo1-retention-diff=14');
});

it('generates config with explicit archive retention', function () {
    $this->database->pgbackrest_retention_full = 2;
    $this->database->pgbackrest_retention_diff = 7;
    $this->database->pgbackrest_retention_full_type = 'count';
    $this->database->pgbackrest_retention_archive = 4;
    $this->database->pgbackrest_retention_archive_type = 'diff';
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('repo1-retention-archive-type=diff');
    expect($config)->toContain('repo1-retention-archive=4');
});

it('omits archive retention when null to use pgbackrest default', function () {
    $this->database->pgbackrest_retention_full = 2;
    $this->database->pgbackrest_retention_diff = 7;
    $this->database->pgbackrest_retention_full_type = 'count';
    $this->database->pgbackrest_retention_archive = null;
    $this->database->pgbackrest_retention_archive_type = 'full';
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('repo1-retention-archive-type=full');
    // Should not have explicit archive retention value
    expect($config)->not->toMatch('/repo1-retention-archive=\d+/');
});

it('generates complete stanza configuration', function () {
    $this->database->pgbackrest_retention_full = 2;
    $this->database->pgbackrest_retention_diff = 7;
    $this->database->pgbackrest_retention_full_type = 'count';
    $this->database->pgbackrest_retention_archive = null;
    $this->database->pgbackrest_retention_archive_type = 'full';
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('[global]');
    expect($config)->toContain('[db-test-uuid]');
    expect($config)->toContain('pg1-path=/var/lib/postgresql/data');
    expect($config)->toContain('pg1-user=testuser');
    expect($config)->toContain('pg1-database=testdb');
    expect($config)->toContain('start-fast=y');
    expect($config)->toContain('delta=y');
});
