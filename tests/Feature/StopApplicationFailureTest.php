<?php

use App\Actions\Application\StopApplication;
use App\Events\ApplicationStopFailed;
use App\Events\ServiceStatusChanged;
use App\Livewire\Project\Application\Heading;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Bus::fake();
    Event::fake([ServiceStatusChanged::class, ApplicationStopFailed::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false, 'docker_version' => '28.0.0']);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'running:healthy',
    ]);
});

/**
 * `docker ps` finds one running container; the stop commands give the result in $stopResult.
 */
function fakeRunningContainerWithStopResult(ProcessResult $stopResult): void
{
    Process::fake(function ($process) use ($stopResult) {
        if (str_contains($process->command, 'docker ps -a --filter')) {
            return Process::result(output: json_encode(['Names' => 'app-container', 'Labels' => '', 'State' => 'running']));
        }

        return str_contains($process->command, 'docker stop') ? $stopResult : Process::result(output: '');
    });
}

test('a stop that the server rejects keeps the status and reports the error', function () {
    fakeRunningContainerWithStopResult(Process::result(errorOutput: 'Error response from daemon: cannot stop container', exitCode: 1));

    $result = StopApplication::run($this->application, dockerCleanup: false);

    expect($result)->toContain('cannot stop container')
        ->and($this->application->fresh()->status)->toBe('running:healthy');
    Event::assertDispatched(ApplicationStopFailed::class, fn (ApplicationStopFailed $event) => $event->teamId === $this->team->id
        && $event->applicationUuid === $this->application->uuid
        && str_contains($event->message, 'cannot stop container'));
    Event::assertNotDispatched(ServiceStatusChanged::class);
});

test('a stop on a server that is not functional reports the error', function () {
    $this->server->settings->update(['is_reachable' => false]);
    Process::fake();

    StopApplication::run($this->application->fresh(), dockerCleanup: false);

    expect($this->application->fresh()->status)->toBe('running:healthy');
    Event::assertDispatched(ApplicationStopFailed::class, fn (ApplicationStopFailed $event) => str_contains($event->message, 'is not functional'));
    Process::assertNothingRan();
});

test('a successful stop marks the application exited and reports no error', function () {
    fakeRunningContainerWithStopResult(Process::result(output: 'app-container'));

    expect(StopApplication::run($this->application, dockerCleanup: false))->toBeNull()
        ->and($this->application->fresh()->status)->toStartWith('exited');
    Event::assertNotDispatched(ApplicationStopFailed::class);
    Event::assertDispatched(ServiceStatusChanged::class);
});

test('the application heading shows a stop failure only for its own application', function () {
    $user = User::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    Livewire::test(Heading::class, ['application' => $this->application])
        ->call('stopFailed', ['applicationUuid' => 'another-application', 'message' => 'Server is down.'])
        ->assertNotDispatched('error')
        ->call('stopFailed', ['applicationUuid' => $this->application->uuid, 'message' => 'Server is down.'])
        ->assertDispatched('error', 'Failed to stop the application.', 'Server is down.');
});
