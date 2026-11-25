<?php

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    $fixture = deployTriggerFixture();
    $fixture['server']->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $fixture['team']->id])->id]);
    Server::flushIdentityMap();
    $this->server = $fixture['server']->fresh();
    $this->scripts = collect();
});

function fakeCleanupProcesses($scripts, ?Closure $prune = null): void
{
    Process::fake(function (PendingProcess $process) use ($scripts, $prune) {
        $script = remoteProcessScript($process);
        $scripts->push($script);
        if ($prune && str_contains($script, 'mirror prune')) {
            return $prune();
        }

        return Process::result(output: '');
    });
}

it('prunes git mirrors during docker cleanup', function () {
    fakeCleanupProcesses($this->scripts);

    (new DockerCleanupJob($this->server))->handle();

    $prune = $this->scripts->first(fn (string $script) => str_contains($script, 'coolify-affected mirror prune'));
    expect($prune)->not->toBeNull()
        ->and($prune)->toContain('--days 14')
        ->and($prune)->toContain('docker volume inspect coolify-git-mirrors')
        ->and($prune)->toContain('-v coolify-git-mirrors:/mirrors');
});

it('keeps the cleanup successful when pruning fails', function () {
    fakeCleanupProcesses($this->scripts, fn () => Process::result(errorOutput: 'not found', exitCode: 127));

    (new DockerCleanupJob($this->server))->handle();

    expect(DockerCleanupExecution::query()->where('server_id', $this->server->id)->sole()->status)->toBe('success');
});
