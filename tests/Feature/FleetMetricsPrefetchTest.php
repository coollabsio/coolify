<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\SentinelMetricsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->team = $team;
});

function prefetchServer($team, $privateKey): Server
{
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $server->settings->forceFill(['is_metrics_enabled' => true])->saveQuietly();

    return $server->refresh();
}

/** Sentinel responses in the order of the batch: summary, containers, cpu, memory, disk, load, network. */
function fleetBatchOutput(?string $summary = null): string
{
    return implode("\x1e", [
        $summary ?? '{"cpu":{"percent":12.5},"memory":{"used":100,"total":200}}',
        '[{"id":"app-1","cpu":{"percent":3},"status":{"state":"running"}}]',
        '[{"time":300000,"percent":12.5}]',
        '[{"time":300000,"used":100}]',
        '[{"time":300000,"mount":"/","usedPercent":40}]',
        '[{"time":300000,"load1":0.5}]',
        '[{"time":300000,"rxBytesPerSec":10,"txBytesPerSec":2}]',
    ]);
}

it('fetches the page metrics of every server in one SSH command each', function () {
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $commands[] = remoteProcessScript($process);

        return Process::result(fleetBatchOutput());
    });
    $clients = collect([prefetchServer($this->team, $this->privateKey), prefetchServer($this->team, $this->privateKey)])
        ->map(fn (Server $server) => new SentinelMetricsClient($server));

    SentinelMetricsClient::prefetchConcurrently($clients, '24h');

    foreach ($clients as $client) {
        expect($client->summary()['cpu'])->toBe(12.5)
            ->and($client->containersCurrent())->toHaveCount(1)
            ->and($client->history('cpu', '24h'))->toBe([[300000, 12.5]])
            ->and($client->history('disk', '24h'))->toBe([[300000, 40.0]])
            ->and($client->networkHistory('24h')['tx'])->toBe([[300000, 2.0]]);
    }
    expect($commands)->toHaveCount(2)
        ->and($commands[0])->toContain('/api/summary', '/api/containers/current', '/api/load/history', '/api/network/history', '-H @-')
        ->and($commands[0])->toContain('/api/disk/history?from=')
        ->and($commands[0])->toContain('mount=%2F%2C%2Fapp%2Fdb%2C%2Fdata%2Fcoolify%2C%2Fdata');
});

it('does not fetch each metric again when the batch fails', function () {
    $commands = 0;
    Process::fake(function () use (&$commands) {
        $commands++;

        return Process::result('', 'ssh: connect to host failed', 255);
    });
    $client = new SentinelMetricsClient(prefetchServer($this->team, $this->privateKey));

    SentinelMetricsClient::prefetchConcurrently([$client], '24h');

    expect($client->summary())->toBeNull()
        ->and($client->containersCurrent())->toBe([])
        ->and($client->history('cpu', '24h'))->toBe([])
        ->and($client->networkHistory('24h'))->toBe(['rx' => [], 'tx' => []])
        ->and($commands)->toBe(1);
});

it('keeps the other metrics when Sentinel fails one endpoint', function () {
    $commands = 0;
    Process::fake(function () use (&$commands) {
        $commands++;

        return Process::result(fleetBatchOutput(summary: '404 page not found'));
    });
    $client = new SentinelMetricsClient(prefetchServer($this->team, $this->privateKey));

    SentinelMetricsClient::prefetchConcurrently([$client], '24h');

    expect($client->summary())->toBeNull()
        ->and($client->history('cpu', '24h'))->toBe([[300000, 12.5]])
        ->and($commands)->toBe(1);
});

it('reads cached responses on the next load without SSH', function () {
    $commands = 0;
    Process::fake(function () use (&$commands) {
        $commands++;

        return Process::result(fleetBatchOutput());
    });
    $server = prefetchServer($this->team, $this->privateKey);
    SentinelMetricsClient::prefetchConcurrently([new SentinelMetricsClient($server)], '24h');

    $next = new SentinelMetricsClient($server);
    SentinelMetricsClient::prefetchConcurrently([$next], '24h');

    expect($next->history('memory', '24h'))->toBe([[300000, 100.0]])
        ->and($commands)->toBe(1);
});

it('runs the batched script with sudo for a non-root user', function () {
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $commands[] = remoteProcessScript($process);

        return Process::result(fleetBatchOutput());
    });
    $server = prefetchServer($this->team, $this->privateKey);
    $server->forceFill(['user' => 'deploy'])->saveQuietly();

    SentinelMetricsClient::prefetchConcurrently([new SentinelMetricsClient($server)], '24h');

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toContain('sudo docker exec -i coolify-sentinel sh -s')
        ->and($commands[0])->not->toContain('sudo curl');
});

it('keeps the other metrics when the last curl of the batch fails', function () {
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $commands[] = remoteProcessScript($process);

        return Process::result(output: fleetBatchOutput(), exitCode: str_contains(end($commands), "\ntrue\nCOOLIFY_SENTINEL_SCRIPT") ? 0 : 28);
    });
    $client = new SentinelMetricsClient(prefetchServer($this->team, $this->privateKey));

    SentinelMetricsClient::prefetchConcurrently([$client], '24h');

    expect($client->summary()['cpu'])->toBe(12.5)
        ->and($client->history('cpu', '24h'))->toBe([[300000, 12.5]])
        ->and($commands)->toHaveCount(1);
});
