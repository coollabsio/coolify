<?php

use App\Actions\V5\Server\RemoveBootstrapMarker;
use App\Models\V5\Server as V5Server;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    resetV5DashboardTestState();
    createSharedUserAndTeamTables();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createRemoveMarkerServer(array $attributes = []): V5Server
{
    [$user, $team] = createV5UserWithTeam();

    return V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 2222,
        'status' => 'installed',
        ...$attributes,
    ]);
}

/**
 * @param  array<int, mixed>  $command
 */
function removeMarkerSshKeyPath(array $command): ?string
{
    $index = array_search('-i', $command, true);

    return $index === false ? null : ($command[$index + 1] ?? null);
}

it('returns false and runs no ssh command when the server has no private key', function () {
    Process::fake();

    $server = createRemoveMarkerServer(['private_key_id' => null]);

    expect(RemoveBootstrapMarker::run($server))->toBeFalse();

    Process::assertNothingRan();
});

it('removes the on-host bootstrap identity over ssh and deletes the temporary key file', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $server = createRemoveMarkerServer();
    $privateKey = createV5PrivateKey($server->team, 'Marker Removal Key');
    $server->update(['private_key_id' => $privateKey->id]);

    expect(RemoveBootstrapMarker::run($server->fresh()))->toBeTrue();

    $keyPath = null;

    Process::assertRan(function ($process) use (&$keyPath): bool {
        $command = $process->command;

        if (! is_array($command) || ($command[0] ?? null) !== 'ssh') {
            return false;
        }

        $script = end($command);

        if (! str_contains($script, '$SUDO rm -f /etc/coolify/v5-node.json /etc/coolify/host-jwt /etc/systemd/system/coold.service.d/10-flux.conf')) {
            return false;
        }

        if (! in_array('root@203.0.113.10', $command, true) || ! in_array('2222', $command, true)) {
            return false;
        }

        $keyPath = removeMarkerSshKeyPath($command);

        return true;
    });

    expect($keyPath)->not->toBeNull()
        ->and(file_exists($keyPath))->toBeFalse();
});

it('returns false but still deletes the temporary key file when the ssh command fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'connection refused', exitCode: 255)]);

    $server = createRemoveMarkerServer();
    $privateKey = createV5PrivateKey($server->team, 'Marker Removal Key');
    $server->update(['private_key_id' => $privateKey->id]);

    expect(RemoveBootstrapMarker::run($server->fresh()))->toBeFalse();

    $keyPath = null;

    Process::assertRan(function ($process) use (&$keyPath): bool {
        if (! is_array($process->command) || ($process->command[0] ?? null) !== 'ssh') {
            return false;
        }

        $keyPath = removeMarkerSshKeyPath($process->command);

        return true;
    });

    expect($keyPath)->not->toBeNull()
        ->and(file_exists($keyPath))->toBeFalse();
});
