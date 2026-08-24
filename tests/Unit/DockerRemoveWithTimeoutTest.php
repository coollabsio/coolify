<?php

use App\Jobs\RemoveContainerJob;
use App\Models\Server;
use Symfony\Component\Process\Process;

it('bounds forced container removal and reports a timeout marker', function () {
    expect(dockerRemoveCommandWithTimeout('container name'))
        ->toStartWith("bash -c '")
        ->toContain('timeout -k 10s 60s docker rm -f')
        ->toContain('__COOLIFY_CONTAINER_REMOVE_TIMEOUT__');
});

it('uses timeout syntax supported by coreutils and busybox', function () {
    expect(dockerRemoveCommandWithTimeout('container-name'))
        ->toContain('command -v timeout')
        ->toContain('timeout -k 10s 60s')
        ->not->toContain('--kill-after');
});

it('preserves bounded cleanup when commands are adapted for non-root servers', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->shouldReceive('setAttribute')->andReturnSelf();
    $server->user = 'ubuntu';

    $commands = parseCommandsByLineForSudo(
        collect([dockerRemoveCommandWithTimeout('container-name')]),
        $server
    );
    $command = $commands[0];

    expect($command)
        ->toStartWith("sudo bash -c '")
        ->toContain('timeout -k 10s 60s docker rm -f')
        ->toContain('__COOLIFY_CONTAINER_REMOVE_TIMEOUT__');
});

