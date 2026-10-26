<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-mux');
    Queue::fake();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $token = $this->user->createToken('service-delete-message', ['write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->bearerToken = $token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '10.20.30.40']);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'server_id' => $this->server->id,
    ]);
});

function setServiceDeleteServerReachable(Server $server, bool $reachable): void
{
    $server->settings->forceFill([
        'is_reachable' => $reachable,
        'is_usable' => $reachable,
        'force_disabled' => false,
    ])->save();
    Server::flushIdentityMap();
}

test('explicit delete from coolify only does not claim the server is unreachable', function (bool $serverReachable) {
    setServiceDeleteServerReachable($this->server, $serverReachable);

    $this->withToken($this->bearerToken)
        ->deleteJson("/api/v1/services/{$this->service->uuid}?delete_from_coolify_only=true")
        ->assertOk()
        ->assertJsonPath('message', 'The service will be removed from Coolify only; Docker resources will remain.');

    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job): bool => $job->deleteFromCoolifyOnly);
})->with([
    'reachable server' => [true],
    'unreachable server' => [false],
]);

test('delete on a reachable server queues a full deletion', function () {
    setServiceDeleteServerReachable($this->server, true);

    $this->withToken($this->bearerToken)
        ->deleteJson("/api/v1/services/{$this->service->uuid}")
        ->assertOk()
        ->assertJsonPath('message', 'Service deletion request queued.');

    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job): bool => ! $job->deleteFromCoolifyOnly);
});

test('delete on an unreachable server falls back to removing from coolify only', function () {
    setServiceDeleteServerReachable($this->server, false);

    $this->withToken($this->bearerToken)
        ->deleteJson("/api/v1/services/{$this->service->uuid}")
        ->assertOk()
        ->assertJsonPath('message', 'Server is not reachable. The service will be removed from Coolify only; Docker resources may remain.');

    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job): bool => $job->deleteFromCoolifyOnly);
});
