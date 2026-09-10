<?php

use App\Actions\Database\StartDatabaseImport;
use App\Livewire\Project\Database\ImportForm;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Support\DatabaseImport\DatabaseImportException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'disable_two_step_confirmation' => true,
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->database = StandalonePostgresql::create([
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
});

class ImportFormRunningStateTestComponent extends ImportForm
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
    }

    public function render()
    {
        return view('livewire.project.database.import-form');
    }
}

function importForm(): Testable
{
    return Livewire::test(ImportFormRunningStateTestComponent::class, [
        'database' => test()->database,
        'server' => test()->server,
    ])
        ->set('filename', 'backup.dump')
        ->set('s3StorageId', 1)
        ->set('s3Path', '/backups/backup.dump')
        ->set('s3FileSize', 1024);
}

test('runImport resets importRunning when StartDatabaseImport rejects the import', function () {
    StartDatabaseImport::shouldRun()->once()->andThrow(
        new DatabaseImportException('The completed upload was not found.')
    );

    importForm()
        ->call('runImport')
        ->assertSet('importRunning', false)
        ->assertDispatched('error', 'The completed upload was not found.');
});

test('runImport resets importRunning when StartDatabaseImport throws a generic error', function () {
    StartDatabaseImport::shouldRun()->once()->andThrow(new RuntimeException('scp failed'));

    importForm()
        ->call('runImport')
        ->assertSet('importRunning', false)
        ->assertDispatched('error', 'scp failed');
});

test('restoreFromS3 resets importRunning when StartDatabaseImport rejects the import', function () {
    StartDatabaseImport::shouldRun()->once()->andThrow(
        new DatabaseImportException('The S3 backup was not found or exceeds the 10 GiB limit.')
    );

    importForm()
        ->call('restoreFromS3')
        ->assertSet('importRunning', false)
        ->assertDispatched('error', 'The S3 backup was not found or exceeds the 10 GiB limit.');
});

test('runImport keeps importRunning true after a successful start', function () {
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'queued',
        'properties' => ['status' => 'queued'],
    ]);
    StartDatabaseImport::shouldRun()->once()->andReturn($activity);

    importForm()
        ->call('runImport')
        ->assertSet('importRunning', true)
        ->assertSet('activityId', $activity->id)
        ->assertDispatched('activityMonitor', $activity->id)
        ->assertDispatched('databaserestore');
});
