<?php

use App\Livewire\Project\New\DockerCompose;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->routeParams = [
        'project_uuid' => $project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];

    $this->actingAs($user);
    session(['currentTeam' => $team]);
});

function newDockerComposeComponent(): mixed
{
    return Livewire::withUrlParams(['destination' => test()->destination->uuid])
        ->test(DockerCompose::class, test()->routeParams)
        ->set('parameters', test()->routeParams)
        ->set('query', ['destination' => test()->destination->uuid]);
}

test('repeated submits create only one service', function () {
    $component = newDockerComposeComponent()
        ->set('dockerComposeRaw', "services:\n  nginx:\n    image: nginx:alpine\n");

    $component->call('submit')->assertHasNoErrors()->assertRedirect();
    $component->call('submit')->assertHasNoErrors()->assertRedirect();
    $component->call('submit')->assertHasNoErrors()->assertRedirect();

    $services = Service::where('environment_id', $this->environment->id)->get();
    expect($services)->toHaveCount(1)
        ->and($services->first()->applications()->pluck('name')->all())->toBe(['nginx']);
});

test('compose without services creates no service', function (string $compose) {
    newDockerComposeComponent()
        ->set('dockerComposeRaw', $compose)
        ->call('submit')
        ->assertNoRedirect();

    expect(Service::count())->toBe(0);
})->with([
    'no services key' => "volumes:\n  data: {}\n",
    'empty services' => "services: {}\n",
    'services is a list' => "services:\n  - nginx\n",
]);
