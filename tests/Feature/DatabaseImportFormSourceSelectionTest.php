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
use App\Support\DatabaseImport\DatabaseImportSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake();
    InstanceSettings::forceCreate([
        'id' => 0,
        'disable_two_step_confirmation' => true,
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'db',
        'image' => 'postgres:17',
        'status' => 'running',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->stagedUpload = "upload/{$this->database->uuid}/restore";
});

class ImportFormSourceSelectionTestComponent extends ImportForm
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
    }

    protected function serverFileExists(string $path): bool
    {
        return true;
    }

    public function render()
    {
        return view('livewire.project.database.import-form');
    }
}

function importFormSourceSelection(): Testable
{
    return Livewire::test(ImportFormSourceSelectionTestComponent::class, [
        'database' => test()->database,
        'server' => test()->server,
    ]);
}

function expectImportFromSource(string $type, ?string $path = null): void
{
    StartDatabaseImport::shouldRun()->once()
        ->withArgs(fn ($resource, DatabaseImportSource $source): bool => $source->type === $type && $source->path === $path)
        ->andReturn(Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]));
}

test('a server path import ignores and deletes an earlier upload that was not imported', function () {
    Storage::put($this->stagedUpload, 'old upload');
    expectImportFromSource('server', '/backups/server.dump');

    importFormSourceSelection()
        ->set('replaceExisting', true)
        ->set('customLocation', '/backups/server.dump')
        ->call('checkFile')
        ->call('runImport')
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore');

    expect(Storage::exists($this->stagedUpload))->toBeFalse();
});

test('an upload import uses the uploaded file', function () {
    expectImportFromSource('upload');

    $form = importFormSourceSelection()
        ->set('customLocation', '/backups/server.dump')
        ->call('checkFile');

    Storage::put($this->stagedUpload, 'new upload');

    $form->call('selectUploadedFile', 'backup.dump')
        ->assertSet('customLocation', '')
        ->call('runImport')
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore');
});

test('an upload is not selected when the staged file is missing', function () {
    StartDatabaseImport::shouldRun()->never();

    importFormSourceSelection()
        ->call('selectUploadedFile', 'backup.dump')
        ->assertDispatched('error')
        ->call('runImport')
        ->assertDispatched('error', 'Please select a file to import.');
});

test('a server path changed after the check is not imported', function () {
    Storage::put($this->stagedUpload, 'old upload');
    StartDatabaseImport::shouldRun()->never();

    importFormSourceSelection()
        ->set('customLocation', '/backups/server.dump')
        ->call('checkFile')
        ->set('customLocation', '/backups/other.dump')
        ->call('runImport')
        ->assertDispatched('error', 'Please select a file to import.');
});

test('an S3 restore deletes an earlier upload that was not imported', function () {
    Storage::put($this->stagedUpload, 'old upload');
    StartDatabaseImport::shouldRun()->once()
        ->withArgs(fn ($resource, DatabaseImportSource $source): bool => $source->type === 's3')
        ->andReturn(Activity::create(['log_name' => 'default', 'description' => 'queued', 'properties' => ['status' => 'queued']]));

    importFormSourceSelection()
        ->set('s3StorageId', 1)
        ->set('s3Path', '/backups/backup.dump')
        ->set('s3FileSize', 1024)
        ->call('restoreFromS3')
        ->assertDispatched('databaserestore');

    expect(Storage::exists($this->stagedUpload))->toBeFalse();
});
