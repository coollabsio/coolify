<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);
});

function showDeployment(string $status): TestResponse
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => 'deploy-scroll-'.$status,
        'server_id' => test()->server->id,
        'status' => $status,
        'logs' => json_encode([[
            'command' => null,
            'output' => 'log line for '.$status,
            'type' => 'stdout',
            'timestamp' => now()->toISOString(),
            'hidden' => false,
            'batch' => 1,
            'order' => 1,
        ]], JSON_THROW_ON_ERROR),
    ]);

    return test()->get(route('project.application.deployment.show', [
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'application_uuid' => test()->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]));
}

it('does not enable follow mode for a finished deployment', function () {
    $response = showDeployment(ApplicationDeploymentStatus::FINISHED->value);

    $response->assertSuccessful();
    $response->assertSee('alwaysScroll: false', false);
    $response->assertDontSee('alwaysScroll: true', false);
});

it('enables follow mode for an in-progress deployment', function () {
    $response = showDeployment(ApplicationDeploymentStatus::IN_PROGRESS->value);

    $response->assertSuccessful();
    $response->assertSee('alwaysScroll: true', false);
});

it('scopes scroll teardown to the component so a stale loop cannot leak across deployments', function () {
    $content = showDeployment(ApplicationDeploymentStatus::FINISHED->value)->getContent();

    // Alpine destroy() tears the scroll loop down on wire:navigate away.
    expect($content)->toContain('destroy()')
        ->toContain('cancelScrollLoop()')
        // Container lookup is component-scoped, not a global getElementById.
        ->toContain("this.\$root.querySelector('#logsContainer')")
        ->not->toContain("document.getElementById('logsContainer')")
        // morph.updated hook only acts on this component's own DOM.
        ->toContain('this.$root.contains(el)')
        // Global Livewire hook is unregistered when Alpine tears down.
        ->toContain('morphUpdatedCleanup: null')
        ->toContain("this.morphUpdatedCleanup = Livewire.hook('morph.updated'")
        ->toContain("typeof this.morphUpdatedCleanup === 'function'")
        ->toContain('this.morphUpdatedCleanup()')
        // Continuation timeout is tracked so it can be cancelled.
        ->toContain('scrollTimeout');
});
