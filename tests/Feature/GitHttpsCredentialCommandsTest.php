<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/**
 * Serves the bare repositories in $root/srv with `git http-backend` (smart HTTP) and answers 401 without the Basic credentials.
 */
function startGitHttpsCredentialServer(string $root, string $credentials): array
{
    file_put_contents($root.'/router.php', <<<'PHP'
        <?php
        if (! hash_equals('Basic '.base64_encode(getenv('GIT_TEST_CREDENTIALS')), $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
            header('WWW-Authenticate: Basic realm="git"');
            http_response_code(401);

            return true;
        }
        $backend = proc_open(['git', 'http-backend'], [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'a']], $pipes, null, [
            'GIT_PROJECT_ROOT' => __DIR__.'/srv',
            'GIT_HTTP_EXPORT_ALL' => '1',
            'GIT_PROTOCOL' => $_SERVER['HTTP_GIT_PROTOCOL'] ?? '',
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
            'PATH_INFO' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
            'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? '',
            'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? '',
            'HTTP_CONTENT_ENCODING' => $_SERVER['HTTP_CONTENT_ENCODING'] ?? '',
            'PATH' => getenv('PATH'),
        ]);
        fwrite($pipes[0], file_get_contents('php://input'));
        fclose($pipes[0]);
        [$head, $body] = explode("\r\n\r\n", stream_get_contents($pipes[1]), 2) + [1 => ''];
        proc_close($backend);
        foreach (explode("\r\n", $head) as $line) {
            str_starts_with($line, 'Status:') ? http_response_code((int) substr($line, 8)) : header($line);
        }
        echo $body;

        return true;
        PHP);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $server = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", $root.'/router.php'], $root, ['GIT_TEST_CREDENTIALS' => $credentials]);
    $server->start();
    for ($i = 0; $i < 100 && ! @fsockopen('127.0.0.1', $port); $i++) {
        usleep(50_000);
    }

    return [$server, "http://127.0.0.1:{$port}"];
}

/**
 * Runs the commands like a server does over SSH: one `bash -se` that reads them from stdin.
 */
function runGitHttpsCredentialCommands(string $root, string $commands): Process
{
    $process = new Process(['bash', '-se'], env: ['PATH' => $root.'/bin:'.getenv('PATH'), 'GIT_TERMINAL_PROMPT' => '0']);
    $process->setInput($commands."\n");
    $process->setTimeout(60);
    $process->run();

    return $process;
}

function gitHttpsCredentialApplication(string $repository, array $attributes = []): Application
{
    $application = Application::factory()->create([
        'git_repository' => $repository,
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'build_pack' => 'dockercompose',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        ...$attributes,
    ]);
    $application->forceFill(['git_https_username' => 'deploy-bot', 'git_https_password' => 'plain-test-value'])->save();
    $application->settings->update(['is_git_submodules_enabled' => true, 'is_git_lfs_enabled' => false]);

    return $application->fresh();
}

function gitHttpsCredentialNonRootServer(): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $server->shouldReceive('setAttribute')->andReturnSelf();

    return $server;
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->root = sys_get_temp_dir().'/coolify-git-https-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0755, true);
    mkdir($this->root.'/srv');
    mkdir($this->root.'/work');
    file_put_contents($this->root.'/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
    // Runs `docker exec <container> bash -c '...'` on the host, so the helper container commands run unchanged.
    file_put_contents($this->root.'/bin/docker', "#!/bin/sh\nshift 2\nexec \"\$@\"\n");
    chmod($this->root.'/bin/sudo', 0755);
    chmod($this->root.'/bin/docker', 0755);

    [$this->server, $this->baseUrl] = startGitHttpsCredentialServer($this->root, 'deploy-bot:plain-test-value');

    $git = fn (string $dir, array $args) => (new Process(['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', '-c', 'protocol.file.allow=always', ...$args], $dir))->mustRun();

    mkdir($this->root.'/sub');
    file_put_contents($this->root.'/sub/lib.txt', 'submodule file');
    $git($this->root.'/sub', ['init', '-q', '-b', 'main']);
    $git($this->root.'/sub', ['add', '-A']);
    $git($this->root.'/sub', ['commit', '-qm', 'sub']);
    $git($this->root.'/sub', ['clone', '-q', '--bare', '.', $this->root.'/srv/sub.git']);

    mkdir($this->root.'/src');
    $this->compose = "services:\n  web:\n    image: nginx\n";
    file_put_contents($this->root.'/src/docker-compose.yml', $this->compose);
    $git($this->root.'/src', ['init', '-q', '-b', 'main']);
    $git($this->root.'/src', ['submodule', 'add', '-q', $this->root.'/srv/sub.git', 'lib']);
    $git($this->root.'/src', ['config', '-f', '.gitmodules', 'submodule.lib.url', $this->baseUrl.'/sub.git']);
    $git($this->root.'/src', ['add', '-A']);
    $git($this->root.'/src', ['commit', '-qm', 'app']);
    $this->commit = trim($git($this->root.'/src', ['rev-parse', 'HEAD'])->getOutput());
    file_put_contents($this->root.'/src/pr.txt', 'pull request');
    $git($this->root.'/src', ['add', '-A']);
    $git($this->root.'/src', ['commit', '-qm', 'pr']);
    $git($this->root.'/src', ['clone', '-q', '--bare', '.', $this->root.'/srv/app.git']);
    $git($this->root.'/srv/app.git', ['update-ref', 'refs/pull/7/head', 'HEAD']);
    $git($this->root.'/srv/app.git', ['update-ref', 'refs/heads/main', $this->commit]);

    $this->repository = $this->baseUrl.'/app.git';
});

