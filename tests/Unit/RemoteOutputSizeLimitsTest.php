<?php

use App\Actions\Proxy\GetProxyConfiguration;
use App\Jobs\ScheduledTaskJob;
use App\Livewire\Project\Shared\GetLogs;
use App\Livewire\Server\Proxy\DynamicConfigurations;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\SchemalessAttributes\SchemalessAttributes;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function remoteOutputTestServer(): Server
{
    $user = User::factory()->create();
    $team = $user->teams()->first();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    Storage::fake('ssh-keys');

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
}

it('bounds dynamic proxy configuration files and their combined Livewire payload', function () {
    $server = Mockery::mock(remoteOutputTestServer())->makePartial();
    $server->shouldReceive('proxyPath')->andReturn('/data/proxy');
    $files = collect(range(1, 101))->map(fn (int $number) => sprintf('file%03d.yml', $number))->implode("\n");

    Process::fake(function ($process) use ($files) {
        if (str_contains($process->command, 'ls -1')) {
            return Process::result(output: $files);
        }

        if (str_contains($process->command, 'file001.yml')) {
            return Process::result(output: str_repeat('x', DynamicConfigurations::MAX_CONFIGURATION_FILE_SIZE_BYTES + 1));
        }

        if (preg_match('/file00[2-6]\.yml/', $process->command)) {
            return Process::result(output: str_repeat('x', DynamicConfigurations::MAX_CONFIGURATION_FILE_SIZE_BYTES));
        }

        return Process::result(output: 'x');
    });

    $component = Mockery::mock(DynamicConfigurations::class)->makePartial();
    $component->server = $server;
    $component->shouldReceive('authorize')->once();
    $component->shouldReceive('dispatch')->andReturnSelf();
    $component->loadDynamicConfigurations();

    expect($component->contents)->toHaveCount(5)
        ->toHaveKeys(['file002|yml', 'file003|yml', 'file004|yml', 'file005|yml', 'file006|yml'])
        ->not->toHaveKeys(['file001|yml', 'file007|yml', 'file101|yml']);
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'file101.yml'));
});
it('fails explicitly when a source fixture cannot be read', function () {
    remoteOutputSource('missing-source-fixture.php');
})->throws(RuntimeException::class, 'Unable to read source fixture:');

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

it('rejects oversized docker compose files loaded from git before parsing', function () {
    $server = remoteOutputTestServer();

    $application = Mockery::mock(Application::class)->makePartial();
    $application->base_directory = '/';
    $application->docker_compose_location = '/docker-compose.yml';
    $application->setRelation('destination', (object) ['server' => $server]);
    $application->shouldReceive('generateGitImportCommands')->andReturn(['commands' => collect(['git clone example checkout'])]);
    $application->shouldReceive('getGitRemoteStatus')->andReturn(['is_accessible' => true]);
    $application->shouldReceive('save')->once();

    Process::fake(function ($process) {
        if (str_contains($process->command, 'git --version')) {
            return Process::result(output: 'git version 2.40.0');
        }

        if (str_contains($process->command, 'wc -c')) {
            return Process::result(output: '__COOLIFY_COMPOSE_TOO_LARGE__');
        }

        return Process::result();
    });

    expect(fn () => $application->loadComposeFile())
        ->toThrow(RuntimeException::class, 'Docker Compose file exceeds the 5 MiB size limit.');
    expect($application->docker_compose_raw)->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, 'head -c '.(Application::MAX_DOCKER_COMPOSE_SIZE_BYTES + 1)));
});
it('bounds scheduled task output before storing or notifying', function () {
    $source = remoteOutputSource('app/Jobs/ScheduledTaskJob.php');

    expect($source)
        ->toContain('MAX_OUTPUT_SIZE_BYTES')
        ->toContain('head -c {$readLimit}')
        ->toContain('[... Output truncated at');

    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('boundedTaskCommand');
    $command = $method->invoke($reflection->newInstanceWithoutConstructor(), 'printf hello');
    $failureCommand = $method->invoke($reflection->newInstanceWithoutConstructor(), "bash -c 'printf failure; exit 7'");
    exec('bash -n -c '.escapeshellarg($command), $output, $exitCode);
    exec('bash -c '.escapeshellarg($command), $commandOutput, $commandExitCode);
    exec('bash -c '.escapeshellarg($failureCommand).' 2>&1', $failureOutput, $failureExitCode);

    expect(ScheduledTaskJob::MAX_OUTPUT_SIZE_BYTES)->toBe(5 * 1024 * 1024)
        ->and($command)->toContain('head -c 5242881')
        ->and($exitCode)->toBe(0)
        ->and($commandExitCode)->toBe(0)
        ->and(implode("\n", $commandOutput))->toBe('hello')
        ->and($failureExitCode)->toBe(7)
        ->and(implode("\n", $failureOutput))->toBe('failure');
});

