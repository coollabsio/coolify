<?php

use App\Livewire\Project\Database\Sqlite\ConnectApplication;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneSqlite;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->resourceTeam = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->resourceTeam->members()->attach($this->user->id, ['role' => 'admin']);
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->resourceTeam]);

    $server = Server::factory()->create(['team_id' => $this->resourceTeam->id]);
    $this->destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));

    $this->sqlite = StandaloneSqlite::create([
        'name' => 'app-sqlite',
        'environment_id' => sqliteResourceTeamEnvironment($this->resourceTeam)->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->resourceTeamApplication = sqliteResourceTeamApplication(sqliteResourceTeamEnvironment($this->resourceTeam), $this->destination, 'Resource Team App');
    $this->otherTeamApplication = sqliteResourceTeamApplication(sqliteResourceTeamEnvironment($this->otherTeam), $this->destination, 'Other Team App');
});

function sqliteResourceTeamEnvironment(Team $team): Environment
{
    return Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
}

function sqliteResourceTeamApplication(Environment $environment, StandaloneDocker $destination, string $name): Application
{
    return Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
    ]);
}

it('offers only applications of the database team when the session team is another team', function () {
    session(['currentTeam' => $this->otherTeam]);

    Livewire::test(ConnectApplication::class, ['database' => $this->sqlite])
        ->assertSet('applicationOptions', fn (array $options): bool => collect($options)->pluck('value')->all() === [$this->resourceTeamApplication->uuid]);
});

it('connects an application of the database team after a session team switch', function () {
    $component = Livewire::test(ConnectApplication::class, ['database' => $this->sqlite]);
    session(['currentTeam' => $this->otherTeam]);

    $component->set('applicationUuid', $this->resourceTeamApplication->uuid)
        ->call('connect')
        ->assertNotDispatched('error')
        ->assertRedirect();

    expect($this->resourceTeamApplication->persistentStorages()->where('standalone_sqlite_id', $this->sqlite->id)->exists())->toBeTrue();
});

it('does not connect an application of another team after a session team switch', function () {
    $component = Livewire::test(ConnectApplication::class, ['database' => $this->sqlite]);
    session(['currentTeam' => $this->otherTeam]);

    $component->set('applicationUuid', $this->otherTeamApplication->uuid)
        ->call('connect')
        ->assertDispatched('error')
        ->assertNoRedirect();

    expect($this->otherTeamApplication->persistentStorages()->count())->toBe(0);
});

it('forbids a member of the database team from connecting after switching to a team they own', function () {
    $this->resourceTeam->members()->updateExistingPivot($this->user->id, ['role' => 'member']);
    $this->user->refresh();

    $component = Livewire::test(ConnectApplication::class, ['database' => $this->sqlite]);
    session(['currentTeam' => $this->otherTeam]);

    $component->set('applicationUuid', $this->resourceTeamApplication->uuid)
        ->call('connect')
        ->assertForbidden();

    expect($this->resourceTeamApplication->persistentStorages()->count())->toBe(0);
});
