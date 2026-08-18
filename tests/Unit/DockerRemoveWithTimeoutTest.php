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
