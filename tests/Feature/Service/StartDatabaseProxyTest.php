<?php

use App\Actions\Database\StartDatabaseProxy;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
});

test('database proxy is disabled on port already allocated error', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $database = StandalonePostgresql::create([
        'name' => 'postgres',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'is_public' => true,
        'public_port' => 5432,
    ]);

    expect($database->is_public)->toBeTrue();

    $action = new StartDatabaseProxy;

    // Use reflection to test the private method directly
    $method = new ReflectionMethod($action, 'isNonTransientError');

    expect($method->invoke($action, 'Bind for 0.0.0.0:5432 failed: port is already allocated'))->toBeTrue();
    expect($method->invoke($action, 'address already in use'))->toBeTrue();
    expect($method->invoke($action, 'some other error'))->toBeFalse();
});

test('isNonTransientError detects port conflict patterns', function () {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'isNonTransientError');

    expect($method->invoke($action, 'Bind for 0.0.0.0:5432 failed: port is already allocated'))->toBeTrue()
        ->and($method->invoke($action, 'address already in use'))->toBeTrue()
        ->and($method->invoke($action, 'Bind for 0.0.0.0:3306 failed: port is already allocated'))->toBeTrue()
        ->and($method->invoke($action, 'network timeout'))->toBeFalse()
        ->and($method->invoke($action, 'connection refused'))->toBeFalse();
});

test('buildProxyTimeoutConfig normalizes invalid values to default', function (?int $input, string $expected) {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'buildProxyTimeoutConfig');

    expect($method->invoke($action, $input))->toBe($expected);
})->with([
    [null, 'proxy_timeout 3600s;'],
    [0, 'proxy_timeout 3600s;'],
    [-10, 'proxy_timeout 3600s;'],
    [120, 'proxy_timeout 120s;'],
]);

test('buildListenConfig adds an IPv6 listener only when the network has IPv6 enabled', function (bool $ipv6Enabled, array $expectedListeners) {
    $action = new StartDatabaseProxy;
    $method = new ReflectionMethod($action, 'buildListenConfig');

    $listeners = array_map('trim', explode("\n", $method->invoke($action, 28197, $ipv6Enabled)));

    expect($listeners)->toBe($expectedListeners);
})->with([
    'IPv4 only network' => [false, ['listen 28197;']],
    'IPv6 enabled network' => [true, ['listen 28197;', 'listen [::]:28197;']],
]);

test('database proxy resolves the database container on every connection', function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return Process::result(output: '');
    });

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $database = create_standalone_postgresql($environment->id, $destination);
    $database->update(['is_public' => true, 'public_port' => 15432]);

    StartDatabaseProxy::run($database->fresh());

    preg_match("/echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| tee [^ ]+nginx\\.conf/", implode("\n", $commands), $matches);
    $nginxConfig = base64_decode($matches[1]);

    // A literal host in proxy_pass is resolved once at startup, so a restarted database with a new IP becomes unreachable.
    expect($nginxConfig)->toContain('resolver 127.0.0.11 valid=10s;')
        ->toContain("set \$upstream {$database->uuid}:5432;")
        ->toContain('proxy_pass $upstream;')
        ->not->toContain("proxy_pass {$database->uuid}:5432;");
});
