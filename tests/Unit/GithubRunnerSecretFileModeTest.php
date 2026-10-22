<?php

use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Services\GithubRunner\GithubRunnerContainer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->secretFileExecution = (new GithubRunnerExecution)->forceFill(['uuid' => 'abc123']);
    $this->secretFileConfig = (new GithubRunnerConfig)->forceFill(['docker_mode' => 'none', 'allow_pull_requests' => true]);
    $this->secretFileDirectory = sys_get_temp_dir().'/runner-secret-'.bin2hex(random_bytes(4));
    mkdir($this->secretFileDirectory);
});

afterEach(function () {
    array_map('unlink', glob($this->secretFileDirectory.'/*') ?: []);
    rmdir($this->secretFileDirectory);
    Mockery::close();
});

/**
 * Runs the start commands up to the docker run line in a temp directory under umask 022 and
 * returns the mode of the env file right after the command that wrote the secret into it.
 */
function runSecretFileCommandsAndReadMode(array $commands, string $directory): int
{
    $envFile = GithubRunnerContainer::SECRETS_DIRECTORY.'/abc123.env';
    foreach ($commands as $command) {
        if (str_starts_with($command, 'docker ')) {
            break;
        }
        $process = Process::fromShellCommandline('umask 022; '.str_replace(GithubRunnerContainer::SECRETS_DIRECTORY, $directory, $command));
        $process->mustRun();

        clearstatcache();
        $localEnvFile = str_replace(GithubRunnerContainer::SECRETS_DIRECTORY, $directory, $envFile);
        if (file_exists($localEnvFile) && str_contains((string) file_get_contents($localEnvFile), 'SECRET')) {
            return fileperms($localEnvFile) & 0777;
        }
    }

    throw new RuntimeException('No command wrote the env file.');
}

it('creates the JIT secret file readable only by root as soon as the secret is written', function () {
    $commands = (new GithubRunnerContainer($this->secretFileExecution, $this->secretFileConfig))->startCommands('SECRET');

    expect(runSecretFileCommandsAndReadMode($commands, $this->secretFileDirectory))->toBe(0600)
        ->and(file_get_contents($this->secretFileDirectory.'/abc123.env'))->toBe("ACTIONS_RUNNER_INPUT_JITCONFIG=SECRET\n");
});

it('replaces a world-readable secret file left by an interrupted start instead of writing into it', function () {
    file_put_contents($this->secretFileDirectory.'/abc123.env', 'OLD');
    chmod($this->secretFileDirectory.'/abc123.env', 0644);
    $commands = (new GithubRunnerContainer($this->secretFileExecution, $this->secretFileConfig))->startCommands('SECRET');

    expect(runSecretFileCommandsAndReadMode($commands, $this->secretFileDirectory))->toBe(0600)
        ->and(file_get_contents($this->secretFileDirectory.'/abc123.env'))->toBe("ACTIONS_RUNNER_INPUT_JITCONFIG=SECRET\n");
});

it('writes the secret file as root in one shell script for a non-root SSH user', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $commands = (new GithubRunnerContainer($this->secretFileExecution, $this->secretFileConfig))->startCommands('SECRET');

    $parsed = collect(parseCommandsByLineForSudo(collect($commands), $server));
    $writeLine = $parsed->first(fn (string $line) => str_contains($line, base64_encode("ACTIONS_RUNNER_INPUT_JITCONFIG=SECRET\n")));

    expect($writeLine)->toStartWith("sudo sh -c 'umask 077 && ")
        ->and(isSingleSudoShellScript($writeLine))->toBeTrue()
        ->and($parsed->implode("\n"))->not->toContain('SECRET')
        ->not->toContain('tee /data/coolify/github-runners/abc123.env');
});
