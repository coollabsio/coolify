<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->root = sys_get_temp_dir().'/coolify-compose-load-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/src/apps/web', 0755, true);
    mkdir($this->root.'/bin');
    file_put_contents($this->root.'/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
    chmod($this->root.'/bin/sudo', 0755);

    $this->compose = "services:\n  api:\n    build:\n      context: ../..\n";
    file_put_contents($this->root.'/src/apps/web/docker-compose.yml', $this->compose);
    file_put_contents($this->root.'/src/README.md', 'monorepo');
    foreach ([
        ['git', 'init', '-q', '-b', 'main'],
        ['git', 'add', '-A'],
        ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '-qm', 'monorepo'],
        ['git', 'clone', '-q', '--bare', '.', $this->root.'/repo.git'],
    ] as $command) {
        (new Process($command, $this->root.'/src'))->mustRun();
    }
    $this->commit = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->root.'/src'))->mustRun()->getOutput());
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->root]))->run();
    Mockery::close();
});

test('the Compose file loads through the server commands for root and non-root users', function (string $user, bool $pinCommit) {
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'git_repository' => $this->root.'/repo.git',
        'git_branch' => 'main',
        'git_commit_sha' => $pinCommit ? $this->commit : 'HEAD',
        'base_directory' => '/apps/web',
        'docker_compose_location' => '/docker-compose.yml',
    ]);
    $application->settings->update(['is_git_submodules_enabled' => true]);
    $uuid = 'compose-load-'.bin2hex(random_bytes(4));

    $commands = (new ReflectionMethod(Application::class, 'composeFileReadCommands'))->invoke($application->fresh(), $uuid);
    if ($user !== 'root') {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn($user);
        $server->shouldReceive('setAttribute')->andReturnSelf();
        $commands = collect(parseCommandsByLineForSudo($commands, $server));
    }

    // Servers read the command list from stdin with `bash -se`, like instant_remote_process() over SSH.
    $process = new Process(['bash', '-se'], env: ['PATH' => $this->root.'/bin:'.getenv('PATH')]);
    $process->setInput($commands->implode("\n")."\n");
    $process->run();
    (new Process(['rm', '-rf', "/tmp/{$uuid}"]))->run();

    expect($commands->implode("\n"))->not->toContain("cd '/artifacts/")
        ->and($process->getErrorOutput())->not->toContain('syntax error')
        ->and($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe($this->compose);
})->with([
    'root' => ['root'],
    'non-root' => ['ubuntu'],
])->with([
    'branch head' => [false],
    'pinned commit' => [true],
]);

/**
 * Commits the given symlinks to the repository, pushes them to the bare remote, and runs the
 * Compose read commands for an application with the given base directory and Compose file location.
 */
function runComposeReadWithSymlinks(object $test, array $symlinks, string $baseDirectory, string $composeLocation): Process
{
    foreach ($symlinks as $link => $target) {
        @mkdir(dirname($test->root.'/src/'.$link), 0755, true);
        symlink($target, $test->root.'/src/'.$link);
    }
    foreach ([
        ['git', 'add', '-A'],
        ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '-qm', 'symlinks'],
        ['git', 'push', '-q', $test->root.'/repo.git', 'main'],
    ] as $command) {
        (new Process($command, $test->root.'/src'))->mustRun();
    }

    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'git_repository' => $test->root.'/repo.git',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'base_directory' => $baseDirectory,
        'docker_compose_location' => $composeLocation,
    ]);
    $uuid = 'compose-load-'.bin2hex(random_bytes(4));
    $commands = (new ReflectionMethod(Application::class, 'composeFileReadCommands'))->invoke($application->fresh(), $uuid);

    $process = new Process(['bash', '-se'], env: ['PATH' => $test->root.'/bin:'.getenv('PATH')]);
    $process->setInput($commands->implode("\n")."\n");
    $process->run();
    (new Process(['rm', '-rf', "/tmp/{$uuid}"]))->run();

    return $process;
}

test('a symlinked Compose file is rejected', function (string $target) {
    if ($target === 'outside') {
        $target = $this->root.'/linked-target.yml';
    }
    file_put_contents($this->root.'/linked-target.yml', "services:\n  linked:\n    environment:\n      TOKEN: linked-target-value\n");

    $process = runComposeReadWithSymlinks($this, ['docker-compose.yml' => $target], '/', '/docker-compose.yml');

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('symbolic link')
        ->and($process->getErrorOutput())->not->toContain('linked-target-value');
})->with([
    'absolute path' => ['outside'],
    'file in the repository' => ['apps/web/docker-compose.yml'],
]);

test('a symlinked base directory is not found', function () {
    mkdir($this->root.'/linked-app');
    file_put_contents($this->root.'/linked-app/docker-compose.yml', "services:\n  linked:\n    image: linked-target-value\n");

    $process = runComposeReadWithSymlinks($this, ['linked' => $this->root.'/linked-app'], '/linked', '/docker-compose.yml');

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('No such file')
        ->and($process->getErrorOutput())->not->toContain('linked-target-value');
});

