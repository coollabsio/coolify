<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Jobs\ScheduledTaskJob;
use App\Livewire\Project\Shared\GetLogs;
use App\Livewire\Server\Proxy\DynamicConfigurations;
use App\Models\Application;

function remoteOutputSource(string $path): string
{
    return file_get_contents(__DIR__.'/../../'.$path);
}

it('bounds dynamic proxy configuration files and their combined Livewire payload', function () {
    $source = remoteOutputSource('app/Livewire/Server/Proxy/DynamicConfigurations.php');

    expect($source)
        ->toContain('MAX_CONFIGURATION_FILE_SIZE_BYTES')
        ->toContain('MAX_TOTAL_CONFIGURATION_SIZE_BYTES')
        ->toContain('MAX_CONFIGURATION_FILES')
        ->toContain('head -c')
        ->toContain('$totalBytes');

    expect(DynamicConfigurations::MAX_CONFIGURATION_FILE_SIZE_BYTES)->toBe(1024 * 1024)
        ->and(DynamicConfigurations::MAX_TOTAL_CONFIGURATION_SIZE_BYTES)->toBe(5 * 1024 * 1024)
        ->and(DynamicConfigurations::MAX_CONFIGURATION_FILES)->toBe(100);
});

it('bounds regular log viewer output before it reaches PHP', function () {
    $source = remoteOutputSource('app/Livewire/Project/Shared/GetLogs.php');

    expect($source)
        ->toContain('MAX_DISPLAY_SIZE_BYTES')
        ->toContain('boundedLogCommand(')
        ->toContain('[... Output truncated at');

    $method = new ReflectionMethod(GetLogs::class, 'boundedLogCommand');
    $command = $method->invoke(new GetLogs, 'docker logs example', 100);

    expect(GetLogs::MAX_DISPLAY_SIZE_BYTES)->toBe(5 * 1024 * 1024)
        ->and($command)->toBe('(docker logs example) 2>&1 | head -c 101');
});

it('bounds docker compose files loaded from git before parsing', function () {
    $source = remoteOutputSource('app/Models/Application.php');

    expect($source)
        ->toContain('MAX_DOCKER_COMPOSE_SIZE_BYTES')
        ->toContain('MAX_DOCKER_COMPOSE_SIZE_BYTES + 1')
        ->toContain('head -c');

    expect(Application::MAX_DOCKER_COMPOSE_SIZE_BYTES)->toBe(5 * 1024 * 1024);
});

it('bounds scheduled task output before storing or notifying', function () {
    $source = remoteOutputSource('app/Jobs/ScheduledTaskJob.php');

    expect($source)
        ->toContain('MAX_OUTPUT_SIZE_BYTES')
        ->toContain('head -c {$maxOutputBytes}')
        ->toContain('[... Output truncated at');

    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('boundedTaskCommand');
    $command = $method->invoke($reflection->newInstanceWithoutConstructor(), 'printf hello');
    $failureCommand = $method->invoke($reflection->newInstanceWithoutConstructor(), "bash -c 'printf failure; exit 7'");
    exec('bash -n -c '.escapeshellarg($command), $output, $exitCode);
    exec('bash -c '.escapeshellarg($command), $commandOutput, $commandExitCode);
    exec('bash -c '.escapeshellarg($failureCommand).' 2>&1', $failureOutput, $failureExitCode);

    expect(ScheduledTaskJob::MAX_OUTPUT_SIZE_BYTES)->toBe(5 * 1024 * 1024)
        ->and($command)->toContain('head -c 5242880')
        ->and($exitCode)->toBe(0)
        ->and($commandExitCode)->toBe(0)
        ->and(implode("\n", $commandOutput))->toBe('hello')
        ->and($failureExitCode)->toBe(7)
        ->and(implode("\n", $failureOutput))->toBe('failure');
});

it('bounds proxy configuration backfill before storing it', function () {
    $source = remoteOutputSource('app/Actions/Proxy/GetProxyConfiguration.php');

    expect($source)
        ->toContain('MAX_CONFIGURATION_SIZE_BYTES')
        ->toContain('MAX_CONFIGURATION_SIZE_BYTES + 1')
        ->toContain('head -c')
        ->toContain('Proxy configuration exceeds');

    expect(GetProxyConfiguration::MAX_CONFIGURATION_SIZE_BYTES)->toBe(5 * 1024 * 1024);
});
