<?php

use App\Livewire\Project\Application\Scaling;
use App\Livewire\Project\Shared\Destination;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->application = Application::factory()->create([
        'environment_id' => Environment::factory()->create(['project_id' => $project->id])->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ])->fresh();
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);
});

function additionalScalingServer(): Server
{
    $server = Server::factory()->create(['team_id' => test()->team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    return $server;
}

it('saves the replicas', function () {
    Livewire::test(Scaling::class, ['application' => $this->application])
        ->set('replicas', 3)
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->application->fresh()->replicas)->toBe(3);
});

it('does not let a member change the replicas', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Scaling::class, ['application' => $this->application])
        ->set('replicas', 3)
        ->call('submit')
        ->assertForbidden();

    expect($this->application->fresh()->replicas)->toBe(1);
});

it('does not save replicas together with a setting that needs one container', function (Closure $change, string $reason) {
    $change($this->application);

    Livewire::test(Scaling::class, ['application' => $this->application->fresh()])
        ->assertSee($reason)
        ->set('replicas', 2)
        ->call('submit')
        ->assertDispatched('error');

    expect($this->application->fresh()->replicas)->toBe(1);
})->with([
    'additional server' => [function (Application $application) {
        $server = additionalScalingServer();
        $application->additional_networks()->attach($server->standaloneDockers()->firstOrFail()->id, ['server_id' => $server->id]);
    }, 'Remove the additional servers'],
    'host ports' => [fn (Application $application) => $application->update(['ports_mappings' => '8080:80']), 'Remove the port mappings'],
    'consistent container name' => [fn (Application $application) => $application->settings->update(['is_consistent_container_name_enabled' => true]), 'consistent container name'],
    'custom IP address' => [fn (Application $application) => $application->update(['custom_docker_run_options' => '--ip 10.0.0.2']), 'custom IP address'],
    'Docker Compose' => [fn (Application $application) => $application->update(['build_pack' => 'dockercompose']), 'in the Compose file'],
]);

it('does not add a server to an application with replicas', function () {
    $this->application->update(['replicas' => 2]);
    $server = additionalScalingServer();

    Livewire::test(Destination::class, ['resource' => $this->application->fresh()])
        ->call('addServer', $server->standaloneDockers()->firstOrFail()->id, $server->id)
        ->assertDispatched('error');

    expect($this->application->fresh()->additional_networks()->count())->toBe(0);
});
