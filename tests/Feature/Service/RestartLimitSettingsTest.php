<?php

use App\Livewire\Project\Service\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->serviceApplication = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'worker',
        'service_id' => $service->id,
        'image' => 'example/worker:latest',
        'max_restart_count' => 10,
    ]);
});

it('shows and saves the restart limit for a compose application', function () {
    $this->serviceApplication->update(['restart_limit_reached' => true]);

    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication->fresh()])
        ->assertSet('maxRestartCount', 10)
        ->set('currentRoute', 'project.service.index')
        ->assertSee('Max restart count')
        ->assertSee('Set to 0 to disable the limit.')
        ->set('maxRestartCount', 0)
        ->call('saveMaxRestartCount')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->serviceApplication->fresh())
        ->max_restart_count->toBe(0)
        ->restart_limit_reached->toBeFalse();
});

it('validates the restart limit', function (mixed $value) {
    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication])
        ->set('maxRestartCount', $value)
        ->call('saveMaxRestartCount')
        ->assertHasErrors('maxRestartCount');
})->with([
    'negative' => -1,
    'decimal' => 1.5,
    'text' => 'unlimited',
]);

it('does not let a team member change the restart limit', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(Index::class, ['serviceApplication' => $this->serviceApplication])
        ->set('maxRestartCount', 0)
        ->call('saveMaxRestartCount')
        ->assertForbidden();

    expect($this->serviceApplication->fresh()->max_restart_count)->toBe(10);
});
