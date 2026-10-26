<?php

use App\Livewire\Project\New\SimpleDockerfile;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('a new application from the create form uses build secrets', function () {
    $routeParams = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ];
    request()->headers->set('referer', route('project.resource.create', $routeParams).'?destination='.$this->destination->uuid);

    Livewire::withUrlParams(['destination' => $this->destination->uuid])
        ->test(SimpleDockerfile::class, $routeParams)
        ->set('parameters', $routeParams)
        ->set('dockerfile', "FROM nginx\nEXPOSE 80\n")
        ->call('submit');

    $application = Application::query()->where('environment_id', $this->environment->id)->sole();

    expect($application->settings->use_build_secrets)->toBeTrue();
});

test('a cloned application keeps the build secrets setting of its source', function (bool $sourceValue) {
    $source = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $source->settings->update(['use_build_secrets' => $sourceValue]);

    $clone = clone_application($source->fresh(), $this->destination, [
        'environment_id' => $this->environment->id,
    ]);

    expect($clone->fresh()->settings->use_build_secrets)->toBe($sourceValue);
})->with([
    'off' => false,
    'on' => true,
]);
