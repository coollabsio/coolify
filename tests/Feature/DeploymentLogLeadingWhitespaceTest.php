<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Application\Deployment\Show;
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

    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-whitespace-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => json_encode([
            [
                'command' => null,
                'output' => "    indented once\n        indented twice\nno indent",
                'type' => 'stdout',
                'timestamp' => now()->toISOString(),
                'hidden' => false,
                'batch' => 1,
                'order' => 1,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);
});

it('preserves leading whitespace when rendering deployment logs', function () {
    $response = $this->get(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $this->deployment->deployment_uuid,
    ]));

    $response->assertSuccessful();
    expect($response->getContent())
        ->toContain('    indented once')
        ->and($response->getContent())
        ->toContain('        indented twice')
        ->and($response->getContent())
        ->toContain('no indent');
});

it('preserves leading whitespace in copyLogs and downloadAllLogs', function () {
    $component = new Show;
    $component->application = $this->application;
    $component->application_deployment_queue = $this->deployment;

    $copied = $component->copyLogs();
    $downloaded = $component->downloadAllLogs();

    expect($copied)
        ->toContain('    indented once')
        ->toContain('        indented twice')
        ->toContain('no indent')
        ->and($downloaded)
        ->toContain('    indented once')
        ->toContain('        indented twice')
        ->toContain('no indent');
});