test('the Compose file loads from inside a Git submodule', function (string $submodulePath, string $composeLocation, string $user) {
    $submoduleSource = $this->root.'/infra-src';
    $composeDir = substr('infra/templates/wordpress-staging', strlen($submodulePath) + 1);
    mkdir("{$submoduleSource}/{$composeDir}", 0755, true);
    file_put_contents("{$submoduleSource}/{$composeDir}/docker-compose.yml", $this->compose);
    // Git refuses local submodule URLs unless the file protocol is allowed.
    $gitEnv = ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'protocol.file.allow', 'GIT_CONFIG_VALUE_0' => 'always'];
    foreach ([
        [$submoduleSource, ['git', 'init', '-q', '-b', 'main']],
        [$submoduleSource, ['git', 'add', '-A']],
        [$submoduleSource, ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '-qm', 'infra']],
        [$submoduleSource, ['git', 'clone', '-q', '--bare', '.', $this->root.'/infra.git']],
        [$this->root.'/src', ['git', 'submodule', '-q', 'add', $this->root.'/infra.git', $submodulePath]],
        [$this->root.'/src', ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '-qm', 'add infra']],
        [$this->root.'/src', ['git', 'push', '-q', $this->root.'/repo.git', 'main']],
    ] as [$cwd, $command]) {
        (new Process($command, $cwd, $gitEnv))->mustRun();
    }

    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'git_repository' => $this->root.'/repo.git',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'base_directory' => '/',
        'docker_compose_location' => $composeLocation,
    ]);
    $application->settings->update(['is_git_submodules_enabled' => true]);
    $uuid = 'compose-load-'.bin2hex(random_bytes(4));

    $commands = (new ReflectionMethod(Application::class, 'composeFileReadCommands'))->invoke($application->fresh(), $uuid);
    if ($user !== 'root') {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn($user);
        $server->shouldReceive('setAttribute')->andReturnSelf();
        $commands = collect(parseCommandsByLineForSudo($commands, $server));
    }

    $process = new Process(['bash', '-se'], env: ['PATH' => $this->root.'/bin:'.getenv('PATH')] + $gitEnv);
    $process->setInput($commands->implode("\n")."\n");
    $process->run();
    (new Process(['rm', '-rf', "/tmp/{$uuid}"]))->run();

    expect($process->getErrorOutput())->not->toContain('syntax error');
    if (str_contains($composeLocation, 'missing')) {
        // loadComposeFile() turns this error into "Docker Compose file not found".
        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('No such file');
    } else {
        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toBe($this->compose);
    }
})->with([
    'submodule at the top level' => ['infra', '/infra/templates/wordpress-staging/docker-compose.yml'],
    'nested submodule' => ['infra/templates', '/infra/templates/wordpress-staging/docker-compose.yml'],
    'missing file' => ['infra', '/infra/missing/docker-compose.yml'],
])->with([
    'root' => ['root'],
    'non-root' => ['ubuntu'],
]);

test('a symlinked Compose file inside a Git submodule is rejected', function () {
    $submoduleSource = $this->root.'/infra-src';
    mkdir($submoduleSource);
    file_put_contents($this->root.'/linked-target.yml', "services:\n  linked:\n    image: linked-target-value\n");
    symlink($this->root.'/linked-target.yml', "{$submoduleSource}/docker-compose.yml");
    $gitEnv = ['GIT_CONFIG_COUNT' => '1', 'GIT_CONFIG_KEY_0' => 'protocol.file.allow', 'GIT_CONFIG_VALUE_0' => 'always'];
    foreach ([
        [$submoduleSource, ['git', 'init', '-q', '-b', 'main']],
        [$submoduleSource, ['git', 'add', '-A']],
        [$submoduleSource, ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '-qm', 'infra']],
        [$submoduleSource, ['git', 'clone', '-q', '--bare', '.', $this->root.'/infra.git']],
        [$this->root.'/src', ['git', 'submodule', '-q', 'add', $this->root.'/infra.git', 'infra']],
        [$this->root.'/src', ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', 'commit', '-qm', 'add infra']],
        [$this->root.'/src', ['git', 'push', '-q', $this->root.'/repo.git', 'main']],
    ] as [$cwd, $command]) {
        (new Process($command, $cwd, $gitEnv))->mustRun();
    }

    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'git_repository' => $this->root.'/repo.git',
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
        'base_directory' => '/',
        'docker_compose_location' => '/infra/docker-compose.yml',
    ]);
    $application->settings->update(['is_git_submodules_enabled' => true]);
    $uuid = 'compose-load-'.bin2hex(random_bytes(4));
    $commands = (new ReflectionMethod(Application::class, 'composeFileReadCommands'))->invoke($application->fresh(), $uuid);

    $process = new Process(['bash', '-se'], env: ['PATH' => $this->root.'/bin:'.getenv('PATH')] + $gitEnv);
    $process->setInput($commands->implode("\n")."\n");
    $process->run();
    (new Process(['rm', '-rf', "/tmp/{$uuid}"]))->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('symbolic link')
        ->and($process->getErrorOutput())->not->toContain('linked-target-value');
});
