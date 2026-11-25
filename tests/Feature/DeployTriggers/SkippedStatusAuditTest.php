<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\SyncGithubDeploymentJob;
use App\Livewire\Dashboard\ActiveDeployments;
use App\Livewire\Project\Application\Deployment\Index;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fixture = deployTriggerFixture();
    $this->application = deployTriggerApplication($this->fixture, ['name' => 'web-app']);
    $this->withoutVite();
    $this->actingAs($this->fixture['user']);
    session(['currentTeam' => $this->fixture['team']]);
});

function auditDeployment(Application $application, string $status, string $uuid): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'deployment_uuid' => $uuid,
        'deployment_url' => '/deployments',
        'server_id' => $application->destination->server->id,
        'server_name' => $application->destination->server->name,
        'status' => $status,
        'commit' => str_repeat('c', 40),
    ]);
}

it('leaves skipped rows out of the dashboard recent deployments', function () {
    auditDeployment($this->application, ApplicationDeploymentStatus::FINISHED->value, 'finished-row');
    auditDeployment($this->application, ApplicationDeploymentStatus::SKIPPED->value, 'skipped-row');

    $recent = Livewire::test(ActiveDeployments::class)->instance()->recentDeployments();

    expect($recent->pluck('deployment_uuid')->all())->toBe(['finished-row']);
});

it('leaves skipped rows out of the global deployments page', function () {
    auditDeployment($this->application, ApplicationDeploymentStatus::FINISHED->value, 'finished-row');
    auditDeployment($this->application, ApplicationDeploymentStatus::SKIPPED->value, 'skipped-row');

    $this->get('/deployments')
        ->assertOk()
        ->assertSee('finished-row')
        ->assertDontSee('skipped-row');
});

it('does not count skipped rows as recent deployments', function () {
    auditDeployment($this->application, ApplicationDeploymentStatus::SKIPPED->value, 'skipped-row');

    expect($this->application->hasDeploymentsInLastDays())->toBeFalse();
});

it('maps skipped to the inactive github deployment state', function () {
    $job = (new ReflectionClass(SyncGithubDeploymentJob::class))->newInstanceWithoutConstructor();
    $state = (fn (string $status) => $this->githubState($status))->call($job, ApplicationDeploymentStatus::SKIPPED->value);

    expect($state)->toBe('inactive');
});

it('labels skipped deployments in the application deployment filters', function () {
    auditDeployment($this->application, ApplicationDeploymentStatus::SKIPPED->value, 'skipped-row');

    $component = Livewire::test(Index::class, [
        'project_uuid' => $this->fixture['environment']->project->uuid,
        'environment_uuid' => $this->fixture['environment']->uuid,
        'application_uuid' => $this->application->uuid,
    ]);

    expect(collect($component->get('statusFilterOptions'))->pluck('label')->all())->toContain('Skipped');
});
