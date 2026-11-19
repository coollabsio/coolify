<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Application\Deployment\Show;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

it('renders deployment log output', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-layout-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => json_encode([
            [
                'command' => null,
                'output' => 'rolling update started',
                'type' => 'stdout',
                'timestamp' => now()->toISOString(),
                'hidden' => false,
                'batch' => 1,
                'order' => 1,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->get(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('rolling update started');
});

it('links deployment commit hashes to the source commit page', function () {
    $githubApp = GithubApp::query()->create([
        'team_id' => $this->team->id,
        'name' => 'GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);
    $this->application->update([
        'source_id' => $githubApp->id,
        'source_type' => $githubApp->getMorphClass(),
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-commit-link-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => '1234567890abcdef1234567890abcdef12345678',
    ]);

    $response = $this->get(route('project.application.deployment.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee(
        'href="https://github.com/coollabsio/coolify/commit/1234567890abcdef1234567890abcdef12345678" target="_blank" rel="noopener noreferrer"',
        false,
    );
});

it('swaps the logs viewer to another deployment of the same application only', function () {
    $makeDeployment = fn (Application $application, string $uuid) => ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => $uuid,
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $first = $makeDeployment($this->application, 'deploy-first');
    $second = $makeDeployment($this->application, 'deploy-second');
    $otherApplication = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $foreign = $makeDeployment($otherApplication, 'deploy-foreign');

    Livewire::test(Show::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $first->deployment_uuid,
    ])
        ->assertSet('deployment_uuid', 'deploy-first')
        ->call('selectDeployment', $second->deployment_uuid)
        ->assertSet('deployment_uuid', 'deploy-second')
        ->assertSet('application_deployment_queue.id', $second->id)
        ->assertDispatched('deploymentSelected', deploymentUuid: 'deploy-second')
        ->call('selectDeployment', $foreign->deployment_uuid)
        ->assertSet('deployment_uuid', 'deploy-second');
});

it('exports the displayed deployment logs with secrets hidden and the full log with debug lines', function () {
    EnvironmentVariable::query()->create([
        'key' => 'DEPLOY_SECRET',
        'value' => 'deploy-export-canary',
        'resourceable_type' => $this->application->getMorphClass(),
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
        'is_shown_once' => true,
    ]);
    $line = fn (string $output, int $order, bool $hidden = false) => [
        'command' => null,
        'output' => $output,
        'type' => 'stdout',
        'timestamp' => now()->toISOString(),
        'hidden' => $hidden,
        'batch' => 1,
        'order' => $order,
    ];
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-export',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => json_encode([
            $line('build uses deploy-export-canary', 1),
            $line('pushing image', 2),
            $line('internal debug step', 3, hidden: true),
        ], JSON_THROW_ON_ERROR),
    ]);

    $component = Livewire::test(Show::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]);

    $visible = $component->instance()->exportLogs(withTimestamps: false);
    expect($visible)->toContain('build uses')
        ->toContain('pushing image')
        ->not->toContain('deploy-export-canary')
        ->not->toContain('internal debug step');

    expect($component->instance()->exportLogs(withTimestamps: false, search: 'PUSHING'))->toBe('pushing image');

    $full = $component->instance()->downloadAllLogs();
    expect($full)->toContain('[DEBUG] internal debug step')
        ->not->toContain('deploy-export-canary');
});
