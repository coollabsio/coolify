<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\DeploymentNavbar;
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
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake([ApplicationDeploymentJob::class]);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create(['id' => 0]);
    });

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update(['concurrent_builds' => 0]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'navbar-force-start',
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
});

test('force start starts a queued deployment once even when clicked twice', function () {
    $component = Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment]);

    $component->call('force_start')->assertNotDispatched('info');
    $component->call('force_start')->assertDispatched('info', 'This deployment is no longer queued, so it was not started again.');

    expect($this->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 1);
});

test('force start from two open pages starts the deployment once', function () {
    $firstAdmin = Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment]);
    $secondAdmin = Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment]);

    $firstAdmin->call('force_start');
    $secondAdmin->call('force_start')->assertDispatched('info');

    Bus::assertDispatchedTimes(ApplicationDeploymentJob::class, 1);
});

test('members cannot force start a deployment', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment])
        ->call('force_start');

    expect($this->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::QUEUED->value);
    Bus::assertNotDispatched(ApplicationDeploymentJob::class);
});
