<?php

use App\Actions\Database\StartDatabaseImport;
use App\Livewire\Project\Database\ImportForm;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\DatabaseImport\DatabaseImportException;
use App\Support\DatabaseImport\DatabaseImportSource;
use App\Support\RemoteProcessCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('constants.ssh.mux_enabled', false);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'disable_two_step_confirmation' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'user' => 'root']);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->sqlite = create_standalone_sqlite($this->environment->id, $this->destination, ['sqlite_databases' => 'app.db,cache.db']);
    $this->sqlite->update(['status' => 'running:healthy']);
    $this->postgres = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    Queue::fake();
});

/**
 * Starts an import from a server path and returns the restore script that runs in the container.
 */
function importRestoreScript(Model $database, DatabaseImportSource $source): string
{
    Process::fake([
        '*stat -c %s*' => Process::result('2048'),
        '*od -An -tx1*' => Process::result(' 1f 8b 08 00 00 00'),
        '*' => Process::result(''),
    ]);

    $activity = app(StartDatabaseImport::class)->handle($database, $source, test()->team->id);
    preg_match("/echo '([^']+)' \\| base64 -d/", (string) RemoteProcessCommand::read($activity), $matches);

    return base64_decode($matches[1]);
}

class ImportFormRestoreOptionsTestComponent extends ImportForm
{
    public function mount($database = null, $server = null): void
    {
        $this->resourceId = $database->id;
        $this->resourceType = $database::class;
        $this->serverId = $server->id;
        $this->container = $database->uuid;
        $this->resourceUuid = $database->uuid;
        $this->resourceStatus = $database->status ?? '';
        $this->resourceDbType = $database->type();
        $this->loadAvailableS3Storages();
        $this->initializeRestoreOptions();
    }

    public function render()
    {
        return view('livewire.project.database.import-form');
    }
}

function restoreOptionsForm(Model $database): Testable
{
    return Livewire::test(ImportFormRestoreOptionsTestComponent::class, ['database' => $database, 'server' => test()->server]);
}

test('restores a SQLite server backup into the file named in the backup', function () {
    $script = importRestoreScript($this->sqlite, new DatabaseImportSource('server', path: '/backups/sqlite-backup-cache.db-1700000000.gz'));

    expect($script)->toContain("sqlite3 -bail '/var/lib/sqlite/cache.db'")
        ->not->toContain('/var/lib/sqlite/app.db');
});

test('restores a SQLite backup into the selected database file', function () {
    $script = importRestoreScript($this->sqlite, new DatabaseImportSource('server', path: '/backups/sqlite-backup-app.db-1.gz', sqliteDatabase: 'cache.db'));

    expect($script)->toContain("sqlite3 -bail '/var/lib/sqlite/cache.db'");
});

test('rejects a SQLite restore target that is not one of the database files', function (string $target) {
    Process::fake();

    expect(fn () => app(StartDatabaseImport::class)->handle(
        $this->sqlite,
        new DatabaseImportSource('server', path: '/backups/backup.gz', sqliteDatabase: $target),
        $this->team->id,
    ))->toThrow(DatabaseImportException::class, 'not one of the files of this database');

    Queue::assertNothingPushed();
})->with(['unknown file' => ['other.db'], 'path traversal' => ['../app.db'], 'absolute path' => ['/etc/passwd']]);

test('passes the keep owners option to the PostgreSQL restore script', function (bool $keepOwners) {
    $script = importRestoreScript($this->postgres, new DatabaseImportSource('server', path: '/backups/app.dump', keepOwners: $keepOwners));

    expect(str_contains($script, '--no-owner --no-acl'))->toBe(! $keepOwners);
})->with(['default' => [false], 'keep owners' => [true]]);

