<?php

use App\Livewire\Project\Shared\FileBrowser;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('ssh-keys');
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);
    $this->environment = $this->project->environments()->first();
});

it('blocks non-admin members from the file browser', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $application])
        ->assertForbidden();
});

it('shows a stopped state when the container is not running', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'exited',
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $application])
        ->assertSet('containerRunning', false)
        ->assertSee('Start the container to browse its files');
});

it('lists the default directory on mount for a running database', function () {
    Process::fake(['*' => Process::sequence()
        ->push(Process::result(output: '/var/lib/postgresql'))   // defaultRoot inspect
        ->push(Process::result(output: "dir\t0\t1\tdata\nfile\t10\t2\tpostgresql.conf"))]); // list

    $database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test DB',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'image' => 'postgres:15',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileBrowser::class, ['resource' => $database])
        ->assertSet('containerRunning', true)
        ->assertSet('currentPath', '/var/lib/postgresql')
        ->assertSet('container', $database->uuid)
        ->assertSee('data')
        ->assertSee('postgresql.conf');
});
