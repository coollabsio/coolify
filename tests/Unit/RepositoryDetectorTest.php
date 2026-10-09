<?php

use App\Enums\BuildPackTypes;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Services\RepositoryDetector;
use Symfony\Component\Process\Process;

function repositoryDetector(string $repository = 'https://github.com/test/repo', string $baseDirectory = '/'): RepositoryDetector
{
    $application = (new Application)->forceFill(['git_repository' => $repository, 'git_branch' => 'main', 'git_commit_sha' => 'HEAD']);
    $application->setRelation('settings', (new ApplicationSetting)->forceFill(['is_git_shallow_clone_enabled' => true, 'is_git_submodules_enabled' => false]));
    $application->setRelation('source', null);
    $application->setRelation('private_key', null);

    return new RepositoryDetector($application, $baseDirectory, new Server);
}

/**
 * Runs the scan commands on this machine against a local Git repository, optionally after the
 * non-root sudo parser (with `sudo` removed, so the test does not need root).
 */
function runRepositoryScan(RepositoryDetector $detector, string $uuid, bool $throughSudoParser = false): Process
{
    $commands = (new ReflectionMethod($detector, 'scanCommands'))->invoke($detector, $uuid);
    if ($throughSudoParser) {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
        $commands = collect(parseCommandsByLineForSudo($commands, $server))
            ->map(fn (string $line) => preg_replace('/(^|\s)sudo /', '$1', $line))
            ->map(fn (string $line) => preg_replace('/ && chown .*$/', '', $line));
    }
    $process = Process::fromShellCommandline("set -e\n".$commands->implode("\n"));
    $process->run();

    return $process;
}

beforeEach(function () {
    $this->detector = repositoryDetector();
    $this->parseOutputMethod = new ReflectionMethod($this->detector, 'parseOutput');
});

test('parseOutput handles complete detection output', function () {
    $output = json_encode([
        'dockerfiles' => ['Dockerfile', 'apps/api/Dockerfile'],
        'dockerComposeFiles' => ['docker-compose.yml'],
        'envFiles' => ['.env.example' => "APP_NAME=MyApp\nAPP_ENV=production\nDB_HOST=localhost\nDB_PORT=5432"],
        'dockerfilePorts' => ['Dockerfile' => 3000, 'apps/api/Dockerfile' => 8080],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->dockerfiles)->toBe(['Dockerfile', 'apps/api/Dockerfile'])
        ->and($result->dockerComposeFiles)->toBe(['docker-compose.yml'])
        ->and($result->envFiles)->toHaveKey('.env.example')
        ->and($result->envFiles['.env.example'])->toContain('APP_NAME=MyApp')
        ->and($result->dockerfilePorts)->toBe(['Dockerfile' => 3000, 'apps/api/Dockerfile' => 8080])
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERCOMPOSE);
});

test('parseOutput handles empty repository', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => [],
        'envFiles' => (object) [],
        'dockerfilePorts' => (object) [],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->dockerfiles)->toBe([])
        ->and($result->dockerComposeFiles)->toBe([])
        ->and($result->envFiles)->toBe([])
        ->and($result->dockerfilePorts)->toBe([])
        ->and($result->getSuggestedBuildPack())->toBeNull();
});

test('parseOutput handles dockerfile without EXPOSE', function () {
    $output = json_encode([
        'dockerfiles' => ['Dockerfile'],
        'dockerComposeFiles' => [],
        'envFiles' => (object) [],
        'dockerfilePorts' => ['Dockerfile' => null],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->dockerfiles)->toBe(['Dockerfile'])
        ->and($result->dockerfilePorts)->toBe(['Dockerfile' => null])
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERFILE);
});

test('parseOutput handles only env files without dockerfiles', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => [],
        'envFiles' => ['.env.example' => "SECRET_KEY=changeme\nDATABASE_URL=postgres://localhost/db"],
        'dockerfilePorts' => (object) [],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->dockerfiles)->toBe([])
        ->and($result->envFiles)->toHaveKey('.env.example')
        ->and($result->envFiles['.env.example'])->toContain('SECRET_KEY=changeme')
        ->and($result->envFiles['.env.example'])->toContain('DATABASE_URL=postgres://localhost/db')
        ->and($result->getSuggestedBuildPack())->toBeNull();
});

test('parseOutput handles multiple compose files', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => ['docker-compose.yml', 'compose.yaml'],
        'envFiles' => (object) [],
        'dockerfilePorts' => (object) [],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->dockerComposeFiles)->toBe(['docker-compose.yml', 'compose.yaml'])
        ->and($result->getSuggestedBuildPack())->toBe(BuildPackTypes::DOCKERCOMPOSE);
});

