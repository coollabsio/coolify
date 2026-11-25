<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\DeployTriggers\AffectedRunner;
use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\GitAccess;
use App\Services\DeployTriggers\HelperCapability;
use App\Services\DeployTriggers\SshAffectedRunner;
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
    $this->scripts = [];
});

function runnerRequest(): EvaluateRequest
{
    return new EvaluateRequest(
        repositoryUrl: 'git@git.example.com:acme/mono.git',
        mirrorKey: EvaluateRequest::mirrorKeyFor(1, 'git@git.example.com:acme/mono.git'),
        gitEnv: ['GIT_SSH_COMMAND' => 'ssh -i /run/coolify/id_key'],
        gitConfig: ['url.https://x-access-token:SUPERSECRETTOKEN@github.com/.insteadOf=https://github.com/'],
        after: str_repeat('b', 40),
        fetchRefs: ['refs/heads/main'],
        isFork: false,
        submodules: false,
        applications: [new EvaluateApplicationInput('web', str_repeat('a', 40), null, 'smart', null, 'dockerfile', '/', '/apps/web/Dockerfile', null,
            ['install' => null, 'build' => null, 'start' => null, 'compose_build' => null, 'compose_start' => null], [], [])],
    );
}

function runnerResponse(): string
{
    return json_encode([
        'schema_version' => 1, 'tool_version' => '1.0.0', 'after' => str_repeat('b', 40), 'diffs' => [], 'errors' => [],
        'applications' => [['id' => 'web', 'decision' => 'skip', 'reason_code' => 'no_match', 'reason' => 'No match.', 'base' => str_repeat('a', 40), 'matches' => []]],
    ]);
}

function fakeAffectedProcesses(array &$scripts, Closure $handler): void
{
    Process::fake(function (PendingProcess $process) use (&$scripts, $handler) {
        $script = remoteProcessScript($process);
        $scripts[] = $script;

        return $handler($script);
    });
}

it('runs evaluate through one remote script and parses stdout', function () {
    fakeAffectedProcesses($this->scripts, fn (string $script) => str_contains($script, 'coolify-affected evaluate')
        ? Process::result(output: runnerResponse())
        : Process::result());

    $response = (new SshAffectedRunner)->evaluate($this->server, runnerRequest(), new GitAccess('git@git.example.com:acme/mono.git', files: ['id_key' => "KEY\n"]));

    $script = collect($this->scripts)->first(fn (string $s) => str_contains($s, 'coolify-affected evaluate'));
    expect($response->applications['web']->reasonCode)->toBe('no_match')
        ->and($script)->toContain("sh -c '")
        ->and($script)->toContain('umask 077')
        ->and($script)->toContain('-v coolify-git-mirrors:/mirrors')
        ->and($script)->toContain(':/run/coolify:ro')
        ->and($script)->toContain('docker image inspect')
        ->and($script)->toContain('timeout 600 docker pull')
        ->and($script)->toContain('trap ');
});

it('keeps the whole script in one shell for a non-root server', function () {
    $this->server->update(['user' => 'deploy']);
    $this->server->refresh();
    fakeAffectedProcesses($this->scripts, fn (string $script) => Process::result(output: runnerResponse()));

    (new SshAffectedRunner)->evaluate($this->server, runnerRequest(), new GitAccess('repo'));

    $script = collect($this->scripts)->first(fn (string $s) => str_contains($s, 'coolify-affected evaluate'));
    expect($script)->toMatch("/sudo sh -c '/")
        ->and(substr_count($script, 'sudo '))->toBe(1);
});

it('throws a runner exception without credentials when the remote script fails', function () {
    fakeAffectedProcesses($this->scripts, fn (string $script) => Process::result(output: '', errorOutput: 'fatal: could not read from https://x-access-token:SUPERSECRETTOKEN@github.com', exitCode: 3));

    try {
        (new SshAffectedRunner)->evaluate($this->server, runnerRequest(), new GitAccess('repo'));
        $this->fail('Expected an exception.');
    } catch (AffectedRunnerException $exception) {
        expect($exception->getMessage())->not->toContain('SUPERSECRETTOKEN');
    }
});

it('reports compatible only for schema version 1', function (string $output, bool $expected) {
    fakeAffectedProcesses($this->scripts, fn (string $script) => Process::result(output: $output));

    expect(app(HelperCapability::class)->compatible($this->server))->toBe($expected);
})->with([
    'schema 1' => ['{"version":"1.0.0","schema_version":1}', true],
    'schema 2' => ['{"version":"2.0.0","schema_version":2}', false],
    'not json' => ['sh: coolify-affected: not found', false],
]);

it('reports incompatible when the binary is missing', function () {
    fakeAffectedProcesses($this->scripts, fn (string $script) => Process::result(errorOutput: 'executable file not found', exitCode: 127));

    expect(app(HelperCapability::class)->compatible($this->server))->toBeFalse();
});

it('caches capability per server and helper tag', function () {
    fakeAffectedProcesses($this->scripts, fn (string $script) => Process::result(output: '{"version":"1.0.0","schema_version":1}'));

    app(HelperCapability::class)->compatible($this->server);
    app(HelperCapability::class)->compatible($this->server);

    expect(collect($this->scripts)->filter(fn (string $s) => str_contains($s, 'coolify-affected version'))->count())->toBe(1);
});

it('binds the ssh runner and reads the mirror cap from config', function () {
    config(['constants.coolify.push_evaluation.max_mirror_bytes' => 123]);

    expect(app(AffectedRunner::class))->toBeInstanceOf(SshAffectedRunner::class)
        ->and(config('constants.coolify.push_evaluation.max_mirror_bytes'))->toBe(123);
});
