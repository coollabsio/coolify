<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\DeployTriggers\AffectedRunner;
use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\Git\GitEvaluator;
use App\Services\DeployTriggers\Git\MirrorScript;
use App\Services\DeployTriggers\Git\SshMirrorGit;
use App\Services\DeployTriggers\GitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-mux');
    Cache::flush();
    $this->fixture = deployTriggerFixture();
    $this->server = $this->fixture['server'];
    $this->server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $this->fixture['team']->id])->id]);
    Server::flushIdentityMap();
    $this->server->refresh();
    $this->parts = [];
});

function fakeMirrorProcesses(array &$parts, Closure $handler): void
{
    Process::fake(function (PendingProcess $process) use (&$parts, $handler) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $input = is_string($process->input) ? $process->input : '';
        $parts[] = ['command' => $command, 'input' => $input];

        return $handler($command, $input);
    });
}

function secretMirrorScript(SshMirrorGit $git): string
{
    $access = new GitAccess('https://github.com/acme/mono.git', config: ['url.https://x-access-token:SUPERSECRETTOKEN@github.com/.insteadOf=https://github.com/'], files: ['id_key' => "PRIVATE-KEY-MATERIAL\n"]);

    return (new MirrorScript($git, $access, 'key'))->blobs([str_repeat('a', 40)]);
}

it('sends the script with its credentials only on stdin, to a container with a tmpfs', function () {
    fakeMirrorProcesses($this->parts, fn (string $command) => Process::result(output: "@@END\n"));
    $git = new SshMirrorGit($this->server);
    $script = secretMirrorScript($git);

    $git->run($script, 60);
    $git->run($script, 60);

    $runs = collect($this->parts)->filter(fn (array $part) => str_contains($part['command'], 'docker run'));
    $pulls = collect($this->parts)->filter(fn (array $part) => str_contains($part['command'].$part['input'], 'docker image inspect'));
    expect($runs)->toHaveCount(2)
        ->and($pulls)->toHaveCount(1)
        ->and($pulls->first()['command'].$pulls->first()['input'])->toContain('timeout 600 docker pull')
        ->and($runs->first()['command'])->toContain('--tmpfs /run/coolify:rw,nosuid,nodev,noexec')
        ->and($runs->first()['command'])->toContain('-v coolify-git-mirrors:/mirrors')
        ->and($runs->first()['command'])->toContain('timeout 60 sh /run/coolify/script')
        ->and($runs->first()['command'])->not->toContain('SUPERSECRETTOKEN')
        ->and($runs->first()['command'])->not->toContain(base64_encode("PRIVATE-KEY-MATERIAL\n"))
        ->and($runs->first()['input'])->toBe($script)
        ->and($script)->toContain('SUPERSECRETTOKEN');
});

it('prefixes sudo for a non-root server', function () {
    $this->server->update(['user' => 'deploy']);
    $this->server->refresh();
    fakeMirrorProcesses($this->parts, fn () => Process::result(output: "@@END\n"));

    (new SshMirrorGit($this->server))->run('echo "@@END"', 60);

    $run = collect($this->parts)->first(fn (array $part) => str_contains($part['command'], 'docker run'));
    $pull = collect($this->parts)->first(fn (array $part) => str_contains($part['command'].$part['input'], 'docker image inspect'));
    expect($run['command'])->toContain('sudo docker run')
        ->and($pull['command'].$pull['input'])->toContain('sudo docker image inspect');
});

it('throws a runner exception when the container cannot run', function () {
    fakeMirrorProcesses($this->parts, fn (string $command) => str_contains($command, 'docker run')
        ? Process::result(errorOutput: 'Connection reset by peer', exitCode: 255)
        : Process::result());

    expect(fn () => (new SshMirrorGit($this->server))->run('echo', 60))->toThrow(AffectedRunnerException::class, 'Connection reset by peer');
    expect(collect($this->parts)->filter(fn (array $part) => str_contains($part['command'], 'docker run')))->toHaveCount(1);
});

it('binds the git evaluator', function () {
    expect(app(AffectedRunner::class))->toBeInstanceOf(GitEvaluator::class);
});
