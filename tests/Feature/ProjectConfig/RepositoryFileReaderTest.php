<?php

use App\Models\EnvironmentConfigLink;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\ProjectConfig\RepositoryFileException;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->root = sys_get_temp_dir().'/coolify-config-read-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/src', 0755, true);
    mkdir($this->root.'/bin');
    file_put_contents($this->root.'/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
    chmod($this->root.'/bin/sudo', 0755);
    $this->first = "{\"version\": 1}\r\n";
    file_put_contents($this->root.'/src/coolify.json', $this->first);
    foreach ([
        ['git', 'init', '-q', '-b', 'main'],
        ['git', 'add', '-A'],
        ['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', 'commit', '-qm', 'one'],
    ] as $command) {
        (new Process($command, $this->root.'/src'))->mustRun();
    }
    $this->firstSha = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->root.'/src'))->mustRun()->getOutput());
    file_put_contents($this->root.'/src/coolify.json', '{"version": 1, "x-second": true}');
    (new Process(['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', 'commit', '-qam', 'two'], $this->root.'/src'))->mustRun();
    $this->headSha = trim((new Process(['git', 'rev-parse', 'HEAD'], $this->root.'/src'))->mustRun()->getOutput());
    (new Process(['git', 'clone', '-q', '--bare', '.', $this->root.'/repo.git'], $this->root.'/src'))->mustRun();

    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git',
        'repository' => $this->root.'/repo.git', 'branch' => 'main', 'file_path' => 'coolify.json',
        'read_server_id' => $this->fx['server']->id,
    ])->fresh();
});

afterEach(function () {
    (new Process(['rm', '-rf', $this->root]))->run();
});

/**
 * Runs the read step of the commands in a local clone, as the server runs it after the clone step.
 */
function readerRun(object $test, ?string $commit = null, string $user = 'root'): Process
{
    $checkout = $test->root.'/checkout-'.bin2hex(random_bytes(3));
    (new Process(['git', 'clone', '-q', '--no-checkout', $test->root.'/repo.git', $checkout]))->mustRun();
    if ($commit !== null) {
        (new Process(['git', '-c', 'advice.detachedHead=false', 'checkout', '-q', $commit], $checkout))->mustRun();
    }
    $read = collect([app(RepositoryFileReader::class)->commands($test->link, 'cfg-test', $commit)->last()]);
    if ($user !== 'root') {
        $server = Mockery::mock(Server::class)->makePartial();
        $server->shouldReceive('getAttribute')->with('user')->andReturn($user);
        $server->shouldReceive('setAttribute')->andReturnSelf();
        $read = collect(parseCommandsByLineForSudo($read, $server));
    }
    $process = new Process(['bash', '-se'], $checkout, ['PATH' => $test->root.'/bin:'.getenv('PATH')]);
    $process->setInput($read->implode("\n")."\n");
    $process->run();

    return $process;
}

it('runs the full clone and read on a server', function () {
    $uuid = 'cfg-read-'.bin2hex(random_bytes(4));
    $process = new Process(['bash', '-se']);
    $process->setInput(app(RepositoryFileReader::class)->commands($this->link, $uuid, $this->firstSha)->implode("\n")."\n");
    $process->run();
    (new Process(['rm', '-rf', "/tmp/{$uuid}"]))->run();
    $file = app(RepositoryFileReader::class)->parse(trim($process->getOutput()));

    expect($file->commitSha)->toBe($this->firstSha)->and($file->contents)->toBe($this->first);
})->skip(fn () => ! is_writable('/root'), 'The clone writes SSH files to /root/.ssh, as on a Coolify server.');

it('reads the file at the branch head, for root and non-root users', function (string $user) {
    $process = readerRun($this, user: $user);
    $file = app(RepositoryFileReader::class)->parse(trim($process->getOutput()));

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->not->toContain('syntax error')
        ->and($file->missing)->toBeFalse()
        ->and($file->commitSha)->toBe($this->headSha)
        ->and($file->contents)->toBe('{"version": 1, "x-second": true}');
})->with(['root', 'ubuntu']);

it('reads the file at a pinned commit byte for byte', function () {
    $process = readerRun($this, $this->firstSha);
    $file = app(RepositoryFileReader::class)->parse(trim($process->getOutput()));

    expect($file->commitSha)->toBe($this->firstSha)
        ->and($file->contents)->toBe($this->first)
        ->and(app(RepositoryFileReader::class)->commands($this->link, 'cfg-x', $this->firstSha)->implode("\n"))->toContain($this->firstSha);
});

it('reports a missing file without failing the batch', function () {
    $this->link->update(['file_path' => 'deploy/coolify.json']);
    $process = readerRun($this);
    $file = app(RepositoryFileReader::class)->parse(trim($process->getOutput()));

    expect($process->getExitCode())->toBe(0)
        ->and($file->missing)->toBeTrue()
        ->and($file->commitSha)->toBe($this->headSha);
});

it('rejects a symbolic link', function () {
    symlink('coolify.json', $this->root.'/src/link.json');
    (new Process(['git', 'add', '-A'], $this->root.'/src'))->mustRun();
    (new Process(['git', '-c', 'user.email=t@example.com', '-c', 'user.name=T', 'commit', '-qm', 'link'], $this->root.'/src'))->mustRun();
    (new Process(['git', 'push', '-q', $this->root.'/repo.git', 'main'], $this->root.'/src'))->mustRun();
    $this->link->update(['file_path' => 'link.json']);

    $process = readerRun($this);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('symbolic link');
});

it('rejects a file larger than 1 MiB', function () {
    expect(fn () => app(RepositoryFileReader::class)->parse("{$this->headSha}\n".RepositoryFileReader::TOO_LARGE))
        ->toThrow(RepositoryFileException::class, 'larger than 1 MiB');
});

it('does not run commands on a transferred server', function () {
    ProcessFacade::fake();
    $this->fx['server']->update(['server_metadata' => ['transfer' => ['status' => 'transferred']]]);

    expect(fn () => app(RepositoryFileReader::class)->read($this->link->fresh()))->toThrow(RepositoryFileException::class);
    ProcessFacade::assertNothingRan();
});

it('does not retry a failure of the remote command', function () {
    $this->fx['server']->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $this->fx['team']->id])->id]);
    $runs = 0;
    fakeRemoteProcesses(['*git ls-tree*' => function () use (&$runs) {
        $runs++;

        return ProcessFacade::result(output: '', errorOutput: 'fatal: repository not found', exitCode: 1);
    }]);

    expect(fn () => app(RepositoryFileReader::class)->read($this->link->fresh()))->toThrow(RepositoryFileException::class, 'repository not found');
    expect($runs)->toBe(1);
});
