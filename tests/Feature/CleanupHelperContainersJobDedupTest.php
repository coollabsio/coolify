<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\CleanupHelperContainersJob;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
});

it('queues one helper container cleanup per server', function () {
    Queue::fake();
    $first = Server::factory()->create(['team_id' => $this->team->id]);
    $second = Server::factory()->create(['team_id' => $this->team->id]);

    CleanupHelperContainersJob::dispatch($first);
    CleanupHelperContainersJob::dispatch($second);
    CleanupHelperContainersJob::dispatch($first);

    Queue::assertPushed(CleanupHelperContainersJob::class, 2);
});

it('removes only helper containers of finished deployments', function () {
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['constants.ssh.mux_enabled' => false]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    foreach (['finished-uuid' => ApplicationDeploymentStatus::FINISHED, 'failed-uuid' => ApplicationDeploymentStatus::FAILED, 'running-uuid' => ApplicationDeploymentStatus::IN_PROGRESS] as $uuid => $status) {
        ApplicationDeploymentQueue::create(['application_id' => '1', 'deployment_uuid' => $uuid, 'server_id' => $server->id, 'status' => $status->value]);
    }
    $containers = collect([
        'finished-uuid' => 'aaa111',
        'failed-uuid' => 'bbb222',
        'running-uuid' => 'ccc333',
        'volume-upload-abc' => 'ddd444',
        'volume-backup-abc' => 'eee555',
        'backup-of-abc' => 'fff666',
    ])->map(fn (string $id, string $name): array => ['ID' => $id, 'Names' => $name, 'Image' => 'ghcr.io/coollabsio/coolify-helper:1.0.13'])->values();

    $removed = [];
    Process::fake(function (PendingProcess $process) use ($containers, &$removed) {
        $script = remoteProcessScript($process);
        if (preg_match("/docker container rm -f '?([a-z0-9]+)/", $script, $match) === 1) {
            $removed[] = $match[1];
        }

        return Process::result(str_contains($script, 'docker container ps') ? $containers->toJson() : '');
    });

    (new CleanupHelperContainersJob($server))->handle();

    expect($removed)->toBe(['aaa111', 'bbb222']);
});
