<?php

use App\Jobs\ServerConnectionCheckJob;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

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
    Sleep::fake();
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

it('resets unreachable_count after a successful connection check', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    // unreachable_count is not mass-assignable, so it must be set directly.
    $server->unreachable_count = 5;
    $server->save();

    Process::fake([
        '*' => Process::result(
            output: '{"Server":{"Version":"27.0.0"}}',
            exitCode: 0,
        ),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    expect($server->fresh()->unreachable_count)->toBe(0);
});

it('logs the checking node and ssh error only when the connection state changes', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $logPath = tempnam(sys_get_temp_dir(), 'coolify-scheduled-log-');
    config(['logging.channels.scheduled' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']]);
    Log::forgetChannel('scheduled');
    Process::fake([
        '*' => Process::result(errorOutput: 'ssh: connect to host 203.0.113.10 port 22: Connection refused', exitCode: 255),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();
    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    $log = file_get_contents($logPath);
    @unlink($logPath);

    expect(substr_count($log, 'Server connection state changed'))->toBe(1)
        ->and($log)->toContain('"is_reachable":false')
        ->toContain('"was_reachable":true')
        ->toContain('ssh exit 255: ssh: connect to host 203.0.113.10 port 22: Connection refused')
        ->toContain('"host":"'.gethostname().'"');
});

it('keeps the server online after one failed SSH check', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    Process::fake([
        '*' => Process::result(errorOutput: 'Connection timed out', exitCode: 255),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    expect($server->fresh()->unreachable_count)->toBe(1)
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeTrue();
});

it('marks the server offline after consecutive failed SSH checks', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    Process::fake([
        '*' => Process::result(errorOutput: 'Connection timed out', exitCode: 255),
    ]);

    for ($attempt = 0; $attempt < ServerConnectionCheckJob::UNREACHABLE_THRESHOLD; $attempt++) {
        (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();
    }

    expect($server->fresh()->unreachable_count)->toBe(ServerConnectionCheckJob::UNREACHABLE_THRESHOLD)
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeFalse()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeFalse();
});

it('does not mark the server offline when the check fails for a reason other than SSH or Docker', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $server->unreachable_count = 1;
    $server->save();
    Process::fake([
        '*' => Process::result(output: '{"Server":{"Version":"27.0.0"}}', exitCode: 0),
    ]);
    // Fails the unreachable_count reset, after SSH and Docker checks passed.
    Server::saving(fn () => throw new RuntimeException('database connection lost'));

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    Server::flushEventListeners();
    expect($server->fresh()->unreachable_count)->toBe(1)
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeTrue();
});

it('retries a failed SSH attempt once within the same check', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $server->settings->update(['is_reachable' => false, 'is_usable' => false]);
    $server->unreachable_count = 3;
    $server->save();
    Process::fake([
        '*ls -la /*' => Process::sequence()
            ->push(Process::result(errorOutput: 'Connection timed out', exitCode: 255))
            ->push(Process::result(exitCode: 0)),
        '*compose*' => Process::result(output: 'v2.32.4', exitCode: 0),
        '*' => Process::result(output: '{"Server":{"Version":"29.4.3"}}', exitCode: 0),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    Sleep::assertSleptTimes(1);
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'ls -la /'), 2);
    expect($server->fresh()->unreachable_count)->toBe(0)
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeTrue();
});

it('counts one failed check when both SSH attempts fail', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    Process::fake([
        '*' => Process::result(errorOutput: 'Connection timed out', exitCode: 255),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    Sleep::assertSleptTimes(1);
    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'ls -la /'), 2);
    expect($server->fresh()->unreachable_count)->toBe(1)
        ->and((bool) $server->settings->fresh()->is_reachable)->toBeTrue();
});

it('does not retry the Docker check within the same check', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    Process::fake([
        '*docker version*' => Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1),
        '*' => Process::result(exitCode: 0),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    Process::assertRanTimes(fn ($process) => str_contains($process->command, 'docker version'), 1);
    expect((bool) $server->settings->fresh()->is_reachable)->toBeTrue()
        ->and((bool) $server->settings->fresh()->is_usable)->toBeFalse();
});

it('keeps the server usable when only the compose version check fails', function () {
    $server = createServerForConnectionIsolationTest(['ip' => '203.0.113.10']);
    Process::fake([
        '*compose*' => Process::result(errorOutput: 'docker: compose is not a docker command', exitCode: 1),
        '*' => Process::result(output: '{"Server":{"Version":"29.4.3"}}', exitCode: 0),
    ]);

    (new ServerConnectionCheckJob($server->fresh(), disableMux: false))->handle();

    expect((bool) $server->settings->fresh()->is_usable)->toBeTrue();
});

it('gives the job enough time for every connection check step', function () {
    $job = new ServerConnectionCheckJob(new Server);
    $lock = collect($job->middleware())->first();

    expect($job->timeout)->toBeGreaterThanOrEqual(ServerConnectionCheckJob::maximumCheckSeconds())
        ->and($lock->expiresAfter)->toBeGreaterThan($job->timeout);
});
