<?php

use App\Actions\Database\Pgbackrest\GeneratePgbackrestConfig;
use App\Models\PgbackrestRepo;
use App\Models\S3Storage;
use App\Models\StandalonePostgresql;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
    $this->database->postgres_user = 'testuser';
    $this->database->postgres_db = 'testdb';
});

afterEach(function () {
    Mockery::close();
});

function createMockRepo(array $attrs): PgbackrestRepo
{
    $repo = Mockery::mock(PgbackrestRepo::class)->makePartial();
    $repo->repo_index = $attrs['repo_index'];
    $repo->type = $attrs['type'];
    $repo->path = $attrs['path'];
    $repo->shouldReceive('getAttribute')->with('retention_full_effective')->andReturn($attrs['retention_full'] ?? 2);
    $repo->shouldReceive('getAttribute')->with('retention_diff_effective')->andReturn($attrs['retention_diff'] ?? 7);
    $repo->shouldReceive('getAttribute')->with('retention_full_type_effective')->andReturn($attrs['retention_full_type'] ?? 'count');
    $repo->shouldReceive('getAttribute')->with('retention_archive_effective')->andReturn($attrs['retention_archive'] ?? null);
    $repo->shouldReceive('getAttribute')->with('retention_archive_type_effective')->andReturn($attrs['retention_archive_type'] ?? 'full');

    if ($attrs['type'] === 's3' && isset($attrs['s3Storage'])) {
        $repo->shouldReceive('getAttribute')->with('s3Storage')->andReturn($attrs['s3Storage']);
    } else {
        $repo->shouldReceive('getAttribute')->with('s3Storage')->andReturn(null);
    }

    return $repo;
}

function mockPgbackrestReposRelation($database, Collection $repos)
{
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('with')->with('s3Storage')->andReturnSelf();
    $mockRelation->shouldReceive('orderBy')->with('repo_index')->andReturnSelf();
    $mockRelation->shouldReceive('get')->andReturn($repos);
    $mockRelation->shouldReceive('where')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn($repos->isNotEmpty());

    $database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);
}

it('generates config with single posix repo', function () {
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $repos = collect([
        createMockRepo([
            'repo_index' => 1,
            'type' => 'posix',
            'path' => '/var/lib/pgbackrest',
            'retention_full' => 2,
            'retention_diff' => 7,
        ]),
    ]);

    mockPgbackrestReposRelation($this->database, $repos);

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('[global]');
    expect($config)->toContain('repo1-path=/var/lib/pgbackrest');
    expect($config)->toContain('repo1-retention-full-type=count');
    expect($config)->toContain('repo1-retention-full=2');
    expect($config)->toContain('repo1-retention-diff=7');
    expect($config)->toContain('[db-test-uuid]');
    expect($config)->toContain('pg1-user=testuser');
});

it('generates config with single S3 repo', function () {
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $s3Storage = Mockery::mock(S3Storage::class)->makePartial();
    $s3Storage->bucket = 'my-backup-bucket';
    $s3Storage->endpoint = 's3.amazonaws.com';
    $s3Storage->region = 'us-east-1';

    $repos = collect([
        createMockRepo([
            'repo_index' => 1,
            'type' => 's3',
            'path' => '/coolify/test-uuid',
            's3Storage' => $s3Storage,
        ]),
    ]);

    mockPgbackrestReposRelation($this->database, $repos);

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('repo1-type=s3');
    expect($config)->toContain('repo1-path=/coolify/test-uuid');
    expect($config)->toContain('repo1-s3-bucket=my-backup-bucket');
    expect($config)->toContain('repo1-s3-endpoint=s3.amazonaws.com');
    expect($config)->toContain('repo1-s3-region=us-east-1');
    expect($config)->toContain('repo1-s3-uri-style=path');
});

