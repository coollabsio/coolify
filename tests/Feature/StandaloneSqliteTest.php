<?php

use App\Actions\Database\StartSqlite;
use App\Livewire\Project\Database\Sqlite\General;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use App\Services\DatabaseStartCommandExecutor;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->first();

    $this->database = create_standalone_sqlite($this->environment->id, $destination, ['sqlite_databases' => 'app.db,jobs.db']);
});

function sqliteApiHeaders(): array
{
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    return ['Authorization' => 'Bearer '.test()->user->createToken('test-token', ['*'])->plainTextToken];
}

it('creates the data volume and restores into the first configured file', function () {
    expect($this->database->image)->toBe('peakimages/sqlite:3.53.4-v0.1.0')
        ->and($this->database->databaseFilePath())->toBe('/var/lib/sqlite/app.db')
        ->and($this->database->persistentStorages()->first()->mount_path)->toBe('/var/lib/sqlite');
});

it('only accepts plain comma-separated file names', function (string $value, bool $valid) {
    expect((bool) preg_match(StandaloneSqlite::DATABASES_PATTERN, $value))->toBe($valid);
})->with([
    'single file' => ['app.db', true],
    'several files' => ['app.db,jobs.sqlite', true],
    'path traversal' => ['../escape.db', false],
    'hidden file' => ['.app.db', false],
    'shell metacharacters' => ['app.db;id', false],
    'trailing comma' => ['app.db,', false],
]);

it('generates a compose file that passes the database files to the image', function () {
    $commands = [];
    $executor = Mockery::mock(DatabaseStartCommandExecutor::class);
    $executor->shouldReceive('execute')->once()->withArgs(function (array $captured) use (&$commands) {
        $commands = $captured;

        return true;
    })->andReturn(new Activity);
    app()->instance(DatabaseStartCommandExecutor::class, $executor);

    (new StartSqlite)->handle($this->database, new Activity);

    preg_match("/^echo '([^']+)' \| base64 -d/", collect($commands)->first(fn (string $command) => str_contains($command, 'docker-compose.yml')), $matches);
    $service = Yaml::parse(base64_decode($matches[1]))['services'][$this->database->uuid];

    expect($service)->not->toHaveKey('command')
        ->and($service['environment'])->toBe(['SQLITE_DATABASES=app.db,jobs.db'])
        ->and($service['healthcheck']['test'])->toBe(['CMD', 'healthcheck.sh'])
        ->and($service['volumes'])->toBe(['sqlite-data-'.$this->database->uuid.':/var/lib/sqlite']);
});

it('backs up with a read-only VACUUM INTO snapshot instead of a SQL dump', function () {
    expect(file_get_contents(app_path('Jobs/DatabaseBackupJob.php')))
        ->toContain('sqlite3 -readonly')
        ->toContain('VACUUM INTO')
        ->not->toContain('.dump');
});

it('restores a gzipped backup with .restore into the first database file', function () {
    $command = app(DatabaseImportCommandBuilder::class)->buildRestoreCommand($this->database, '/tmp/restore_1', false);

    expect($command)
        ->toStartWith("backup='/tmp/restore_1'\n")
        ->toContain('stream() { if is_gzip; then gunzip -c "$backup"; else cat "$backup"; fi; }')
        ->toEndWith("stream > \"\$backup.db\" || fail 'The backup cannot be read. Nothing was changed.'\nsqlite3 -bail '/var/lib/sqlite/app.db' '.timeout 10000' \".restore \$backup.db\"; status=\$?; rm -f \"\$backup.db\"; exit \$status");
});

it('normalises the file list when saved from the general page', function () {
    Livewire::actingAs($this->user)
        ->test(General::class, ['database' => $this->database])
        ->set('sqliteDatabases', 'app.db, cache.db')
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->database->refresh()->sqlite_databases)->toBe('app.db,cache.db');
});

it('creates a sqlite database through the API without public access fields', function () {
    $headers = sqliteApiHeaders();
    $payload = [
        'server_uuid' => $this->server->uuid,
        'project_uuid' => $this->environment->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'sqlite_databases' => 'main.db',
    ];

    $response = $this->withHeaders($headers)->postJson('/api/v1/databases/sqlite', $payload)->assertStatus(201);

    expect(StandaloneSqlite::where('uuid', $response->json('uuid'))->value('sqlite_databases'))->toBe('main.db');

    $this->withHeaders($headers)
        ->postJson('/api/v1/databases/sqlite', $payload + ['is_public' => true, 'public_port' => 5432])
        ->assertStatus(422);
});

it('updates the database files through the API and rejects unsafe names', function () {
    $headers = sqliteApiHeaders();

    $this->withHeaders($headers)
        ->patchJson("/api/v1/databases/{$this->database->uuid}", ['sqlite_databases' => 'jobs.sqlite'])
        ->assertStatus(200);

    expect($this->database->refresh()->sqlite_databases)->toBe('jobs.sqlite');

    $this->withHeaders($headers)
        ->patchJson("/api/v1/databases/{$this->database->uuid}", ['sqlite_databases' => '../escape.db'])
        ->assertStatus(422);
});
