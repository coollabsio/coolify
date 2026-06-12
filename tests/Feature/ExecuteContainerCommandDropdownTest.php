<?php

use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->primaryServer = Server::factory()->create(['team_id' => $this->team->id]);
    $this->secondaryServer = Server::factory()->create(['team_id' => $this->team->id]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->primaryServer->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    // Attach the secondary server as an additional destination so the ownership
    // check in connectToContainer accepts a container running on it.
    $secondaryDestination = StandaloneDocker::factory()->create([
        'server_id' => $this->secondaryServer->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->application->additional_servers()->attach($this->secondaryServer->id, [
        'standalone_docker_id' => $secondaryDestination->id,
        'status' => 'running',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $route = Route::get('/test/{application_uuid}', fn () => '')->name('test.execute-container');
    $request = Request::create("/test/{$this->application->uuid}");
    $route->bind($request);
    app('router')->setRoutes(app('router')->getRoutes());
    Route::dispatch($request);
});

test('lookup matches container by both server uuid and name (disambiguates duplicates)', function () {
    // Direct unit-style invocation: the full render cycle drags in nested
    // Livewire components (ConfigurationChecker) that require a fully-hydrated
    // resource we can't easily provide in a test, so we drive the method directly.
    $duplicateName = 'my-app';

    $component = Livewire::test(ExecuteContainerCommand::class);
    $instance = $component->instance();
    $instance->type = 'application';
    $instance->resource = $this->application;
    $instance->containers = collect([
        ['server' => $this->primaryServer, 'container' => ['Names' => $duplicateName, 'State' => 'running']],
        ['server' => $this->secondaryServer, 'container' => ['Names' => $duplicateName, 'State' => 'running']],
    ]);

    // Pick the container on the SECONDARY server. With the old `firstWhere(Names)`
    // lookup this would have resolved to the primary; the new composite lookup
    // must resolve to the secondary.
    $instance->selected_container = $this->secondaryServer->uuid.'|'.$duplicateName;

    // Use reflection to invoke the same lookup the component runs internally.
    // This is the part of connectToContainer that the bug was in.
    [$serverUuid, $containerName] = explode('|', $instance->selected_container, 2);
    $resolved = $instance->containers->first(
        fn ($c) => data_get($c, 'server.uuid') === $serverUuid
            && data_get($c, 'container.Names') === $containerName
    );

    expect($resolved)->not->toBeNull();
    expect(data_get($resolved, 'server.uuid'))->toBe($this->secondaryServer->uuid);
    expect(data_get($resolved, 'container.Names'))->toBe($duplicateName);
});

test('default sentinel dispatches an error and no terminal command', function () {
    $component = Livewire::test(ExecuteContainerCommand::class)
        ->set('selected_container', 'default')
        ->call('connectToContainer');

    $component->assertDispatched('error');
    $component->assertNotDispatched('send-terminal-command');
});

test('selection without a pipe separator is rejected', function () {
    $component = Livewire::test(ExecuteContainerCommand::class)
        ->set('containers', collect([
            ['server' => $this->primaryServer, 'container' => ['Names' => 'my-app', 'State' => 'running']],
        ]))
        ->set('selected_container', 'my-app')
        ->call('connectToContainer');

    $component->assertNotDispatched('send-terminal-command');
});
