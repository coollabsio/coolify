<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->createApplication = fn (array $attributes = []) => Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n  db:\n    image: postgres:16\n",
        'environment_id' => $project->environments()->firstOrFail()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        ...$attributes,
    ])->fresh();
    $this->application = ($this->createApplication)();
    EnvironmentVariable::query()->delete();

    $this->createVariable = fn (array $attributes = [], ?Application $application = null) => EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'value',
        'resourceable_type' => Application::class,
        'resourceable_id' => ($application ?? $this->application)->id,
        ...$attributes,
    ]);
    $this->row = fn (EnvironmentVariable $environmentVariable) => Livewire::test(Show::class, ['env' => $environmentVariable, 'type' => 'application']);
    $this->devView = fn (?Application $application = null) => Livewire::test(All::class, ['resource' => $application ?? $this->application])
        ->call('loadEnvironmentVariables')
        ->call('switch');

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('saves the containers of a Compose application variable', function () {
    $environmentVariable = ($this->createVariable)();

    ($this->row)($environmentVariable)
        ->assertSee('Containers')
        ->assertSee('All containers')
        ->set('allContainers', false)
        ->set('compose_services', ['app', 'db'])
        ->call('submit')
        ->assertHasNoErrors();

    expect($environmentVariable->fresh()->compose_services)->toBe(['app', 'db']);

    ($this->row)($environmentVariable->fresh())
        ->assertSet('allContainers', false)
        ->set('allContainers', true)
        ->call('submit')
        ->assertHasNoErrors();

    expect($environmentVariable->fresh()->compose_services)->toBeNull();
});

it('needs at least one container when not all containers get the variable', function () {
    $environmentVariable = ($this->createVariable)();

    ($this->row)($environmentVariable)
        ->set('allContainers', false)
        ->set('compose_services', [])
        ->call('submit')
        ->assertHasErrors(['compose_services' => 'Select at least one container, or select All containers.']);

    expect($environmentVariable->fresh()->compose_services)->toBeNull();
});

it('rejects a container that is not in the Compose file', function () {
    $environmentVariable = ($this->createVariable)();

    ($this->row)($environmentVariable)
        ->set('allContainers', false)
        ->set('compose_services', ['app', 'worker'])
        ->call('submit')
        ->assertHasErrors(['compose_services.1' => 'The container "worker" is not in the Compose file.']);

    expect($environmentVariable->fresh()->compose_services)->toBeNull();
});

it('warns about saved containers that left the Compose file and keeps them on save', function () {
    $environmentVariable = ($this->createVariable)(['compose_services' => ['app', 'worker']]);

    ($this->row)($environmentVariable)
        ->assertSee('Not used by: worker (not in the Compose file).')
        ->set('comment', 'changed')
        ->call('submit')
        ->assertHasNoErrors();

    expect($environmentVariable->fresh()->compose_services)->toBe(['app', 'worker']);

    $environmentVariable->update(['compose_services' => ['worker']]);

    ($this->row)($environmentVariable->fresh())
        ->assertSee('No container gets this variable: worker is not in the Compose file.');
});

it('round trips the containers flag in the developer view', function () {
    $environmentVariable = ($this->createVariable)(['compose_services' => ['db']]);

    $component = ($this->devView)();
    expect($component->get('variables'))->toBe("# coolify: containers=db\nAPI_KEY=value");

    $component->call('submit')->assertNotDispatched('error');
    expect($environmentVariable->fresh()->compose_services)->toBe(['db']);

    $component->set('variables', "# coolify: literal containers=app,db\nAPI_KEY=value")->call('submit')->assertNotDispatched('error');
    expect($environmentVariable->fresh()->only(['is_literal', 'compose_services']))->toBe(['is_literal' => true, 'compose_services' => ['app', 'db']]);

    $component->set('variables', 'API_KEY=value')->call('submit')->assertNotDispatched('error');
    expect($environmentVariable->fresh()->compose_services)->toBe(['app', 'db']);

    $component->set('variables', "# coolify: literal\nAPI_KEY=value")->call('submit')->assertNotDispatched('error');
    expect($environmentVariable->fresh()->compose_services)->toBeNull();
});

it('rejects an unknown container in the developer view and changes nothing', function () {
    $environmentVariable = ($this->createVariable)();

    ($this->devView)()
        ->set('variables', "# coolify: containers=app,worker\nAPI_KEY=changed")
        ->call('submit')
        ->assertDispatched('error', 'API_KEY: unknown container "worker". Containers in the Compose file: app, db.');

    expect($environmentVariable->fresh()->only(['value', 'compose_services']))->toBe(['value' => 'value', 'compose_services' => null]);
});

it('has no containers picker or flag for a non-Compose application', function () {
    $application = ($this->createApplication)(['build_pack' => 'nixpacks']);
    $environmentVariable = ($this->createVariable)([], $application);

    ($this->row)($environmentVariable)
        ->assertDontSee('All containers');

    ($this->devView)($application)
        ->set('variables', "# coolify: containers=app\nAPI_KEY=value")
        ->call('submit')
        ->assertDispatched('error', 'Line 1: unknown flag "containers". Allowed flags: literal, buildtime, runtime.');
});

it('shows the containers of a scoped variable in the variable list', function () {
    ($this->createVariable)(['compose_services' => ['db']]);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->assertSeeHtml('title="Only these containers get this variable: db">db</span>');
});
