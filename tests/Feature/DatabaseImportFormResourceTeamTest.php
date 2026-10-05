<?php

use App\Actions\Database\StartDatabaseImport;
use App\Livewire\Project\Database\ImportForm;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate([
        'id' => 0,
        'disable_two_step_confirmation' => true,
    ]);

    $this->resourceTeam = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->resourceTeam->members()->attach($this->user, ['role' => 'admin']);
    $this->otherTeam->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->resourceTeam]);

    $this->server = Server::factory()->create(['team_id' => $this->resourceTeam->id]);
    $destination = StandaloneDocker::firstOrCreate(
        ['server_id' => $this->server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'docker']
    );
    $project = Project::factory()->create(['team_id' => $this->resourceTeam->id]);
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

    $s3Attributes = ['region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret', 'bucket' => 'backups', 'endpoint' => 'https://s3.example.com', 'is_usable' => true];
    $this->resourceTeamStorage = S3Storage::create([...$s3Attributes, 'team_id' => $this->resourceTeam->id, 'name' => 'resource-team-s3']);
    $this->otherTeamStorage = S3Storage::create([...$s3Attributes, 'team_id' => $this->otherTeam->id, 'name' => 'other-team-s3']);
});

class ImportFormResourceTeamTestComponent extends ImportForm
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

function importFormAfterTeamSwitch(): Testable
{
    $component = Livewire::test(ImportFormResourceTeamTestComponent::class, [
        'database' => test()->database,
        'server' => test()->server,
    ]);

    session(['currentTeam' => test()->otherTeam]);

    return $component;
}

function fakeStartedDatabaseImport(): Activity
{
    return Activity::create([
        'log_name' => 'default',
        'description' => 'queued',
        'properties' => ['status' => 'queued'],
    ]);
}

test('a file import started after a session team switch runs for the database team', function () {
    StartDatabaseImport::shouldRun()->once()
        ->withArgs(fn ($resource, $source, int $teamId): bool => $teamId === $this->resourceTeam->id)
        ->andReturn(fakeStartedDatabaseImport());

    importFormAfterTeamSwitch()
        ->set('filename', 'backup.dump')
        ->call('runImport')
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore');
});

test('an S3 restore started after a session team switch runs for the database team', function () {
    StartDatabaseImport::shouldRun()->once()
        ->withArgs(fn ($resource, $source, int $teamId): bool => $teamId === $this->resourceTeam->id
            && $source->s3StorageUuid === (string) $this->resourceTeamStorage->id)
        ->andReturn(fakeStartedDatabaseImport());

    importFormAfterTeamSwitch()
        ->set('s3StorageId', $this->resourceTeamStorage->id)
        ->set('s3Path', '/backups/backup.dump')
        ->set('s3FileSize', 1024)
        ->call('restoreFromS3')
        ->assertNotDispatched('error')
        ->assertDispatched('databaserestore');
});

test('the S3 storage list contains only storages of the database team', function () {
    importFormAfterTeamSwitch()
        ->call('loadAvailableS3Storages')
        ->assertSet('availableS3Storages', fn (array $storages): bool => collect($storages)->pluck('id')->all() === [$this->resourceTeamStorage->id]);
});

test('an S3 storage of another team cannot be checked for the database', function () {
    importFormAfterTeamSwitch()
        ->set('s3StorageId', $this->otherTeamStorage->id)
        ->set('s3Path', '/backups/backup.dump')
        ->call('checkS3File')
        ->assertNotFound();
});

test('a member of the database team cannot start an import after switching to a team they own', function () {
    $this->resourceTeam->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();
    StartDatabaseImport::shouldRun()->never();

    importFormAfterTeamSwitch()
        ->set('filename', 'backup.dump')
        ->call('runImport')
        ->assertForbidden();
});
