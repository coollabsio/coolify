<?php

use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Services\GithubRunner\GithubRunnerContainer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->execution = (new GithubRunnerExecution)->forceFill(['uuid' => 'abc123']);
    $this->config = (new GithubRunnerConfig)->forceFill([
        'docker_mode' => 'dind',
        'cpu_limit' => '2',
        'memory_limit' => '4g',
        'runner_image' => null,
    ]);
    $this->nonRootServer = Mockery::mock(Server::class)->makePartial();
    $this->nonRootServer->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
});

afterEach(function () {
    Mockery::close();
});

it('starts a privileged Docker sidecar with limits on a private network', function () {
    $commands = (new GithubRunnerContainer($this->execution, $this->config))->prepareCommands();

    expect($commands[0])->toBe('docker network create --label coolify.githubRunner=true --label coolify.githubRunnerExecution=abc123 coolify-runner-abc123')
        ->and($commands)->toContain('docker volume create --label coolify.githubRunner=true --label coolify.githubRunnerExecution=abc123 coolify-runner-abc123-work')
        ->and(end($commands))
        ->toStartWith('docker run -d --privileged --name coolify-runner-abc123-dind --network coolify-runner-abc123')
        ->toContain("--pids-limit 4096 --cpus '2' --memory '4g' --memory-swap '4g'")
        ->toContain('-v coolify-runner-abc123-sock:/var/run -v coolify-runner-abc123-work:/home/runner/_work')
        ->toContain("'".config('constants.github_runner.dind_image')."' dockerd --host=unix:///var/run/docker.sock --group=123");
});

it('passes the JIT config through a root-only env file and never mounts the host Docker socket', function () {
    $commands = (new GithubRunnerContainer($this->execution, $this->config))->startCommands('SECRET');
    $all = implode("\n", $commands);

    expect($all)->not->toContain('SECRET')
        ->toContain(base64_encode("ACTIONS_RUNNER_INPUT_JITCONFIG=SECRET\n"))
        ->not->toContain('docker.sock:/var/run/docker.sock')
        ->and($commands[1])->toBe("sh -c 'umask 077 && rm -f /data/coolify/github-runners/abc123.env && echo ".base64_encode("ACTIONS_RUNNER_INPUT_JITCONFIG=SECRET\n")." | base64 -d > /data/coolify/github-runners/abc123.env'")
        ->and(collect($commands)->first(fn (string $command) => str_starts_with($command, 'docker run -d --name coolify-runner-abc123 ')))
        ->toStartWith('docker run -d --name coolify-runner-abc123 --network coolify-runner-abc123')
        ->toContain('--env-file /data/coolify/github-runners/abc123.env ')
        ->toContain('-e DOCKER_HOST=unix:///var/run/docker.sock')
        ->toEndWith("'".config('constants.github_runner.image')."' /home/runner/run.sh")
        ->and(end($commands))->toBe('rm -f /data/coolify/github-runners/abc123.env');
});

it('starts the Docker sidecar with the Sysbox runtime instead of privileged mode', function () {
    $this->config->forceFill(['docker_mode' => 'sysbox']);
    $container = new GithubRunnerContainer($this->execution, $this->config);
    $commands = [...$container->prepareCommands(), ...$container->startCommands('SECRET')];
    $all = implode("\n", $commands);

    expect($container->usesDind())->toBeTrue()
        ->and($all)->not->toContain('--privileged')
        ->toContain('docker run -d --runtime=sysbox-runc --name coolify-runner-abc123-dind --network coolify-runner-abc123')
        ->toContain('-e DOCKER_HOST=unix:///var/run/docker.sock');
});

it('starts only an unprivileged runner when Docker is disabled', function () {
    $this->config->forceFill(['docker_mode' => 'none', 'cpu_limit' => null, 'memory_limit' => null]);
    $container = new GithubRunnerContainer($this->execution, $this->config);
    $all = implode("\n", [...$container->prepareCommands(), ...$container->startCommands('SECRET')]);

    expect($all)->not->toContain('--privileged')
        ->not->toContain('DOCKER_HOST')
        ->not->toContain('-dind')
        ->not->toContain('--cpus');
});

it('uses the latest official runner image by default', function () {
    expect(config('constants.github_runner.image'))->toBe('ghcr.io/actions/actions-runner:latest');
});

it('pulls the runner image every time so that a moving tag stays current', function () {
    $commands = (new GithubRunnerContainer($this->execution, $this->config))->prepareCommands();
    $image = "'".config('constants.github_runner.image')."'";
    $dindImage = "'".config('constants.github_runner.dind_image')."'";

    expect($commands)
        // Falls back to the local image when the registry cannot be reached.
        ->toContain("docker pull {$image} || docker image inspect {$image} > /dev/null")
        ->toContain("docker image inspect {$dindImage} > /dev/null 2>&1 || docker pull {$dindImage}");
});