afterEach(function () {
    $this->server->stop(0);
    (new Process(['rm', '-rf', $this->root]))->run();
    Mockery::close();
});

test('the deployment clone uses the HTTPS credential for the repository, the commit, and submodules without saving it', function (int $pullRequestId) {
    $application = gitHttpsCredentialApplication($this->repository, ['git_commit_sha' => $pullRequestId === 0 ? $this->commit : 'HEAD']);
    $checkout = $this->root.'/work/checkout';

    ['commands' => $commands] = $application->generateGitImportCommands('deploy-uuid', pull_request_id: $pullRequestId, git_type: 'gitea', exec_in_docker: true, custom_base_dir: $checkout);
    $process = runGitHttpsCredentialCommands($this->root, $commands);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $savedConfig = file_get_contents($checkout.'/.git/config').file_get_contents($checkout.'/.git/modules/lib/config');
    expect(file_get_contents($checkout.'/lib/lib.txt'))->toBe('submodule file')
        ->and(file_exists($checkout.'/pr.txt'))->toBe($pullRequestId !== 0)
        ->and($savedConfig)->not->toContain('plain-test-value')
        ->and($savedConfig)->not->toContain($application->gitHttpsCredentialBasicToken())
        ->and($savedConfig)->not->toContain('extraHeader');
})->with([
    'pinned commit' => [0],
    'pull request preview' => [7],
]);

test('git fails without the right HTTPS credential', function () {
    $application = gitHttpsCredentialApplication($this->repository);
    $application->forceFill(['git_https_password' => 'wrong-token'])->save();

    ['commands' => $commands] = $application->fresh()->generateGitImportCommands('deploy-uuid', exec_in_docker: false, custom_base_dir: $this->root.'/work/checkout');
    $process = runGitHttpsCredentialCommands($this->root, $commands);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('could not read Username')
        ->and(file_exists($this->root.'/work/checkout/docker-compose.yml'))->toBeFalse();
});

test('ls-remote on the server works for root and non-root users', function (bool $nonRoot) {
    $application = gitHttpsCredentialApplication($this->repository);

    ['commands' => $command] = $application->generateGitLsRemoteCommands('deploy-uuid', exec_in_docker: false);
    $commands = collect([$command]);
    if ($nonRoot) {
        $commands = collect(parseCommandsByLineForSudo($commands, gitHttpsCredentialNonRootServer()));
    }
    $process = runGitHttpsCredentialCommands($this->root, $commands->implode("\n"));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain("{$this->commit}\trefs/heads/main");
})->with(['root' => [false], 'non-root' => [true]]);

test('the deployment job ls-remote uses the HTTPS credential in the helper container', function () {
    $application = gitHttpsCredentialApplication($this->repository);
    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $reflection = new ReflectionObject($job);
    foreach (['application' => $application, 'customPort' => 22, 'fullRepoUrl' => $this->repository] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $command = $reflection->getMethod('gitLsRemoteCommand')->invoke($job, 'refs/heads/main');
    $process = runGitHttpsCredentialCommands($this->root, executeInDocker('deploy-uuid', $command));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("{$this->commit}\trefs/heads/main\n");
});

test('the Compose file loads with the HTTPS credential for root and non-root users', function (bool $nonRoot) {
    $application = gitHttpsCredentialApplication($this->repository);
    $uuid = 'git-https-'.bin2hex(random_bytes(4));
    $gitVersion = (string) str((new Process(['git', '--version']))->mustRun()->getOutput())->trim()->explode(' ')->last();

    $commands = (new ReflectionMethod(Application::class, 'composeFileReadCommands'))->invoke($application, $uuid, $gitVersion);
    if ($nonRoot) {
        $commands = collect(parseCommandsByLineForSudo($commands, gitHttpsCredentialNonRootServer()));
    }
    $process = runGitHttpsCredentialCommands($this->root, $commands->implode("\n"));
    (new Process(['rm', '-rf', "/tmp/{$uuid}"]))->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe($this->compose);
})->with(['root' => [false], 'non-root' => [true]]);

test('git sends the HTTPS credential only to the repository host', function () {
    $application = gitHttpsCredentialApplication($this->repository);
    $option = $application->gitHttpsCredentialConfigOption();

    $headerFor = fn (string $url) => trim(runGitHttpsCredentialCommands($this->root, "git {$option} config --get-urlmatch http.extraHeader {$url} || true")->getOutput());

    expect($headerFor($this->baseUrl.'/other/repo.git'))->toBe('Authorization: Basic '.base64_encode('deploy-bot:plain-test-value'))
        ->and($headerFor('http://example.com/app.git'))->toBe('')
        ->and($headerFor(str_replace('http://', 'https://', $this->baseUrl).'/app.git'))->toBe('');
});

test('the HTTPS credential is not used for SSH URLs, deploy keys, or applications without a password', function (array $attributes) {
    $application = gitHttpsCredentialApplication($this->repository);
    $application->forceFill($attributes)->save();

    expect($application->fresh()->gitHttpsCredentialConfigOption())->toBeNull();
})->with([
    'ssh url' => [['git_repository' => 'git@example.com:org/app.git']],
    'deploy key' => [['private_key_id' => 0]],
    'no password' => [['git_https_password' => null]],
]);
