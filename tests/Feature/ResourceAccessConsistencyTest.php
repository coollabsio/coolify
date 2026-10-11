<?php

use App\Livewire\Project\Shared\ScheduledTask\Add;
use App\Livewire\Server\DockerCleanupExecutions;
use App\Models\Application;
use App\Models\DockerCleanupExecution;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();

    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'IDOR test key',
        'private_key' => 'test-private-key',
        'team_id' => $this->team->id,
    ]));

    $token = $this->user->createToken('idor-hardening', ['*']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->token = $token->plainTextToken;
});

test('server creation returns a consistent duplicate address response', function () {
    $ownServer = Server::factory()->create([
        'ip' => '192.0.2.10',
        'team_id' => $this->team->id,
    ]);
    $otherServer = Server::factory()->create([
        'ip' => '192.0.2.20',
        'team_id' => $this->otherTeam->id,
    ]);

    $payload = fn (Server $server): array => [
        'name' => 'Duplicate server',
        'ip' => $server->ip,
        'private_key_uuid' => $this->privateKey->uuid,
        'user' => 'root',
    ];

    $ownResponse = $this->withToken($this->token)->postJson('/api/v1/servers', $payload($ownServer));
    $otherResponse = $this->withToken($this->token)->postJson('/api/v1/servers', $payload($otherServer));

    $ownResponse->assertBadRequest();
    $otherResponse->assertBadRequest();
    expect($ownResponse->json('message'))
        ->toBe('A server with this IP/Domain is already in use.')
        ->toBe($otherResponse->json('message'));
});

test('environment details applies the project view policy', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    Gate::before(fn (User $user, string $ability): ?bool => $ability === 'view' ? false : null);

    $this->withToken($this->token)
        ->getJson("/api/v1/projects/{$project->uuid}/{$environment->uuid}")
        ->assertForbidden();
});

test('docker cleanup execution selection only uses the mounted server', function () {
    $this->actingAs($this->user);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $otherServer = Server::factory()->create(['team_id' => $this->otherTeam->id]);
    $otherExecution = DockerCleanupExecution::create([
        'server_id' => $otherServer->id,
        'status' => 'success',
        'message' => 'other team cleanup output',
    ]);

    Livewire::test(DockerCleanupExecutions::class, ['server' => $server])
        ->call('selectExecution', $otherExecution->id)
        ->assertSet('selectedExecution', null);
});

test('scheduled task form only mounts applications from the current team', function () {
    $this->actingAs($this->user);

    $server = Server::factory()->create(['team_id' => $this->otherTeam->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->otherTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    Livewire::test(Add::class, [
        'id' => (string) $application->id,
        'type' => 'application',
        'containerNames' => collect(),
    ]);
})->throws(ModelNotFoundException::class);
