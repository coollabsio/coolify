<?php

use App\Enums\ApplicationDeploymentStatus;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->ownTeam = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
    $this->ownTeam->members()->attach($this->member->id, ['role' => 'owner']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->application->settings->update(['is_debug_enabled' => true]);

    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'debug-visibility-uuid',
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    $this->deployment->addLogEntry('visible-line');
    $this->deployment->addLogEntry('hidden-debug-line', hidden: true);
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('deployment log lines hide debug output from a member whose session is on a team they own', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->ownTeam]);

    $lines = decode_remote_command_output($this->deployment->fresh())->pluck('line')->implode("\n");

    expect($lines)->toContain('visible-line')
        ->not->toContain('hidden-debug-line');
});

test('deployment log lines show debug output to a resource team admin whose session is on another team', function () {
    $this->team->members()->updateExistingPivot($this->member->id, ['role' => 'admin']);
    $this->ownTeam->members()->updateExistingPivot($this->member->id, ['role' => 'member']);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->ownTeam]);

    $lines = decode_remote_command_output($this->deployment->fresh())->pluck('line')->implode("\n");

    expect($lines)->toContain('hidden-debug-line');
});

test('copied deployment logs hide debug output from a member after switching to a team they own', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment]);

    session(['currentTeam' => $this->ownTeam]);

    $markdown = $component->instance()->copyLogsToClipboard();

    expect($markdown)->toContain('visible-line')
        ->not->toContain('hidden-debug-line');
});
