<?php

use App\Jobs\DeleteResourceJob;
use App\Livewire\Project\Shared\Danger;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Queue::fake();

    $this->user = User::factory()->create([
        'password' => Hash::make('test-password'),
    ]);
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // Bind route parameters so get_route_parameters() works in the Danger component
    $route = Route::get('/test/{project_uuid}/{environment_uuid}', fn () => '')->name('test.danger');
    $request = Request::create("/test/{$this->project->uuid}/{$this->environment->uuid}");
    $route->bind($request);
    app('router')->setRoutes(app('router')->getRoutes());
    Route::dispatch($request);
});

test('delete returns error string when password is incorrect', function () {
    Livewire::test(Danger::class, ['resource' => $this->application])
        ->call('delete', 'wrong-password')
        ->assertReturned('The provided password is incorrect.');

    // Resource should NOT be deleted
    expect(Application::find($this->application->id))->not->toBeNull();
});

test('delete redirects before dispatching resource cleanup after the response', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $component = Livewire::test(Danger::class, ['resource' => $service])
        ->set('projectUuid', $this->project->uuid)
        ->set('environmentUuid', $this->environment->uuid)
        ->call('delete', 'test-password')
        ->assertHasNoErrors()
        ->assertRedirectToRoute('project.resource.index', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ]);

    expect($component->effects)->toHaveKey('redirectUsingNavigate', true);

    expect(Service::find($service->id))->not->toBeNull();
    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($service));
});

test('delete applies selectedActions from checkbox state', function () {
    $component = Livewire::test(Danger::class, ['resource' => $this->application])
        ->call('delete', 'test-password', ['delete_configurations', 'docker_cleanup']);

    expect($component->get('delete_volumes'))->toBeFalse();
    expect($component->get('delete_connected_networks'))->toBeFalse();
    expect($component->get('delete_configurations'))->toBeTrue();
    expect($component->get('docker_cleanup'))->toBeTrue();
});