it('marks scheduled task output that exceeds the limit as truncated', function () {
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('boundedTaskCommand');
    $largeOutputCommand = 'head -c '.(ScheduledTaskJob::MAX_OUTPUT_SIZE_BYTES + 1).' /dev/zero | tr "\\0" "x"';
    $command = $method->invoke($reflection->newInstanceWithoutConstructor(), $largeOutputCommand);

    exec('bash -c '.escapeshellarg($command), $output, $exitCode);
    $taskOutput = implode("\n", $output);

    expect($exitCode)->toBe(0)
        ->and(strlen($taskOutput))->toBeGreaterThan(ScheduledTaskJob::MAX_OUTPUT_SIZE_BYTES)
        ->and($taskOutput)->toEndWith('[... Output truncated at 5MB limit ...]');
});

it('does not pass the scheduled task output wrapper through the sudo rewriter', function () {
    $source = remoteOutputSource('app/Jobs/ScheduledTaskJob.php');
    $reflection = new ReflectionClass(ScheduledTaskJob::class);
    $method = $reflection->getMethod('boundedTaskCommand');
    $command = $method->invoke($reflection->newInstanceWithoutConstructor(), 'sudo docker exec example true');
    $server = new Server(['user' => 'ubuntu']);
    $rewrittenCommand = parseCommandsByLineForSudo(collect([$command]), $server)[0];

    exec('bash -n -c '.escapeshellarg($command), $output, $exitCode);
    exec('bash -n -c '.escapeshellarg($rewrittenCommand).' 2>/dev/null', $rewrittenOutput, $rewrittenExitCode);

    expect($source)
        ->toContain("\$dockerCommand = \$this->server->isNonRoot() ? 'sudo docker' : 'docker'")
        ->toContain('instant_remote_process([$exec], $this->server, throwError: true, no_sudo: true')
        ->and($exitCode)->toBe(0)
        ->and($rewrittenExitCode)->not->toBe(0);
});

it('rejects oversized proxy configuration backfill before persistence', function () {
    $proxy = Mockery::mock(SchemalessAttributes::class);
    $proxy->shouldNotReceive('set');
    $server = Mockery::mock(remoteOutputTestServer())->makePartial();
    $server->shouldReceive('proxyPath')->andReturn('/data/proxy');
    $server->shouldReceive('getAttribute')->with('proxy')->andReturn($proxy);
    $server->shouldNotReceive('save');

    Process::fake(['*' => Process::result(output: '__COOLIFY_PROXY_CONFIG_TOO_LARGE__')]);

    $method = new ReflectionMethod(GetProxyConfiguration::class, 'backfillFromDisk');

    expect(fn () => $method->invoke(new GetProxyConfiguration, $server))
        ->toThrow(RuntimeException::class, 'Proxy configuration exceeds the 5 MiB size limit.');
    Process::assertRan(fn ($process) => str_contains($process->command, 'head -c '.(GetProxyConfiguration::MAX_CONFIGURATION_SIZE_BYTES + 1)));
});