it('quotes a custom runner image', function () {
    $this->config->forceFill(['runner_image' => 'ghcr.io/acme/runner:1.0']);
    $commands = (new GithubRunnerContainer($this->execution, $this->config))->startCommands('SECRET');

    expect(collect($commands)->first(fn (string $command) => str_starts_with($command, 'docker run -d --name coolify-runner-abc123 ')))
        ->toEndWith("'ghcr.io/acme/runner:1.0' /home/runner/run.sh");
});

it('keeps every command valid for servers with a non-root user', function () {
    $container = new GithubRunnerContainer($this->execution, $this->config);
    $commands = collect([
        ...$container->prepareCommands(),
        $container->dockerReadyCommand(),
        ...$container->startCommands('SECRET'),
        ...GithubRunnerContainer::cleanupCommands('abc123'),
        GithubRunnerContainer::listCommand(),
    ]);

    $parsed = parseCommandsByLineForSudo($commands, $this->nonRootServer);

    expect($parsed)
        ->toContain('sudo docker pull '."'".config('constants.github_runner.image')."'".' || sudo docker image inspect '."'".config('constants.github_runner.image')."'".' > /dev/null')
        ->toContain("sudo sh -c 'umask 077 && rm -f /data/coolify/github-runners/abc123.env && echo ".base64_encode("ACTIONS_RUNNER_INPUT_JITCONFIG=SECRET\n")." | base64 -d > /data/coolify/github-runners/abc123.env'")
        ->toContain('echo '.base64_encode(GithubRunnerContainer::pullRequestHookScript()).' | sudo base64 -d | sudo tee /data/coolify/github-runners/abc123-job-started.sh > /dev/null')
        ->toContain('sudo chmod 644 /data/coolify/github-runners/abc123-job-started.sh')
        ->toContain('sudo docker rm -f -v coolify-runner-abc123 coolify-runner-abc123-dind > /dev/null 2>&1 || sudo true')
        ->toContain("sudo docker exec coolify-runner-abc123-dind docker version --format '{{.Server.Version}}'")
        ->toContain('sudo docker ps -a --filter label=coolify.githubRunner=true --format \'{{.Label "coolify.githubRunnerExecution"}} {{.Names}} {{.State}}\'');

    $linesWithoutSudo = collect($parsed)->reject(fn (string $line) => str_starts_with($line, 'sudo ') || str_starts_with($line, 'echo '));
    expect($linesWithoutSudo)->toBeEmpty();
});

it('installs a job-started hook that refuses pull request jobs by default', function () {
    $commands = (new GithubRunnerContainer($this->execution, $this->config))->startCommands('SECRET');
    $hookFile = '/data/coolify/github-runners/abc123-job-started.sh';

    expect($commands)->toContain('echo '.base64_encode(GithubRunnerContainer::pullRequestHookScript()).' | base64 -d | tee '.$hookFile.' > /dev/null')
        ->toContain("chmod 644 {$hookFile}")
        ->and(collect($commands)->first(fn (string $command) => str_starts_with($command, 'docker run -d --name coolify-runner-abc123 ')))
        ->toContain("-v {$hookFile}:/home/runner/coolify-job-started.sh:ro -e ACTIONS_RUNNER_HOOK_JOB_STARTED=/home/runner/coolify-job-started.sh")
        ->and(GithubRunnerContainer::cleanupCommands('abc123'))->toContain("rm -f /data/coolify/github-runners/abc123.env {$hookFile}");
});

it('does not install the pull request hook when pull request jobs are allowed', function () {
    $this->config->forceFill(['allow_pull_requests' => true]);
    $all = implode("\n", (new GithubRunnerContainer($this->execution, $this->config))->startCommands('SECRET'));

    expect($all)->not->toContain('job-started')
        ->not->toContain('ACTIONS_RUNNER_HOOK_JOB_STARTED');
});

it('fails pull request jobs in the job-started hook and lets other jobs run', function (?string $eventName, int $expectedExitCode) {
    $hook = tempnam(sys_get_temp_dir(), 'hook');
    file_put_contents($hook, GithubRunnerContainer::pullRequestHookScript());

    $environment = $eventName === null ? [] : ['GITHUB_EVENT_NAME' => $eventName];
    $process = new Process(['bash', $hook], env: ['PATH' => getenv('PATH'), ...$environment]);
    $process->run();
    unlink($hook);

    expect($process->getExitCode())->toBe($expectedExitCode);
})->with([
    'pull_request' => ['pull_request', 1],
    'pull_request_target' => ['pull_request_target', 1],
    'pull_request_review' => ['pull_request_review', 1],
    'pull_request_review_comment' => ['pull_request_review_comment', 1],
    'unknown event fails closed' => [null, 1],
    'push' => ['push', 0],
    'workflow_dispatch' => ['workflow_dispatch', 0],
    'schedule' => ['schedule', 0],
    'merge_group' => ['merge_group', 0],
]);
