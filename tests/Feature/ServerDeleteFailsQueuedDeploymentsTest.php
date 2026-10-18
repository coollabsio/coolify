<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Server\Delete;
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
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.41']);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $makeDeployment = fn (string $status, string $uuid): ApplicationDeploymentQueue => ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => $uuid,
        'status' => $status,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'commit' => 'HEAD',
        'pull_request_id' => 0,
    ]);
    $this->queued = $makeDeployment(ApplicationDeploymentStatus::QUEUED->value, 'server-delete-queued');
    $this->finished = $makeDeployment(ApplicationDeploymentStatus::FINISHED->value, 'server-delete-finished');
});

afterEach(function () {
    Server::flushIdentityMap();
});

test('deleting a server fails its queued deployments', function (string $path) {
    if ($path === 'api') {
        $token = $this->user->createToken('server-delete', ['write'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->deleteJson("/api/v1/servers/{$this->server->uuid}?force=true")
            ->assertOk();
    } else {
        $this->actingAs($this->user);
        Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid])
            ->call('delete', 'password', ['force_delete_resources']);
    }

    expect(Server::withTrashed()->find($this->server->id)->trashed())->toBeTrue();

    $queued = $this->queued->fresh();
    expect($queued->status)->toBe(ApplicationDeploymentStatus::FAILED->value)
        ->and(collect(json_decode($queued->logs, true))->pluck('output')->implode("\n"))->toContain('The server was deleted.')
        ->and($this->finished->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value);
})->with(['api', 'ui']);
