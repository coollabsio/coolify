<?php

use App\Actions\Server\ProcessSentinelReport;
use App\Actions\Server\StartSentinel;
use App\Events\SentinelRestarted;
use App\Jobs\PullSentinelReportJob;
use App\Jobs\PushServerUpdateJob;
use App\Jobs\ServerManagerJob;
use App\Livewire\Server\Sentinel;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    Cache::flush();
    Event::fake([SentinelRestarted::class]);
    // No instance FQDN or public IP: a push URL cannot be generated, as on a private network.
    InstanceSettings::forceCreate(['id' => 0, 'instance_timezone' => 'UTC']);
    config(['constants.ssh.mux_enabled' => false]);

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->first();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->forceFill([
        'is_reachable' => true,
        'is_usable' => true,
        'is_sentinel_enabled' => true,
        'is_sentinel_pull_enabled' => true,
        'sentinel_custom_url' => null,
    ])->saveQuietly();
    $this->server->refresh();
});

/**
 * @return array<string, string>
 */
function startSentinelForPullModeTest(Server $server): array
{
    $scripts = [];
    Process::fake(function ($process) use (&$scripts) {
        $scripts[] = remoteProcessScript($process);

        return Process::result(output: '');
    });

    StartSentinel::run($server, restart: false, latestVersion: '1.2.0');

    return sentinelEnvFile(collect($scripts)->first(fn (string $script): bool => str_contains($script, 'docker run -d')));
}

function fakeSentinelPullResponse(string $output, int $exitCode = 0, string $errorOutput = ''): void
{
    Process::fake(fn () => Process::result(output: $output, errorOutput: $errorOutput, exitCode: $exitCode));
}

function pulledSentinelReport(): string
{
    return json_encode([
        'version' => '1.2.0',
        'payload' => [
            'containers' => [['name' => 'app-1', 'state' => 'running', 'health_status' => 'healthy']],
            'filesystem_usage_root' => ['used_percentage' => 42],
        ],
    ]);
}

it('starts Sentinel without a push endpoint in pull mode', function () {
    $env = startSentinelForPullModeTest($this->server);

    expect($env)->toHaveKey('PUSH_ENABLED', 'false')
        ->and($env)->not->toHaveKey('PUSH_ENDPOINT');
});

it('also sends the push endpoint in pull mode when one exists, for older Sentinel versions', function () {
    $this->server->settings->forceFill(['sentinel_custom_url' => 'https://coolify.example.com'])->saveQuietly();

    $env = startSentinelForPullModeTest($this->server->refresh());

    expect($env)->toHaveKey('PUSH_ENABLED', 'false')
        ->and($env)->toHaveKey('PUSH_ENDPOINT', 'https://coolify.example.com');
});

it('pulls the first report soon after Sentinel starts in pull mode', function () {
    Carbon::setTestNow('2026-11-23 12:00:00');
    startSentinelForPullModeTest($this->server);

    Queue::assertPushed(PullSentinelReportJob::class, fn (PullSentinelReportJob $job) => $job->delay->toDateTimeString() === '2026-11-23 12:00:10');

    (new ServerManagerJob)->handle();
    Queue::assertPushed(PullSentinelReportJob::class, 1);
    Carbon::setTestNow();
});

it('does not pull after Sentinel starts in push mode', function () {
    $this->server->settings->forceFill([
        'is_sentinel_pull_enabled' => false,
        'sentinel_custom_url' => 'https://coolify.example.com',
    ])->saveQuietly();

    startSentinelForPullModeTest($this->server->refresh());

    Queue::assertNotPushed(PullSentinelReportJob::class);
});

it('starts Sentinel with the push endpoint in push mode', function () {
    $this->server->settings->forceFill([
        'is_sentinel_pull_enabled' => false,
        'sentinel_custom_url' => 'https://coolify.example.com',
    ])->saveQuietly();

    $env = startSentinelForPullModeTest($this->server->refresh());

    expect($env)->toHaveKey('PUSH_ENDPOINT', 'https://coolify.example.com')
        ->and($env)->not->toHaveKey('PUSH_ENABLED');
});

