<?php

use App\Jobs\ServerCloudProviderStatusCheckJob;
use App\Jobs\ServerConnectionCheckJob;
use App\Jobs\ServerManagerJob;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createCloudServerForStatusCheckJobTest(string $provider, array $attributes = []): Server
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $token = CloudProviderToken::create([
        'team_id' => $team->id,
        'provider' => $provider,
        'token' => "test-{$provider}-token",
        'name' => ucfirst($provider),
    ]);

    return Server::factory()->create(array_merge([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'cloud_provider_token_id' => $token->id,
        'ip' => Server::PLACEHOLDER_IP,
    ], $attributes));
}

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'instance_timezone' => 'UTC',
    ]);
    Carbon::setTestNow('2026-07-11 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('syncs provider state for a placeholder server without scheduling SSH', function () {
    $server = createCloudServerForStatusCheckJobTest('digitalocean', [
        'digitalocean_droplet_id' => 987,
        'digitalocean_droplet_status' => 'new',
    ]);

    Queue::fake();

    (new ServerManagerJob)->handle();

    Queue::assertPushed(ServerCloudProviderStatusCheckJob::class, fn (ServerCloudProviderStatusCheckJob $job) => $job->server->is($server));
    Queue::assertNotPushed(ServerConnectionCheckJob::class);
});

it('backfills a DigitalOcean placeholder without changing reachability', function () {
    $server = createCloudServerForStatusCheckJobTest('digitalocean', [
        'digitalocean_droplet_id' => 987,
        'digitalocean_droplet_status' => 'new',
    ]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response([
            'droplet' => [
                'id' => 987,
                'status' => 'active',
                'networks' => [
                    'v4' => [
                        ['type' => 'public', 'ip_address' => '203.0.113.10'],
                    ],
                ],
            ],
        ]),
    ]);

    (new ServerCloudProviderStatusCheckJob($server))->handle();

    $server->refresh();
    expect($server->ip)->toBe('203.0.113.10')
        ->and($server->digitalocean_droplet_status)->toBe('active')
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeTrue();
});

it('backfills a Hetzner placeholder without changing reachability', function () {
    $server = createCloudServerForStatusCheckJobTest('hetzner', [
        'hetzner_server_id' => 123,
        'hetzner_server_status' => 'starting',
    ]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Http::fake([
        'https://api.hetzner.cloud/v1/servers/123' => Http::response([
            'server' => [
                'id' => 123,
                'status' => 'running',
                'public_net' => [
                    'ipv4' => ['ip' => '198.51.100.20'],
                ],
            ],
        ]),
    ]);

    (new ServerCloudProviderStatusCheckJob($server))->handle();

    $server->refresh();
    expect($server->ip)->toBe('198.51.100.20')
        ->and($server->hetzner_server_status)->toBe('running')
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeTrue();
});

it('only calls the API matching the cloud provider token', function () {
    $server = createCloudServerForStatusCheckJobTest('digitalocean', [
        'vultr_instance_id' => 'unrelated-vultr-instance',
        'digitalocean_droplet_id' => 987,
        'digitalocean_droplet_status' => 'new',
    ]);

    Http::fake([
        'https://api.digitalocean.com/v2/droplets/987' => Http::response([
            'droplet' => [
                'id' => 987,
                'status' => 'active',
                'networks' => ['v4' => []],
            ],
        ]),
        'https://api.vultr.com/*' => Http::response(status: 500),
    ]);

    (new ServerCloudProviderStatusCheckJob($server))->handle();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.digitalocean.com/v2/droplets/987');
});
