<?php

use App\Actions\Application\StopApplication;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->fill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'restart_count' => 10,
        'max_restart_count' => 10,
        'last_restart_type' => 'crash',
        'last_restart_at' => now(),
    ]);
});

function fakeContainerDiscoveryAndLogs(Application $application, string $containerName, string $crashOutput): void
{
    Process::fake([
        '*docker ps*' => Process::result(output: json_encode([
            'Names' => $containerName,
            'Labels' => "coolify.applicationId={$application->id}",
        ])),
        '*docker logs*' => Process::result(output: $crashOutput),
        '*docker stop*' => Process::result(exitCode: 0),
    ]);
}

test('StopApplication captures crash logs before removing the container on a crash-triggered stop', function () {
    fakeContainerDiscoveryAndLogs($this->application, 'app-container', "panic: something broke\nexit status 1");

    StopApplication::run($this->application, false, false, false);

    $this->application->refresh();

    expect($this->application->last_crash_logs)->toBe(['app-container' => "panic: something broke\nexit status 1"]);
    expect($this->application->last_crash_logs_captured_at)->not->toBeNull();
    expect($this->application->status)->toBe('exited');
});

test('StopApplication clears crash logs on a normal reset stop', function () {
    $this->application->update([
        'last_crash_logs' => ['app-container' => 'stale crash output'],
        'last_crash_logs_captured_at' => now()->subDay(),
    ]);

    fakeContainerDiscoveryAndLogs($this->application, 'app-container', 'should not be captured');

    StopApplication::run($this->application, false, false, true);

    $this->application->refresh();

    expect($this->application->last_crash_logs)->toBeNull();
    expect($this->application->last_crash_logs_captured_at)->toBeNull();
    expect($this->application->restart_count)->toBe(0);
});
