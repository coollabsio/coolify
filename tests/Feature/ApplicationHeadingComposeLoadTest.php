<?php

use App\Livewire\Project\Application\Heading;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $keyId]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));
    $project = Project::create(['uuid' => (string) Str::uuid(), 'name' => 'Test Project', 'team_id' => $this->team->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $project->environments()->first()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => null,
        'status' => 'exited',
    ]);

    session(['currentTeam' => $this->team]);
});

test('an admin opening the general page of a compose application without a compose file loads it automatically', function () {
    $this->actingAs($this->admin);

    Livewire::test(Heading::class, ['application' => $this->application, 'activeRouteName' => 'project.application.configuration'])
        ->assertSet('loadComposeOnOpen', true)
        ->assertSee('Load compose')
        ->assertSee('Load the Compose file before you deploy.')
        ->assertDontSee('Load a Compose file to deploy.');
});

test('other application pages show the load button but do not load the compose file', function () {
    $this->actingAs($this->admin);

    Livewire::test(Heading::class, ['application' => $this->application, 'activeRouteName' => 'project.application.danger'])
        ->assertSet('loadComposeOnOpen', false)
        ->assertSee('Load compose')
        ->assertSee('Load the Compose file before you deploy.');
});

test('a compose application that already has a compose file does not load it on open', function () {
    $this->actingAs($this->admin);
    $this->application->update(['docker_compose_raw' => "services:\n  web:\n    image: nginx\n"]);

    Livewire::test(Heading::class, ['application' => $this->application, 'activeRouteName' => 'project.application.configuration'])
        ->assertSet('loadComposeOnOpen', false)
        ->assertDontSee('Load compose');
});

test('the automatic load is skipped when the compose file is already loaded', function () {
    $this->actingAs($this->admin);
    $component = Livewire::test(Heading::class, ['application' => $this->application]);

    $this->application->update(['docker_compose_raw' => "services:\n  web:\n    image: nginx\n"]);

    $component->call('loadComposeFile', true)
        ->assertNotDispatched('composeFileLoaded')
        ->assertNotDispatched('error');
});

test('a member cannot load the compose file and does not load it on open', function () {
    $this->actingAs($this->member);

    Livewire::test(Heading::class, ['application' => $this->application, 'activeRouteName' => 'project.application.configuration'])
        ->assertSet('loadComposeOnOpen', false)
        ->assertDontSee('Load compose')
        ->call('loadComposeFile')
        ->assertDispatched('error')
        ->assertNotDispatched('composeFileLoaded');

    expect($this->application->refresh()->docker_compose_raw)->toBeNull();
});
