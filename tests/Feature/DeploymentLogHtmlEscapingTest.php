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

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-html-escape-test',
        'server_id' => $server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => json_encode([[
            'command' => null,
            'output' => '"><img src=x onerror=alert(1)>',
            'type' => 'stdout',
            'timestamp' => now()->toISOString(),
            'hidden' => false,
            'batch' => 1,
            'order' => 1,
        ]], JSON_THROW_ON_ERROR),
    ]);
});

it('escapes deployment log lines exactly once in the log viewer', function () {
    $response = $this->get(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $this->deployment->deployment_uuid,
    ]));

    $escaped = '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;';

    $response->assertSuccessful();
    $html = $response->getContent();

    expect($html)
        ->toContain('data-line-text="'.$escaped.'"')
        ->toContain('>'.$escaped.'</span>')
        ->toMatch('/data-log-content="[^"]* '.preg_quote($escaped, '/').'"/')
        ->not->toContain('<img src=x onerror=alert(1)>')
        ->not->toContain('&amp;quot;&amp;gt;&amp;lt;img');
});
