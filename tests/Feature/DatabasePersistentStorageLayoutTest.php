<?php

use App\Livewire\Project\Shared\Storages\All;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('gates standalone database storage actions behind the update permission', function (string $role, bool $showsActions) {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $team->id])->id,
    ]);
    $database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'pg-test',
        'postgres_password' => 'secret',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    LocalPersistentVolume::create([
        'uuid' => (string) Str::uuid(),
        'name' => $database->uuid.'-data',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    Livewire::test(All::class, ['resource' => $database])
        ->assertSet('canUpdate', $showsActions)
        ->assertSet('showActionsColumn', $showsActions);
})->with([
    'owner' => ['owner', true],
    'member' => ['member', false],
]);
