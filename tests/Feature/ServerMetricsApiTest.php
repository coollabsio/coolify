<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['constants.ssh.mux_enabled' => false]);

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::create([
        'name' => 'Metrics API Key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $token = $this->user->createToken('read-token', ['read']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();
    $this->bearerToken = $token->plainTextToken;
});

function getServerMetricsApi(Server $server): TestResponse
{
    return test()->withHeaders([
        'Authorization' => 'Bearer '.test()->bearerToken,
        'Content-Type' => 'application/json',
    ])->getJson('/api/v1/servers/'.$server->uuid.'/metrics');
}

it('returns current cpu and memory metrics for a server', function () {
    $this->server->settings()->update(['is_metrics_enabled' => true]);

    Process::fake([
        '*/cpu/current*' => Process::result(output: json_encode([
            'time' => '2026-06-19T10:00:00Z',
            'percent' => 12.5,
        ]), exitCode: 0),
        '*/memory/current*' => Process::result(output: json_encode([
            'time' => '2026-06-19T10:00:00Z',
            'total' => 1024,
            'available' => 768,
            'used' => 256,
            'usedPercent' => 25,
            'free' => 512,
        ]), exitCode: 0),
    ]);

    getServerMetricsApi($this->server)
        ->assertOk()
        ->assertJsonPath('cpu.percent', 12.5)
        ->assertJsonPath('memory.usedPercent', 25);

    Process::assertRan(fn ($process) => str_contains($process->command, '/cpu/current'));
    Process::assertRan(fn ($process) => str_contains($process->command, '/memory/current'));
});

it('returns conflict when metrics are disabled for the server', function () {
    $this->server->settings()->update(['is_metrics_enabled' => false]);

    Process::fake();

    getServerMetricsApi($this->server)
        ->assertStatus(409)
        ->assertJson(['message' => 'Metrics are disabled for this server.']);

    Process::assertNothingRan();
});

it('does not expose metrics for another team server', function () {
    $otherTeam = Team::factory()->create();
    $otherPrivateKey = PrivateKey::create([
        'name' => 'Other Metrics API Key',
        'private_key' => generateSSHKey('ed25519')['private'],
        'team_id' => $otherTeam->id,
    ]);
    $otherServer = Server::factory()->create([
        'team_id' => $otherTeam->id,
        'private_key_id' => $otherPrivateKey->id,
    ]);
    $otherServer->settings()->update(['is_metrics_enabled' => true]);

    Process::fake();

    getServerMetricsApi($otherServer)
        ->assertNotFound()
        ->assertJson(['message' => 'Server not found.']);

    Process::assertNothingRan();
});