it('escapes container names in bounded removal commands', function () {
    $containerName = "container'; reboot; '";
    $directory = sys_get_temp_dir().'/coolify-docker-remove-'.bin2hex(random_bytes(4));
    $captureFile = $directory.'/arguments';
    mkdir($directory);
    file_put_contents($directory.'/docker', "#!/bin/sh\nprintf '%s\\n' \"\$@\" > \"\$CAPTURE_FILE\"\n");
    chmod($directory.'/docker', 0755);

    $process = new Process(['/bin/sh', '-c', dockerRemoveCommandWithTimeout($containerName)], env: [
        'PATH' => $directory.':'.getenv('PATH'),
        'CAPTURE_FILE' => $captureFile,
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and(file_get_contents($captureFile))->toBe("rm\n-f\n{$containerName}\n");

    unlink($captureFile);
    unlink($directory.'/docker');
    rmdir($directory);
});

it('succeeds when the container was already removed', function () {
    $directory = sys_get_temp_dir().'/coolify-docker-remove-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/docker', "#!/bin/sh\necho 'Error response from daemon: No such container: container-name' >&2\nexit 1\n");
    chmod($directory.'/docker', 0755);

    $process = new Process(['/bin/sh', '-c', dockerRemoveCommandWithTimeout('container-name')], env: [
        'PATH' => $directory.':'.getenv('PATH'),
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue();

    unlink($directory.'/docker');
    rmdir($directory);
});

it('reports a timeout when timeout output also says the container is missing', function () {
    $directory = sys_get_temp_dir().'/coolify-docker-remove-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/timeout', "#!/bin/sh\necho 'Error response from daemon: No such container: container-name'\nexit 124\n");
    chmod($directory.'/timeout', 0755);

    $process = new Process(['/bin/sh', '-c', dockerRemoveCommandWithTimeout('container-name')], env: [
        'PATH' => $directory.':'.getenv('PATH'),
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(124)
        ->and($process->getOutput())->toContain('__COOLIFY_CONTAINER_REMOVE_TIMEOUT__');

    unlink($directory.'/timeout');
    rmdir($directory);
});

it('fails when Docker cannot remove an existing container', function () {
    $directory = sys_get_temp_dir().'/coolify-docker-remove-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/docker', "#!/bin/sh\necho 'Error response from daemon: removal already in progress' >&2\nexit 1\n");
    chmod($directory.'/docker', 0755);

    $process = new Process(['/bin/sh', '-c', dockerRemoveCommandWithTimeout('container-name')], env: [
        'PATH' => $directory.':'.getenv('PATH'),
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('removal already in progress');

    unlink($directory.'/docker');
    rmdir($directory);
});

it('makes regular container removal idempotent without hiding other failures', function () {
    $directory = sys_get_temp_dir().'/coolify-docker-remove-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/docker', "#!/bin/sh\necho \"\$DOCKER_ERROR\" >&2\nexit 1\n");
    chmod($directory.'/docker', 0755);

    $missingContainer = new Process(['/bin/sh', '-c', dockerRemoveCommand('container name')], env: [
        'PATH' => $directory.':'.getenv('PATH'),
        'DOCKER_ERROR' => 'Error response from daemon: No such container: container name',
    ]);
    $missingContainer->run();
    $realFailure = new Process(['/bin/sh', '-c', dockerRemoveCommand('container name')], env: [
        'PATH' => $directory.':'.getenv('PATH'),
        'DOCKER_ERROR' => 'Error response from daemon: removal already in progress',
    ]);
    $realFailure->run();

    expect($missingContainer->isSuccessful())->toBeTrue()
        ->and($realFailure->isSuccessful())->toBeFalse();

    unlink($directory.'/docker');
    rmdir($directory);
});

it('makes network removal idempotent without hiding other failures', function () {
    $directory = sys_get_temp_dir().'/coolify-docker-remove-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/docker', "#!/bin/sh\necho \"\$DOCKER_ERROR\" >&2\nexit 1\n");
    chmod($directory.'/docker', 0755);

    $missingNetwork = new Process(['/bin/sh', '-c', dockerNetworkRemoveCommand('network name')], env: [
        'PATH' => $directory.':'.getenv('PATH'),
        'DOCKER_ERROR' => 'Error response from daemon: network network name not found',
    ]);
    $missingNetwork->run();
    $realFailure = new Process(['/bin/sh', '-c', dockerNetworkRemoveCommand('network name')], env: [
        'PATH' => $directory.':'.getenv('PATH'),
        'DOCKER_ERROR' => 'Error response from daemon: network has active endpoints',
    ]);
    $realFailure->run();

    expect($missingNetwork->isSuccessful())->toBeTrue()
        ->and($realFailure->isSuccessful())->toBeFalse();

    unlink($directory.'/docker');
    rmdir($directory);
});

it('uses idempotent commands in strict cleanup paths', function () {
    $root = dirname(__DIR__, 2);

    expect(file_get_contents($root.'/app/Jobs/DatabaseBackupJob.php'))
        ->toContain('dockerRemoveCommand("backup-of-{$this->backup_log_uuid}")')
        ->and(file_get_contents($root.'/app/Actions/Database/StopDatabaseProxy.php'))
        ->toContain('dockerRemoveCommand("{$uuid}-proxy")')
        ->and(file_get_contents($root.'/app/Actions/Destination/RemoveStandaloneDockerNetwork.php'))
        ->toContain('dockerNetworkRemoveCommand($destination->network)')
        ->and(file_get_contents($root.'/app/Livewire/Destination/Show.php'))
        ->toContain('dockerNetworkRemoveCommand($this->destination->network)')
        ->and(file_get_contents($root.'/app/Listeners/ProxyStatusChangedNotification.php'))
        ->toContain("dockerRemoveCommand('coolify-proxy')")
        ->and(file_get_contents($root.'/app/Actions/Application/StopApplicationOneServer.php'))
        ->toContain('dockerRemoveCommand($containerName)');
});

it('configures deferred removal attempts to outlive the shell timeout', function () {
    $job = new RemoveContainerJob(123, 'container-name');

    expect($job->serverId)->toBe(123)
        ->and($job->containerName)->toBe('container-name')
        ->and($job->timeout)->toBeGreaterThan(60)
        ->and($job->tries)->toBeGreaterThan(1);
});

it('continues deployments and schedules deferred cleanup after a removal timeout', function () {
    $source = file_get_contents(dirname(__DIR__, 2).'/app/Jobs/ApplicationDeploymentJob.php');

    expect($source)
        ->toContain('dockerRemoveCommandWithTimeout($containerName)')
        ->toContain('timed out after 60 seconds. The deployment will continue')
        ->toContain('RemoveContainerJob::dispatch($this->server->id, $containerName)')
        ->toContain('->delay(now()->addMinutes(5))');
});
