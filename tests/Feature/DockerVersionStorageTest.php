<?php

use App\Jobs\ServerConnectionCheckJob;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function createServerForDockerVersionStorageTest(): Server
{
    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.10',
    ]);
}

beforeEach(function () {
    Storage::fake('ssh-keys');
    Carbon::setTestNow('2026-08-13 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('logs the docker stop command in local development', function () {
    config(['app.env' => 'local']);
    Log::spy();

    dockerStopCommand(30, 'app-1', '27.5.1');

    Log::shouldHaveReceived('info')
        ->once()
        ->with('docker stop command', Mockery::on(fn (array $context): bool => $context['command'] === 'docker stop --time=30 app-1'
            && $context['docker_version'] === '27.5.1'));
});

it('does not log the docker stop command outside local development', function () {
    config(['app.env' => 'testing']);
    Log::spy();

    dockerStopCommand(30, 'app-1', '27.5.1');

    Log::shouldNotHaveReceived('info');
});

it('has docker and compose version columns on server settings', function () {
    expect(Schema::hasColumns('server_settings', [
        'docker_version',
        'docker_version_checked_at',
        'compose_version',
        'compose_version_checked_at',
    ]))->toBeTrue();
});

it('stores a parsed docker version on the server settings', function () {
    $server = createServerForDockerVersionStorageTest();

    $server->rememberDockerVersion('29.4.3-ce');

    $settings = $server->settings->fresh();

    expect($settings->docker_version)->toBe('29.4.3')
        ->and($settings->docker_version_checked_at?->equalTo(Carbon::parse('2026-08-13 12:00:00')))->toBeTrue()
        ->and($server->dockerVersion())->toBe('29.4.3');
});

it('updates the stored docker version when the engine changes', function () {
    $server = createServerForDockerVersionStorageTest();
    $server->rememberDockerVersion('27.5.1');

    Carbon::setTestNow('2026-08-13 13:00:00');
    $server->rememberDockerVersion('29.4.3');

    $settings = $server->settings->fresh();

    expect($settings->docker_version)->toBe('29.4.3')
        ->and($settings->docker_version_checked_at?->equalTo(Carbon::parse('2026-08-13 13:00:00')))->toBeTrue();
});

it('builds stop commands from the stored server docker version', function (?string $storedVersion, string $expectedCommand) {
    $server = createServerForDockerVersionStorageTest();
    if ($storedVersion !== null) {
        $server->rememberDockerVersion($storedVersion);
    }

    expect(dockerStopCommand(30, 'app-1', $server->fresh()))->toBe($expectedCommand);
})->with([
    'unset' => [null, 'docker stop --time=30 app-1'],
    'docker 28+' => ['29.4.3', 'docker stop --timeout=30 app-1'],
    'docker 27' => ['27.5.1', 'docker stop --time=30 app-1'],
]);

it('stores a parsed compose version on the server settings', function () {
    $server = createServerForDockerVersionStorageTest();

    $server->rememberComposeVersion('v2.32.4');

    $settings = $server->settings->fresh();

    expect($settings->compose_version)->toBe('2.32.4')
        ->and($settings->compose_version_checked_at?->equalTo(Carbon::parse('2026-08-13 12:00:00')))->toBeTrue()
        ->and($server->composeVersion())->toBe('2.32.4');
});

it('records docker and compose versions during connection checks', function () {
    $server = createServerForDockerVersionStorageTest();

    Process::fake([
        '*compose*' => Process::result(output: 'v2.32.4', exitCode: 0),
        '*' => Process::result(
            output: '{"Server":{"Version":"29.4.3"}}',
            exitCode: 0,
        ),
    ]);

    (new ServerConnectionCheckJob($server, disableMux: false))->handle();

    $settings = $server->settings->fresh();

    expect($settings->docker_version)->toBe('29.4.3')
        ->and($settings->compose_version)->toBe('2.32.4');
});