test('the import form strips owners by default and keeps them when the option is checked', function () {
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    StartDatabaseImport::shouldRun()->once()->withArgs(fn ($resource, DatabaseImportSource $source) => $source->keepOwners === true)->andReturn($activity);

    $form = restoreOptionsForm($this->postgres)
        ->assertSet('keepOwners', false)
        ->assertSee('Keep owners and privileges');
    expect($form->get('restoreCommandText'))->toContain('--no-owner --no-acl');

    $form->set('keepOwners', true);
    expect($form->get('restoreCommandText'))->not->toContain('--no-owner');

    $form->set('filename', 'backup.dump')->set('customLocation', '/backups/backup.dump')->call('runImport');
});

test('the import form lets the user pick the SQLite database file to restore into', function () {
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    StartDatabaseImport::shouldRun()->once()->withArgs(fn ($resource, DatabaseImportSource $source) => $source->sqliteDatabase === 'cache.db')->andReturn($activity);

    $form = restoreOptionsForm($this->sqlite)
        ->assertSet('sqliteDatabase', 'app.db')
        ->assertSee('Restore into');
    expect($form->get('restoreCommandText'))->toContain("'/var/lib/sqlite/app.db'");

    $form->call('selectSqliteDatabaseFor', 'sqlite-backup-cache.db-1700000000.gz')
        ->assertSet('sqliteDatabase', 'cache.db');
    expect($form->get('restoreCommandText'))->toContain("'/var/lib/sqlite/cache.db'");

    $form->set('filename', 'backup.gz')->set('customLocation', '/backups/backup.gz')->call('runImport');
});

test('the import form does not accept a SQLite target from the client that is not a database file', function () {
    $form = restoreOptionsForm($this->sqlite)
        ->set('sqliteDatabase', '../../etc/passwd')
        ->assertSet('sqliteDatabase', 'app.db');

    expect($form->get('restoreCommandText'))->not->toContain('passwd');
});

test('the API passes keep owners and the SQLite target to the import', function () {
    // API requests authenticate with the token, not with the session user from beforeEach.
    app('auth')->forgetGuards();
    $headers = ['Authorization' => 'Bearer '.$this->user->createToken('imports', ['*'])->plainTextToken];
    $activity = Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]);
    $action = Mockery::mock(StartDatabaseImport::class);
    $action->shouldReceive('handle')->once()->withArgs(fn ($resource, DatabaseImportSource $source) => $resource->is($this->postgres) && $source->keepOwners === true)->andReturn($activity);
    $action->shouldReceive('handle')->once()->withArgs(fn ($resource, DatabaseImportSource $source) => $resource->is($this->sqlite) && $source->sqliteDatabase === 'cache.db')->andReturn($activity);
    app()->instance(StartDatabaseImport::class, $action);

    $this->withHeaders($headers)
        ->postJson("/api/v1/databases/{$this->postgres->uuid}/imports", ['source' => 'server', 'path' => '/tmp/backup.dump', 'keep_owners' => true])
        ->assertAccepted();
    $this->withHeaders($headers)
        ->postJson("/api/v1/databases/{$this->sqlite->uuid}/imports", ['source' => 'server', 'path' => '/tmp/backup.gz', 'sqlite_database' => 'cache.db'])
        ->assertAccepted();
    $this->withHeaders($headers)
        ->postJson("/api/v1/databases/{$this->postgres->uuid}/imports", ['source' => 'server', 'path' => '/tmp/backup.dump', 'keep_owners' => 'yes'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('keep_owners');
});

test('the API rejects a SQLite target that is not a database file', function () {
    Process::fake();
    // API requests authenticate with the token, not with the session user from beforeEach.
    app('auth')->forgetGuards();
    $headers = ['Authorization' => 'Bearer '.$this->user->createToken('imports', ['*'])->plainTextToken];

    $this->withHeaders($headers)
        ->postJson("/api/v1/databases/{$this->sqlite->uuid}/imports", ['source' => 'server', 'path' => '/tmp/backup.gz', 'sqlite_database' => '../app.db'])
        ->assertUnprocessable();

    Queue::assertNothingPushed();
});
