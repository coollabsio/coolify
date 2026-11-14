<?php

use App\Enums\ServerRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = $team = Team::factory()->create();
    $this->privateKey = $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $this->server = $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->first();
    $this->destination->update(['network' => 'git-net']);
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'git_repository' => 'git@gitea:org/repo.git',
        'private_key_id' => $privateKey->id,
        'source_id' => null,
        'source_type' => null,
    ]);
});

test('the host key scan runs on the destination network and ends the options before the host', function () {
    $scripts = [];
    fakeRemoteProcesses(['*' => function (PendingProcess $process) use (&$scripts) {
        $scripts[] = remoteProcessScript($process);

        return Process::result('gitea ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample');
    }]);

    expect($this->application->fetchGitSshHostKeys())->toBe(['gitea ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample'])
        ->and(implode("\n", $scripts))->toContain("docker run --rm --network 'git-net' --entrypoint ssh-keyscan")
        ->and(implode("\n", $scripts))->toContain("-T 10 -p 22 -- 'gitea'");
});

test('the host key scan also reads the saved hosts, such as submodule hosts, but not the pinned hosts', function () {
    $this->application->update(['git_ssh_known_hosts' => "gitea ssh-ed25519 OLD\n[sub.example.com]:2222 ssh-ed25519 OLDSUB"]);
    $scripts = [];
    fakeRemoteProcesses(['*' => function (PendingProcess $process) use (&$scripts) {
        $scripts[] = remoteProcessScript($process);

        return Process::result('[sub.example.com]:2222 ssh-ed25519 NEWSUB');
    }]);

    expect($this->application->gitSshHostsToScan())->toBe([['host' => 'gitea', 'port' => 22], ['host' => 'sub.example.com', 'port' => 2222]])
        ->and($this->application->fetchGitSshHostKeys())->toBe(['[sub.example.com]:2222 ssh-ed25519 NEWSUB'])
        ->and(implode("\n", $scripts))->toContain("-T 10 -p 2222 -- 'sub.example.com'");

    $this->application->update(['git_repository' => 'git@github.com:org/repo.git', 'git_ssh_known_hosts' => null]);
    expect($this->application->fresh()->gitSshHostsToScan())->toBe([]);
});

test('the host key scan runs on the build server when the application builds there', function () {
    $buildServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'ip' => '192.0.2.77']);
    $buildServer->settings->update(['server_role' => ServerRole::BUILD, 'is_reachable' => true, 'is_usable' => true]);
    $this->application->settings->update(['is_build_server_enabled' => true]);
    $scripts = [];
    fakeRemoteProcesses(['*' => function (PendingProcess $process) use (&$scripts) {
        $scripts[] = $process->command."\n".remoteProcessScript($process);

        return Process::result('gitea ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample');
    }]);

    expect($this->application->fresh()->fetchGitSshHostKeys())->toBe(['gitea ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample'])
        ->and($scans = array_values(array_filter($scripts, fn (string $script) => str_contains($script, 'ssh-keyscan'))))->toHaveCount(1)
        ->and($scans[0])->toContain('192.0.2.77')
        ->and($scans[0])->toContain('docker run --rm --entrypoint ssh-keyscan');
});

test('a git host that starts with a dash is not an ssh host', function (string $repository) {
    $this->application->update(['git_repository' => $repository]);

    expect($this->application->gitSshHost())->toBeNull();
})->with([
    'scp style' => 'git@-oProxy:org/repo.git',
    'ssh url' => 'ssh://git@-oProxy:22/org/repo.git',
]);

test('a host key verification failure tells the user to review the host key', function () {
    $reason = (fn (string $summary, string $errorOutput) => $this->gitFailureReason($summary, $errorOutput))
        ->call($this->application, 'Failed to read Git source.', "Host key verification failed.\nfatal: Could not read from remote repository.\n\nPlease make sure you have the correct access rights\nand the repository exists.");

    expect($reason)->toContain('Source &gt; SSH host keys')
        ->and($reason)->toContain('Fetch current host key')
        ->and($reason)->not->toContain('and the repository exists.');
});

test('other git failures still show the last error line', function () {
    $reason = (fn (string $summary, string $errorOutput) => $this->gitFailureReason($summary, $errorOutput))
        ->call($this->application, 'Failed to read Git source.', "fatal: <b>bad</b> ref\n");

    expect($reason)->toBe('<br><br>Reason: fatal: &lt;b&gt;bad&lt;/b&gt; ref');
});

test('the server checkout writes the ssh config before git uses it', function () {
    $command = $this->application->serverCheckoutCommand('test-uuid', '/tmp/checkout');

    $configWrite = strpos($command, 'tee /root/.ssh/coolify_git_test-uuid.config');
    expect($configWrite)->toBeInt()
        ->and($configWrite)->toBeLessThan(strpos($command, 'git clone'))
        ->and($command)->toContain('ssh -F /root/.ssh/coolify_git_test-uuid.config');
});
