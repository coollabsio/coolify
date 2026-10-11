<?php

use App\Enums\ApplicationDeploymentStatus;
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
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
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
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.10']);
    $this->buildServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.20']);

    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    // A deployments-only server uses the build server even with this setting off.
    $this->application->settings->update(['is_build_server_enabled' => false]);

    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'cancel-cleanup-uuid',
        'server_id' => $this->server->id,
        'build_server_id' => $this->buildServer->id,
        'destination_id' => $destination->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'current_process_id' => '4242',
    ]);

    $this->remoteCommands = [];
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
        $this->remoteCommands[] = $command;

        return Process::result(str_contains($command, 'docker ps -a') ? 'cancel-cleanup-uuid' : '');
    });
});

afterEach(function () {
    Server::flushIdentityMap();
});

function cancelCleanupCommandsOn(array $commands, string $ip, string $needle): array
{
    return array_values(array_filter($commands, fn (string $command) => str_contains($command, "@'{$ip}'") && str_contains($command, $needle)));
}

function assertCancelCleanupRanCorrectly(array $commands, ApplicationDeploymentQueue $deployment): void
{
    $killCommands = array_filter($commands, fn (string $command) => preg_match('/\bkill\s+-9\b/', $command) === 1 || str_contains($command, 'kill -9 4242'));

    expect($killCommands)->toBe([])
        ->and(cancelCleanupCommandsOn($commands, '203.0.113.20', 'docker rm -f cancel-cleanup-uuid'))->not->toBeEmpty()
        ->and($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value)
        ->and($deployment->fresh()->current_process_id)->toBeNull();
}

test('cancelling from the UI removes the helper container on the build server and never kills a remote pid', function () {
    $this->actingAs($this->user);

    Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment])
        ->call('cancel');

    assertCancelCleanupRanCorrectly($this->remoteCommands, $this->deployment);
});

test('cancelling through the API removes the helper container on the build server and never kills a remote pid', function () {
    $token = $this->user->createToken('cancel-cleanup', ['*'])->plainTextToken;

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/deployments/{$this->deployment->deployment_uuid}/cancel")
        ->assertSuccessful();

    assertCancelCleanupRanCorrectly($this->remoteCommands, $this->deployment);
});

test('cancelling through MCP removes the helper container on the build server and never kills a remote pid', function () {
    $token = $this->user->createToken('cancel-cleanup', ['read', 'deploy'])->plainTextToken;

    $this->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer '.$token,
    ])->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'cancel_deployment', 'arguments' => ['uuid' => $this->deployment->deployment_uuid]],
    ])->assertOk();

    assertCancelCleanupRanCorrectly($this->remoteCommands, $this->deployment);
});

test('cancelling a deployment without a build server removes the helper container on the deployment server', function () {
    $this->deployment->update(['build_server_id' => null]);
    $this->actingAs($this->user);

    Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment])
        ->call('cancel');

    expect(cancelCleanupCommandsOn($this->remoteCommands, '203.0.113.10', 'docker rm -f cancel-cleanup-uuid'))->not->toBeEmpty()
        ->and(cancelCleanupCommandsOn($this->remoteCommands, '203.0.113.20', 'docker'))->toBeEmpty()
        ->and(array_filter($this->remoteCommands, fn (string $command) => str_contains($command, 'kill -9')))->toBe([]);
});

test('cancelling does not touch servers of another team', function () {
    $otherTeam = Team::factory()->create();
    $otherKey = PrivateKey::factory()->create(['team_id' => $otherTeam->id]);
    $foreignServer = Server::factory()->create(['team_id' => $otherTeam->id, 'private_key_id' => $otherKey->id, 'ip' => '203.0.113.30']);
    $this->deployment->update(['build_server_id' => $foreignServer->id]);
    $this->actingAs($this->user);

    Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment])
        ->call('cancel');

    expect(cancelCleanupCommandsOn($this->remoteCommands, '203.0.113.30', 'docker'))->toBeEmpty()
        ->and($this->deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::CANCELLED_BY_USER->value);
});

test('cancelling after switching to another team still cleans up and starts the next queued deployment', function () {
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $nextApplication = Application::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);
    $queued = ApplicationDeploymentQueue::create([
        'application_id' => $nextApplication->id,
        'deployment_uuid' => 'cancel-cleanup-next',
        'server_id' => $this->server->id,
        'destination_id' => $this->application->destination_id,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);
    $this->actingAs($this->user);

    $component = Livewire::test(DeploymentNavbar::class, ['application_deployment_queue' => $this->deployment]);
    session(['currentTeam' => $otherTeam]);
    $component->call('cancel');

    assertCancelCleanupRanCorrectly($this->remoteCommands, $this->deployment);
    expect($queued->fresh()->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);
});
