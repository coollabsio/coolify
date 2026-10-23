<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $key = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $this->server->settings->forceFill(['is_metrics_enabled' => true])->saveQuietly();
    $this->server->refresh();
});

it('fetches several metric types in one SSH command', function () {
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(implode("\x1e", [
            '[{"time":1000,"percent":12.5}]',
            '[{"time":1000,"usedPercent":40}]',
            '[{"time":1000,"mount":"/","usedPercent":70}]',
        ]));
    });

    $this->server->prefetchMetrics(['cpu', 'memory', 'disk'], 5);

    expect($this->server->getCpuMetrics(5))->toBe([[1000, 12.5]])
        ->and($this->server->getMemoryMetrics(5))->toBe([[1000, 40.0]])
        ->and($this->server->getDiskMetrics(5))->toBe([[1000, 70.0]])
        ->and($commands)->toHaveCount(1)
        ->and($commands[0])->toContain('/api/cpu/history', '/api/memory/history', '/api/disk/history', '-H @-')
        ->and($commands[0])->not->toContain('Bearer '.$this->server->settings->sentinel_token.'"');
});

it('keeps the Sentinel error of one prefetched metric', function () {
    Process::fake(fn () => Process::result("[{\"time\":1000,\"percent\":1}]\x1e{\"error\":\"Unauthorized\"}"));

    $this->server->prefetchMetrics(['cpu', 'memory'], 5);

    expect($this->server->getCpuMetrics(5))->toBe([[1000, 1.0]])
        ->and(fn () => $this->server->getMemoryMetrics(5))->toThrow(Exception::class, 'Unauthorized');
});

it('falls back to one fetch per metric when the batch fails', function () {
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $commands[] = $process->command;

        return count($commands) === 1
            ? Process::result('', 'ssh: connect to host failed', 1)
            : Process::result('[{"time":2000,"percent":5}]');
    });

    $this->server->prefetchMetrics(['cpu', 'memory'], 5);

    expect($this->server->getCpuMetrics(5))->toBe([[2000, 5.0]])
        ->and($commands)->toHaveCount(2);
});

it('runs the batched metrics script with sudo for a non-root user', function () {
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result("[]\x1e[]");
    });
    $this->server->forceFill(['user' => 'deploy'])->saveQuietly();

    $this->server->prefetchMetrics(['cpu', 'memory'], 5);

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toContain('sudo docker exec -i coolify-sentinel sh -s')
        ->and($commands[0])->toContain("\ncurl -s -H @- ")
        ->and($commands[0])->not->toContain('sudo curl')
        ->and($commands[0])->not->toContain('sudo printf');
});