it('generates config with multiple repos (posix + S3)', function () {
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $s3Storage = Mockery::mock(S3Storage::class)->makePartial();
    $s3Storage->bucket = 'my-backup-bucket';
    $s3Storage->endpoint = 's3.amazonaws.com';
    $s3Storage->region = 'us-east-1';

    $repos = collect([
        createMockRepo([
            'repo_index' => 1,
            'type' => 'posix',
            'path' => '/var/lib/pgbackrest',
            'retention_full' => 2,
            'retention_diff' => 7,
        ]),
        createMockRepo([
            'repo_index' => 2,
            'type' => 's3',
            'path' => '/coolify/test-uuid',
            's3Storage' => $s3Storage,
            'retention_full' => 2,
            'retention_diff' => 7,
        ]),
    ]);

    mockPgbackrestReposRelation($this->database, $repos);

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('repo1-path=/var/lib/pgbackrest');
    expect($config)->toContain('repo1-retention-full=2');
    expect($config)->toContain('repo2-type=s3');
    expect($config)->toContain('repo2-path=/coolify/test-uuid');
    expect($config)->toContain('repo2-s3-bucket=my-backup-bucket');
    expect($config)->toContain('repo2-retention-full=2');
});

it('generates config with explicit archive retention', function () {
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $repos = collect([
        createMockRepo([
            'repo_index' => 1,
            'type' => 'posix',
            'path' => '/var/lib/pgbackrest',
            'retention_full' => 2,
            'retention_diff' => 7,
            'retention_archive' => 4,
            'retention_archive_type' => 'diff',
        ]),
    ]);

    mockPgbackrestReposRelation($this->database, $repos);

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->toContain('repo1-retention-archive-type=diff');
    expect($config)->toContain('repo1-retention-archive=4');
});

it('omits archive retention when null', function () {
    $this->database->pgbackrest_compress_type = 'lz4';
    $this->database->pgbackrest_compress_level = 6;
    $this->database->pgbackrest_log_level = 'info';

    $repos = collect([
        createMockRepo([
            'repo_index' => 1,
            'type' => 'posix',
            'path' => '/var/lib/pgbackrest',
            'retention_archive' => null,
        ]),
    ]);

    mockPgbackrestReposRelation($this->database, $repos);

    $config = GeneratePgbackrestConfig::run($this->database);

    expect($config)->not->toMatch('/repo1-retention-archive=\d+/');
});

it('usesS3 returns true when S3 repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 's3')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(true);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    expect(GeneratePgbackrestConfig::usesS3($this->database))->toBeTrue();
});

it('usesS3 returns false when no S3 repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 's3')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(false);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    expect(GeneratePgbackrestConfig::usesS3($this->database))->toBeFalse();
});

it('hasLocalRepo returns true when posix repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 'posix')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(true);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    expect(GeneratePgbackrestConfig::hasLocalRepo($this->database))->toBeTrue();
});

it('hasLocalRepo returns false when no posix repo exists', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 'posix')->andReturnSelf();
    $mockRelation->shouldReceive('exists')->andReturn(false);
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    expect(GeneratePgbackrestConfig::hasLocalRepo($this->database))->toBeFalse();
});

it('isS3ConfigComplete returns true when no S3 repos exist', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 's3')->andReturnSelf();
    $mockRelation->shouldReceive('with')->with('s3Storage')->andReturnSelf();
    $mockRelation->shouldReceive('get')->andReturn(collect([]));
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    expect(GeneratePgbackrestConfig::isS3ConfigComplete($this->database))->toBeTrue();
});

it('isS3ConfigComplete returns false when S3 storage not usable', function () {
    $s3Storage = Mockery::mock(S3Storage::class)->makePartial();
    $s3Storage->shouldReceive('isUsable')->andReturn(false);

    $repo = Mockery::mock(PgbackrestRepo::class)->makePartial();
    $repo->shouldReceive('getAttribute')->with('s3Storage')->andReturn($s3Storage);

    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 's3')->andReturnSelf();
    $mockRelation->shouldReceive('with')->with('s3Storage')->andReturnSelf();
    $mockRelation->shouldReceive('get')->andReturn(collect([$repo]));
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    expect(GeneratePgbackrestConfig::isS3ConfigComplete($this->database))->toBeFalse();
});

it('getS3EnvVars returns empty array when no S3 repos', function () {
    $mockRelation = Mockery::mock();
    $mockRelation->shouldReceive('where')->with('type', 's3')->andReturnSelf();
    $mockRelation->shouldReceive('with')->with('s3Storage')->andReturnSelf();
    $mockRelation->shouldReceive('get')->andReturn(collect([]));
    $this->database->shouldReceive('pgbackrestRepos')->andReturn($mockRelation);

    $envVars = GeneratePgbackrestConfig::getS3EnvVars($this->database);

    expect($envVars)->toBeEmpty();
});