it('handles a pulled report like a push', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()->subHour(), 'sentinel_waiting_since' => now()])->save();
    $this->server->rememberSentinelPushProblem('Sentinel is too old for pull mode. Update Sentinel.');

    ProcessSentinelReport::run($this->server, json_decode(pulledSentinelReport(), true)['payload'], '1.2.0');

    $server = $this->server->fresh();
    expect($server->sentinelStatus())->toBe('in_sync')
        ->and($server->sentinel_waiting_since)->toBeNull()
        ->and($server->sentinelPushProblem())->toBeNull()
        ->and(Cache::get(Server::sentinelReportedVersionCacheKey($server->id)))->toBe('1.2.0');
    Queue::assertPushed(PushServerUpdateJob::class, 1);
});

it('processes the report that the pull job reads over SSH', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()->subHour()])->save();
    fakeSentinelPullResponse(pulledSentinelReport()."\n200");

    (new PullSentinelReportJob($this->server))->handle();

    expect($this->server->fresh()->sentinelStatus())->toBe('in_sync')
        ->and($this->server->sentinelPushProblem())->toBeNull();
    Queue::assertPushed(PushServerUpdateJob::class, fn (PushServerUpdateJob $job): bool => data_get($job->data, 'containers.0.name') === 'app-1');
});

it('sends the Sentinel token to curl on stdin, not as an argument', function () {
    fakeSentinelPullResponse(pulledSentinelReport()."\n200");

    (new PullSentinelReportJob($this->server))->handle();

    $token = $this->server->settings->sentinel_token;
    Process::assertRan(function ($process) use ($token): bool {
        $lines = collect(explode("\n", remoteProcessScript($process)))->filter(fn (string $line): bool => str_contains($line, $token));

        return $lines->values()->all() === ["Authorization: Bearer {$token}"];
    });
});

it('records that Sentinel is too old when it has no pull endpoint', function () {
    $this->server->forceFill(['sentinel_updated_at' => now()->subHour()])->save();
    fakeSentinelPullResponse("\n404");

    (new PullSentinelReportJob($this->server))->handle();

    expect($this->server->sentinelPushProblem())->toBe('Sentinel is too old for pull mode. Update Sentinel.')
        ->and($this->server->fresh()->sentinelStatus())->toBe('out_of_sync');
    Queue::assertNotPushed(PushServerUpdateJob::class);
});

it('records the error that Sentinel returns', function () {
    fakeSentinelPullResponse("{\"error\":\"collector not ready\"}\n500");

    (new PullSentinelReportJob($this->server))->handle();

    expect($this->server->sentinelPushProblem())->toBe('Sentinel refused the pull request: collector not ready');
});

it('records the SSH error when the Sentinel container is not running', function () {
    fakeSentinelPullResponse('', 1, 'Error response from daemon: container coolify-sentinel is not running');

    (new PullSentinelReportJob($this->server))->handle();

    expect($this->server->sentinelPushProblem())
        ->toBe('Coolify could not read the Sentinel report over SSH: Error response from daemon: container coolify-sentinel is not running');
});

it('pulls once per push interval from the server manager', function () {
    Carbon::setTestNow('2026-11-23 12:00:00');
    $this->server->settings->forceFill(['sentinel_push_interval_seconds' => 120])->saveQuietly();

    (new ServerManagerJob)->handle();
    Carbon::setTestNow('2026-11-23 12:01:00');
    (new ServerManagerJob)->handle();
    Carbon::setTestNow('2026-11-23 12:02:00');
    (new ServerManagerJob)->handle();

    Queue::assertPushed(PullSentinelReportJob::class, 2);
    Carbon::setTestNow();
});

it('does not pull from a server in push mode or a server that is not functional', function (array $settings) {
    $this->server->settings->forceFill($settings)->saveQuietly();

    (new ServerManagerJob)->handle();

    Queue::assertNotPushed(PullSentinelReportJob::class);
})->with([
    'push mode' => [['is_sentinel_pull_enabled' => false]],
    'unreachable' => [['is_reachable' => false]],
]);

it('restarts Sentinel when the Sentinel page switches to push mode', function () {
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSet('isSentinelPullEnabled', true)
        ->set('isSentinelPullEnabled', false)
        ->set('sentinelCustomUrl', 'https://coolify.example.com')
        ->call('instantSave')
        ->assertHasNoErrors();

    expect($this->server->settings->fresh()->is_sentinel_pull_enabled)->toBeFalse()
        ->and(Queue::pushed(JobDecorator::class, fn (JobDecorator $job): bool => $job->getAction() instanceof StartSentinel))->toHaveCount(1);
});
