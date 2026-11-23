<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    config(['constants.ssh.mux_enabled' => false]);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    ServerSetting::withoutEvents(fn () => $this->server->settings->update([
        'is_metrics_enabled' => false,
        'sentinel_metrics_history_days' => 7,
    ]));

    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->database = StandalonePostgresql::create([
        'name' => 'db',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'app',
        'image' => 'postgres:16',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->headers = [
        'Authorization' => 'Bearer '.$this->user->createToken('resource-metrics-read', ['read'])->plainTextToken,
        'Accept' => 'application/json',
    ];
});

function enableResourceMetricsForTest(): void
{
    ServerSetting::withoutEvents(fn () => test()->server->settings->update([
        'is_metrics_enabled' => true,
        'sentinel_token' => 'resource-metrics-test-token',
    ]));
}

function fakeContainerMetrics(string $uuid): void
{
    fakeRemoteProcesses([
        "*container/{$uuid}/cpu/history*" => Process::result(output: json_encode([
            ['time' => 1787259000000, 'percent' => 2.5],
        ])),
        "*container/{$uuid}/memory/history*" => Process::result(output: json_encode([
            ['time' => 1787259000000, 'used' => 52428800, 'usedPercent' => 10.0],
        ])),
    ]);
}

dataset('metrics resources', [
    'application' => ['applications', 'application'],
    'database' => ['databases', 'database'],
]);

it('returns container cpu percent and memory in MB', function (string $path, string $property) {
    enableResourceMetricsForTest();
    $uuid = $this->{$property}->uuid;
    fakeContainerMetrics($uuid);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/{$path}/{$uuid}/metrics?minutes=30")
        ->assertOk()
        ->assertExactJson([
            'cpu' => [[1787259000000, 2.5]],
            'memory' => [[1787259000000, 50.0]],
        ]);
})->with('metrics resources');

it('returns null metrics when metrics are disabled on the server', function (string $path, string $property) {
    $this->withHeaders($this->headers)
        ->getJson("/api/v1/{$path}/{$this->{$property}->uuid}/metrics")
        ->assertOk()
        ->assertExactJson(['cpu' => null, 'memory' => null]);
})->with('metrics resources');

it('rejects a window older than the retention of the resource server', function (string $path, string $property) {
    $this->withHeaders($this->headers)
        ->getJson("/api/v1/{$path}/{$this->{$property}->uuid}/metrics?from=".now()->subDays(8)->toIso8601ZuluString())
        ->assertStatus(422)
        ->assertJsonPath('errors.from.0', 'The requested metrics history exceeds the configured Sentinel retention period of 7 day(s).');
})->with('metrics resources');

it('returns 502 when Sentinel fails', function (string $path, string $property) {
    enableResourceMetricsForTest();
    Process::fake(['*' => Process::result(output: json_encode(['error' => 'Unauthorized']))]);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/{$path}/{$this->{$property}->uuid}/metrics")
        ->assertStatus(502)
        ->assertExactJson(['message' => 'Failed to fetch metrics from Sentinel.']);
})->with('metrics resources');

it('returns 404 for a resource of another team', function (string $path, string $property, string $message) {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $otherEnvironment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id]);
    $attributes = [
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ];
    $other = $property === 'application'
        ? Application::factory()->create($attributes)
        : StandalonePostgresql::create($attributes + ['name' => 'other', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'app', 'image' => 'postgres:16']);

    $this->withHeaders($this->headers)
        ->getJson("/api/v1/{$path}/{$other->uuid}/metrics")
        ->assertNotFound()
        ->assertExactJson(['message' => $message]);
})->with([
    'application' => ['applications', 'application', 'Application not found.'],
    'database' => ['databases', 'database', 'Database not found.'],
]);
