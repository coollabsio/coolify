<?php

use App\Livewire\Project\Database\ImportForm;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
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
});

class ImportFormFileCheckAuthorizationTestComponent extends ImportForm
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

    public function render()
    {
        return view('livewire.project.database.import-form');
    }
}

function importFormFileCheckAs(Team $team, string $role): Testable
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return Livewire::test(ImportFormFileCheckAuthorizationTestComponent::class, [
        'database' => test()->database,
        'server' => test()->server,
    ]);
}

it('forbids members from checking a server file', function () {
    importFormFileCheckAs($this->team, 'member')
        ->set('customLocation', 'not-an-absolute-path')
        ->call('checkFile')
        ->assertForbidden();
});

it('forbids members from checking an S3 file', function () {
    importFormFileCheckAs($this->team, 'member')
        ->call('checkS3File')
        ->assertForbidden();
});

it('lets admins and owners check files', function (string $role) {
    importFormFileCheckAs($this->team, $role)
        ->set('customLocation', 'not-an-absolute-path')
        ->call('checkFile')
        ->assertDispatched('error', 'Invalid file path. Path must be absolute and contain only safe characters (alphanumerics, dots, dashes, underscores, slashes).')
        ->call('checkS3File')
        ->assertDispatched('error', 'Please select an S3 storage.');
})->with(['admin', 'owner']);

it('does nothing when an admin checks an empty server path', function () {
    importFormFileCheckAs($this->team, 'admin')
        ->set('customLocation', '')
        ->call('checkFile')
        ->assertNotDispatched('error')
        ->assertSet('filename', null);
});
