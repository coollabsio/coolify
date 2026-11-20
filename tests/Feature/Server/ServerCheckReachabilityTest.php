<?php

use App\Enums\ServerRole;
use App\Jobs\ServerCheckJob;
use App\Jobs\ServerCloudProviderStatusCheckJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Jobs\ServerPatchCheckJob;
use App\Models\Application;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['constants.coolify.self_hosted' => true]);
    Carbon::setTestNow('2026-07-11 12:00:00');
    InstanceSettings::forceCreate(['id' => 0, 'instance_timezone' => 'UTC']);
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update([
        'server_timezone' => 'UTC',
        'server_role' => ServerRole::BOTH,
        'is_reachable' => true,
        'is_usable' => true,
    ]);
    $this->server->refresh();
});

afterEach(function () {
    Carbon::setTestNow();
});

function createServerCheckReachabilityApplication(Server $server): Application
{
    $project = Project::factory()->create(['team_id' => $server->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = StandaloneDocker::factory()->create([
        'server_id' => $server->id,
        'name' => 'dest-'.fake()->unique()->word(),
        'network' => 'net-'.fake()->unique()->word(),
    ]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'running:healthy',
    ]);
}

it('marks resources exited on an unreachable server without an SSH probe', function () {
    Process::fake();
    $application = createServerCheckReachabilityApplication($this->server);
    $this->server->settings->update(['is_reachable' => false, 'is_usable' => false]);

    (new ServerCheckJob($this->server->refresh()))->handle();

    Process::assertNothingRan();
    expect($application->refresh()->status)->toStartWith('exited');
});

it('does not mark a reachable server unreachable when it is not usable', function () {
    Process::fake();
    $application = createServerCheckReachabilityApplication($this->server);
    $this->server->settings->update(['is_usable' => false]);

    (new ServerCheckJob($this->server->refresh()))->handle();

    Process::assertNothingRan();
    expect($application->refresh()->status)->toBe('running:healthy')
        ->and($this->server->settings->refresh()->is_reachable)->toBeTrue();
});

it('skips the patch check on a server that is not functional without an SSH probe', function () {
    Process::fake();
    $this->server->settings->update(['is_reachable' => false, 'is_usable' => false]);

    (new ServerPatchCheckJob($this->server->refresh()))->handle();

    Process::assertNothingRan();
});

it('does not dispatch ServerCheckJob for build servers and Swarm workers', function (array $settings) {
    Queue::fake();
    $this->server->settings->update($settings);

    (new ServerManagerJob)->handle();

    Queue::assertNotPushed(ServerCheckJob::class);
})->with([
    'build server' => [['server_role' => ServerRole::BUILD]],
    'swarm worker' => [['is_swarm_worker' => true]],
]);

it('dispatches ServerCheckJob for a deployment server when Sentinel is out of sync', function () {
    Queue::fake();

    (new ServerManagerJob)->handle();

    Queue::assertPushed(ServerCheckJob::class, fn (ServerCheckJob $job) => $job->server->is($this->server));
});

it('checks the cloud provider status every five minutes', function () {
    Queue::fake();
    $token = CloudProviderToken::create([
        'team_id' => $this->team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Hetzner',
    ]);
    $this->server->update(['cloud_provider_token_id' => $token->id, 'hetzner_server_id' => 123]);

    $pushedAt = collect(['12:00', '12:01', '12:04', '12:05'])->filter(function (string $time) {
        Carbon::setTestNow("2026-07-11 {$time}:00");
        Server::flushIdentityMap();
        $before = Queue::pushed(ServerCloudProviderStatusCheckJob::class)->count();

        (new ServerManagerJob)->handle();

        return Queue::pushed(ServerCloudProviderStatusCheckJob::class)->count() > $before;
    })->values()->all();

    expect($pushedAt)->toBe(['12:00', '12:05']);
});

it('does not disable SSH multiplexing for later jobs in the same worker', function () {
    Process::fake();
    config(['constants.ssh.mux_enabled' => true]);

    (new ServerConnectionCheckJob($this->server))->handle();
    $this->server->validateConnection();

    expect(config('constants.ssh.mux_enabled'))->toBeTrue();
});