test('parseOutput puts the file nearest to the base directory first', function () {
    $output = json_encode([
        'dockerfiles' => ['apps/api/Dockerfile', 'Dockerfile.dev', 'apps/web/Dockerfile', 'Dockerfile'],
        'dockerComposeFiles' => ['examples/demo/compose.yaml', 'deploy/docker-compose.yml', 'compose.yaml'],
        'envFiles' => (object) [],
        'dockerfilePorts' => (object) [],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->dockerfiles)->toBe(['Dockerfile', 'Dockerfile.dev', 'apps/api/Dockerfile', 'apps/web/Dockerfile'])
        ->and($result->dockerComposeFiles)->toBe(['compose.yaml', 'deploy/docker-compose.yml', 'examples/demo/compose.yaml']);
});

test('parseOutput handles multiple env files', function () {
    $output = json_encode([
        'dockerfiles' => [],
        'dockerComposeFiles' => [],
        'envFiles' => [
            '.env.example' => "APP_KEY=base64:abc\nAPP_ENV=local",
            '.env.sample' => "DB_HOST=127.0.0.1\nDB_PORT=5432",
            '.env.dist' => 'REDIS_HOST=localhost',
        ],
        'dockerfilePorts' => (object) [],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->envFiles)->toHaveCount(3)
        ->and($result->envFiles['.env.example'])->toContain('APP_KEY=base64:abc')
        ->and($result->envFiles['.env.sample'])->toContain('DB_HOST=127.0.0.1')
        ->and($result->envFiles['.env.dist'])->toContain('REDIS_HOST=localhost')
        ->and($result->hasEnvFiles())->toBeTrue();
});

test('parseOutput handles string port values from JSON', function () {
    $output = json_encode([
        'dockerfiles' => ['Dockerfile'],
        'dockerComposeFiles' => [],
        'envFiles' => (object) [],
        'dockerfilePorts' => ['Dockerfile' => '3000'],
    ]);

    $result = $this->parseOutputMethod->invoke($this->detector, $output);

    expect($result->dockerfilePorts)->toBe(['Dockerfile' => 3000])
        ->and($result->dockerfilePorts['Dockerfile'])->toBeInt();
});

test('parseOutput returns none for invalid JSON', function () {
    $result = $this->parseOutputMethod->invoke($this->detector, 'not valid json');

    expect($result->dockerfiles)->toBe([])
        ->and($result->dockerComposeFiles)->toBe([])
        ->and($result->envFiles)->toBe([])
        ->and($result->dockerfilePorts)->toBe([]);
});

test('scanner rejects traversal before remote execution', function (string $baseDirectory) {
    $detector = repositoryDetector(baseDirectory: $baseDirectory);
    expect(fn () => (new ReflectionMethod($detector, 'scanCommands'))->invoke($detector, 'scan-test'))->toThrow(RuntimeException::class);
})->with(['/../outside', '/app/../..', '/./app', '/app;id']);

test('scanner reads only regular Git blobs and honors build directory', function (bool $throughSudoParser) {
    $repo = sys_get_temp_dir().'/scan-fixture-'.bin2hex(random_bytes(8));
    $uuid = 'scan-test-'.bin2hex(random_bytes(8));
    mkdir($repo);
    mkdir($repo.'/app');
    mkdir($repo.'/app/services');
    file_put_contents($repo.'/Dockerfile', "FROM scratch\nEXPOSE 9000\n");
    file_put_contents($repo.'/app/Dockerfile', "FROM scratch\r\n  expose 8080/tcp\r\n");
    file_put_contents($repo.'/app/services/Dockerfile.worker', "FROM scratch\nEXPOSE \${PORT}\n");
    file_put_contents($repo.'/app/compose.yaml', 'services: {}');
    file_put_contents($repo.'/app/.env.sample', "SAFE=value\nQUOTE='it''s'");
    file_put_contents($repo.'/app/services/.env.example', 'NESTED=ignored');
    file_put_contents($repo.'/app/my dockerfile notes.txt', 'not a Dockerfile');
    file_put_contents($repo.'/secret', 'HOST_SECRET=do-not-read');
    symlink($repo.'/secret', $repo.'/app/.env.example');
    $git = 'git -C '.escapeshellarg($repo);
    Process::fromShellCommandline("{$git} init -q -b main && {$git} add . && {$git} -c user.name=Test -c user.email=test@example.com commit -qm fixture")->mustRun();
    try {
        $detector = repositoryDetector($repo, '/app');
        $process = runRepositoryScan($detector, $uuid, $throughSudoParser);
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $result = (new ReflectionMethod($detector, 'parseOutput'))->invoke($detector, $process->getOutput());
        expect($result->dockerfiles)->toBe(['Dockerfile', 'services/Dockerfile.worker'])
            ->and($result->dockerComposeFiles)->toBe(['compose.yaml'])
            ->and($result->envFiles)->toBe(['.env.sample' => "SAFE=value\nQUOTE='it''s'"])
            ->and($result->dockerfilePorts)->toBe(['Dockerfile' => 8080, 'services/Dockerfile.worker' => null]);

        $failure = runRepositoryScan(repositoryDetector($repo.'-missing'), $uuid.'-missing', $throughSudoParser);
        expect($failure->isSuccessful())->toBeFalse();
    } finally {
        Process::fromShellCommandline('rm -rf -- '.escapeshellarg($repo).' /tmp/'.$uuid.' /tmp/'.$uuid.'-missing')->mustRun();
    }
})->with(['root server' => false, 'non-root server' => true]);

test('invalid port values are not suggested', function (mixed $port) {
    $result = $this->parseOutputMethod->invoke($this->detector, json_encode(['dockerfilePorts' => ['Dockerfile' => $port]]));
    expect($result->dockerfilePorts['Dockerfile'])->toBeNull();
})->with([0, -1, 65536, '3.5', '3e3', true]);

test('malformed scan collections fall back safely', function () {
    $result = $this->parseOutputMethod->invoke($this->detector, '{"dockerfiles":"Dockerfile"}');
    expect($result->dockerfiles)->toBe([]);
});
