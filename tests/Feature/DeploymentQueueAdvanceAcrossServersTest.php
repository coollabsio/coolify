<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\DeploymentNavbar;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    Notification::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Process::fake(['*' => Process::result(output: '')]);
    config([
        'constants.ssh.mux_enabled' => false,
        'cache.default' => 'array',
        'app.maintenance.store' => 'array',
    ]);

    $settings = new InstanceSettings(['is_api_enabled' => true, 'is_mcp_server_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $makeServer = function (string $ip) use ($privateKey): Server {
        $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => $ip]);
        $server->settings->update(['concurrent_builds' => 1]);

        return $server;
    };
    $this->primaryServer = $makeServer('203.0.113.31');
    $this->additionalServer = $makeServer('203.0.113.32');
    $this->deletedServer = $makeServer('203.0.113.33');

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $primaryDestination = StandaloneDocker::where('server_id', $this->primaryServer->id)->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $primaryDestination->id,
        'destination_type' => $primaryDestination->getMorphClass(),
    ]);
    foreach ([$this->additionalServer, $this->deletedServer] as $server) {
        $this->application->additional_servers()->attach($server->id, [
            'standalone_docker_id' => StandaloneDocker::where('server_id', $server->id)->firstOrFail()->id,
        ]);
    }

    $this->makeQueueDeployment = fn (Server $server, string $status, string $uuid): ApplicationDeploymentQueue => ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => $uuid,
        'status' => $status,
        'server_id' => $server->id,
        'destination_id' => StandaloneDocker::where('server_id', $server->id)->firstOrFail()->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
        'only_this_server' => true,
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('a finished deployment starts the queued deployment on another server when a deleted server also has one queued', function () {
    $running = ($this->makeQueueDeployment)($this->primaryServer, 'in_progress', 'queue-across-running');
    $orphaned = ($this->makeQueueDeployment)($this->deletedServer, 'queued', 'queue-across-orphaned');
    $this->travel(1)->seconds();
    $waiting = ($this->makeQueueDeployment)($this->additionalServer, 'queued', 'queue-across-waiting');
    // A server deleted before deletion failed its queued deployments left them queued.
    Server::withoutEvents(fn () => $this->deletedServer->delete());
    Server::flushIdentityMap();
    Log::spy();

    (new ApplicationDeploymentJob($running->id))->failed(new RuntimeException('build failed'));

    Log::shouldNotHaveReceived('warning', [Mockery::pattern('/Starting the next queued deployment failed/')]);
    expect($waiting->fresh()->status)->toBe('in_progress')
        ->and($orphaned->fresh()->status)->toBe('queued');
    Queue::assertPushed(ApplicationDeploymentJob::class, 1);
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $waiting->id);
});

test('cancelling a deployment starts the queued deployment of the same application on another server', function (string $path) {
    $running = ($this->makeQueueDeployment)($this->primaryServer, 'in_progress', 'queue-across-running');
    $waiting = ($this->makeQueueDeployment)($this->additionalServer, 'queued', 'queue-across-waiting');

    if ($path === 'ui') {
        $this->actingAs($this->user);
        Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $running])->call('cancel');
    } elseif ($path === 'api') {
        $token = $this->user->createToken('queue-across', ['*'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson("/api/v1/deployments/{$running->deployment_uuid}/cancel")
            ->assertSuccessful();
    } else {
        $token = $this->user->createToken('queue-across', ['read', 'deploy'])->plainTextToken;
        $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'cancel_deployment', 'arguments' => ['uuid' => $running->deployment_uuid]],
        ])->assertOk();
    }

    expect($running->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($waiting->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $waiting->id);
})->with(['ui', 'api', 'mcp']);
