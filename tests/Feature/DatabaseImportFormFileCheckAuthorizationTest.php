<?php

use App\Enums\BackupStorageType;
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
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
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
        ->assertDispatched('error', 'Please select a storage.');
})->with(['admin', 'owner']);

it('does nothing when an admin checks an empty server path', function () {
    importFormFileCheckAs($this->team, 'admin')
        ->set('customLocation', '')
        ->call('checkFile')
        ->assertNotDispatched('error')
        ->assertSet('filename', null);
});

it('does not check a file on an SFTP storage without a saved host key', function () {
    $storage = S3Storage::create([
        'team_id' => $this->team->id,
        'type' => BackupStorageType::Sftp,
        'name' => 'SFTP backups',
        'region' => 'us-east-1',
        'key' => 'backup',
        'secret' => 'password',
        'endpoint' => 'sftp://1.1.1.1:2222/backups',
        'is_usable' => true,
    ]);
    $connected = false;
    app()->bind(SftpConnectionProvider::class, function () use (&$connected) {
        $connected = true;

        throw new RuntimeException('Connected.');
    });

    importFormFileCheckAs($this->team, 'owner')
        ->set('s3StorageId', $storage->id)
        ->set('s3Path', 'backups/db.dmp')
        ->call('checkS3File')
        ->assertDispatched('error')
        ->assertSet('s3FileSize', null);

    expect($connected)->toBeFalse()
        ->and($storage->fresh()->sftp_host_key)->toBeNull();
});
