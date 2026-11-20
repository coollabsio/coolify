<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class ApiFakeTrafficClient extends SentinelTrafficClient
{
    public ?Throwable $failure = null;

    protected function raw(string $url): string
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $responses = [
            '/traffic/overview' => ['requests' => 200, 'bytes_in' => 5000, 'bytes_out' => 25000, 'status' => ['s2xx' => 180, 's3xx' => 5, 's4xx' => 10, 's5xx' => 5], 'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1], 'unique_visitors' => 32],
            '/traffic/paths' => [['path' => '/', 'app' => 'app-key', 'requests' => 150, 'bytes_out' => 12000, 's4xx' => 2, 's5xx' => 1, 'p95' => 30.0]],
            '/traffic/breakdown/country' => [['value' => 'US', 'requests' => 120, 'bytes_out' => 15000]],
            '/traffic/breakdown/ip' => [['value' => '198.51.100.42', 'requests' => 60, 'bytes_out' => 1200]],
            '/traffic/breakdown/useragent' => [['value' => 'Mozilla/5.0 PerAppAgent/2.0', 'requests' => 55, 'bytes_out' => 1100]],
            '/traffic/series' => [['bucket' => 1_700_000_000_000, 's2xx' => 40, 's3xx' => 2, 's4xx' => 1, 's5xx' => 0, 'requests' => 43, 'bytes_in' => 1000, 'bytes_out' => 5000, 'unique_visitors' => 12, 'p95' => 30.0]],
        ];
        foreach ($responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return json_encode($response);
            }
        }

        return '[]';
    }
}

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings->is_traffic_analytics_enabled = true;
    $this->server->settings->save();

    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->service = Service::factory()->create([
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);

    $this->fakeClient = new ApiFakeTrafficClient($this->server);
    app()->bind(SentinelTrafficClient::class, fn () => $this->fakeClient);
});

function trafficHeaders(array $abilities = ['read']): array
{
    return [
        'Authorization' => 'Bearer '.test()->user->createToken('traffic', $abilities)->plainTextToken,
        'Accept' => 'application/json',
    ];
}

dataset('traffic resources', [
    'application' => ['applications', 'application'],
    'service' => ['services', 'service'],
]);

it('returns traffic analytics with snake case keys', function (string $path, string $property) {
    $response = $this->withHeaders(trafficHeaders())
        ->getJson("/api/v1/{$path}/{$this->{$property}->uuid}/traffic?range=7d")
        ->assertOk();

    expect($response->json('enabled'))->toBeTrue()
        ->and($response->json('range'))->toBe('7d')
        ->and($response->json('overview'))->toBe([
            'requests' => 200,
            'unique_visitors' => 32,
            'bytes_in' => 5000,
            'bytes_out' => 25000,
            'error_rate' => 7.5,
            'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
            'status' => ['s2xx' => 180, 's3xx' => 5, 's4xx' => 10, 's5xx' => 5],
        ])
        ->and($response->json('series'))->toBe([
            ['bucket' => 1_700_000_000_000, 's2xx' => 40, 's3xx' => 2, 's4xx' => 1, 's5xx' => 0, 'requests' => 43, 'bytes_in' => 1000, 'bytes_out' => 5000, 'unique_visitors' => 12, 'p95' => 30],
        ])
        ->and($response->json('top_paths.0'))->toMatchArray(['path' => '/', 'requests' => 150, 'bytes_out' => 12000, 's4xx' => 2, 's5xx' => 1, 'p95' => 30])
        ->and($response->json('breakdowns.country'))->toBe([['value' => 'US', 'requests' => 120, 'bytes_out' => 15000]]);
})->with('traffic resources');

it('hides visitor ip and user agent breakdowns without read sensitive', function () {
    $breakdowns = $this->withHeaders(trafficHeaders())
        ->getJson("/api/v1/applications/{$this->application->uuid}/traffic")
        ->assertOk()
        ->json('breakdowns');

    expect($breakdowns)->not->toHaveKeys(['ip', 'useragent'])->toHaveKey('country');
});

it('returns visitor ip and user agent breakdowns with read sensitive', function () {
    $breakdowns = $this->withHeaders(trafficHeaders(['read', 'read:sensitive']))
        ->getJson("/api/v1/applications/{$this->application->uuid}/traffic")
        ->assertOk()
        ->json('breakdowns');

    expect($breakdowns['ip'])->toBe([['value' => '198.51.100.42', 'requests' => 60, 'bytes_out' => 1200]])
        ->and($breakdowns['useragent'])->toBe([['value' => 'Mozilla/5.0 PerAppAgent/2.0', 'requests' => 55, 'bytes_out' => 1100]]);
});

it('returns enabled false and null fields when traffic analytics is disabled', function () {
    $this->server->settings->is_traffic_analytics_enabled = false;
    $this->server->settings->save();

    $this->withHeaders(trafficHeaders())
        ->getJson("/api/v1/services/{$this->service->uuid}/traffic")
        ->assertOk()
        ->assertExactJson([
            'enabled' => false,
            'range' => '24h',
            'overview' => null,
            'latency_approximate' => null,
            'uniques_approximate' => null,
            'series' => null,
            'top_paths' => null,
            'breakdowns' => null,
            'attribution' => null,
        ]);
});

it('rejects an unknown range', function () {
    $this->withHeaders(trafficHeaders())
        ->getJson("/api/v1/applications/{$this->application->uuid}/traffic?range=1y")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('range');
});

it('returns 502 when Sentinel fails', function () {
    $this->fakeClient->failure = new RuntimeException('Server is not reachable.');

    $this->withHeaders(trafficHeaders())
        ->getJson("/api/v1/applications/{$this->application->uuid}/traffic")
        ->assertStatus(502)
        ->assertExactJson(['message' => 'Failed to fetch traffic analytics from Sentinel.']);
});

it('returns 404 for a resource of another team', function (string $path, string $message) {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $otherEnvironment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id]);
    $attributes = [
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => $otherDestination->getMorphClass(),
    ];
    $other = $path === 'applications'
        ? Application::factory()->create($attributes)
        : Service::factory()->create($attributes + ['server_id' => $otherServer->id]);

    $this->withHeaders(trafficHeaders())
        ->getJson("/api/v1/{$path}/{$other->uuid}/traffic")
        ->assertNotFound()
        ->assertExactJson(['message' => $message]);
})->with([
    'application' => ['applications', 'Application not found.'],
    'service' => ['services', 'Service not found.'],
]);
