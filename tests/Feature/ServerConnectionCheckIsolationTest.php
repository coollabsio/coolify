<?php

use App\Jobs\ServerConnectionCheckJob;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function createServerForConnectionIsolationTest(array $attributes = []): Server
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create(array_merge([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ], $attributes));
}

beforeEach(function () {
    Storage::fake('ssh-keys');
});

it('never attempts SSH to a placeholder address', function (string $placeholderIp) {
    $server = createServerForConnectionIsolationTest(['ip' => $placeholderIp]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    Process::fake();

    (new ServerConnectionCheckJob($server, disableMux: false))->handle();

    Process::assertNothingRan();
    expect($server->fresh()->unreachable_count)->toBe(0)
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeTrue();
})->with([
    'reserved provisioning address' => Server::PLACEHOLDER_IP,
    'unspecified IPv4 address' => '0.0.0.0',
    'unspecified IPv6 address' => '::',
]);

it('does not call cloud provider APIs during an SSH connection check', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $token = CloudProviderToken::create([
        'team_id' => $server->team_id,
        'provider' => 'vultr',
        'token' => 'test-vultr-token',
        'name' => 'Vultr',
    ]);
    $server->update([
        'cloud_provider_token_id' => $token->id,
        'vultr_instance_id' => 'instance-1',
        'vultr_instance_status' => 'active',
    ]);

    Http::fake([
        'https://api.vultr.com/*' => Http::response([
            'instance' => ['id' => 'instance-1', 'status' => 'active'],
        ]),
    ]);
    Process::fake([
        '*' => Process::result(
            output: '{"Server":{"Version":"27.0.0"}}',
            exitCode: 0,
        ),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    Http::assertNothingSent();
});
