<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
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
    Server::flushIdentityMap();
    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'user' => 'deploy']);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'git_repository' => 'https://git.example.com/org/repo.git',
        'git_branch' => 'main',
        'private_key_id' => null,
        'source_id' => null,
        'source_type' => null,
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

/**
 * The server checkout as RepositoryDetector and loadComposeFile() send it: one sh -c line.
 */
function serverGitCheckoutForNonRootUser(Application $application, Server $server): string
{
    return implode("\n", parseCommandsByLineForSudo(collect(['sh -c '.escapeshellarg($application->serverCheckoutCommand('test-uuid', '/tmp/checkout'))]), $server));
}

function serverGitRemoteStatusScript(Application $application): string
{
    $scripts = [];
    fakeRemoteProcesses(['*' => function (PendingProcess $process) use (&$scripts) {
        $scripts[] = remoteProcessScript($process);

        return Process::result();
    }]);

    expect($application->getGitRemoteStatus('test-uuid')['is_accessible'])->toBeTrue();

    return implode("\n", $scripts);
}

test('git over https on the server needs no ssh setup and no bash for a non-root user', function (string $repository) {
    $this->application->update(['git_repository' => $repository]);

    $checkout = serverGitCheckoutForNonRootUser($this->application, $this->server);
    $lsRemote = serverGitRemoteStatusScript($this->application->fresh());

    expect($checkout)->toContain('git clone')
        ->and($checkout)->not->toContain('coolify_git_test-uuid')
        ->and($lsRemote)->toContain('git ls-remote')
        ->and($lsRemote)->not->toContain('bash -c')
        ->and($lsRemote)->not->toContain('coolify_git_test-uuid');
})->with([
    'https' => 'https://git.example.com/org/repo.git',
    'http with port' => 'http://git.example.com:3000/org/repo.git',
]);

test('a github app repository on the server needs no ssh setup', function () {
    $githubApp = GithubApp::create([
        'name' => 'public-github',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => true,
    ]);
    $this->application->update(['git_repository' => 'org/repo', 'source_id' => $githubApp->id, 'source_type' => GithubApp::class]);

    $checkout = serverGitCheckoutForNonRootUser($this->application->fresh(), $this->server);
    $lsRemote = serverGitRemoteStatusScript($this->application->fresh());

    expect($checkout)->toContain('https://github.com/org/repo')
        ->and($checkout)->not->toContain('coolify_git_test-uuid')
        ->and($lsRemote)->toContain("git ls-remote -- 'https://github.com/org/repo'")
        ->and($lsRemote)->not->toContain('bash -c')
        ->and($lsRemote)->not->toContain('coolify_git_test-uuid');
});

test('git over ssh on the server still writes the ssh config and known hosts first', function (string $repository, bool $deployKey) {
    $this->application->update(['git_repository' => $repository, 'private_key_id' => $deployKey ? $this->privateKey->id : null]);
    $application = $this->application->fresh();

    $checkout = serverGitCheckoutForNonRootUser($application, $this->server);
    $lsRemote = serverGitRemoteStatusScript($application);

    foreach ([$checkout, $lsRemote] as $script) {
        expect($script)->toContain('tee /root/.ssh/coolify_git_test-uuid.known_hosts')
            ->and($script)->toContain('tee /root/.ssh/coolify_git_test-uuid.config')
            ->and(strpos($script, 'tee /root/.ssh/coolify_git_test-uuid.config'))->toBeLessThan(strpos($script, 'git '.($script === $checkout ? 'clone' : 'ls-remote')));
    }
})->with([
    'deploy key' => ['git@git.example.com:org/repo.git', true],
    'scp style without a key' => ['git@git.example.com:org/repo.git', false],
    'ssh url without a key' => ['ssh://git@git.example.com:2222/org/repo.git', false],
    'https url with a deploy key' => ['https://git.example.com/org/repo.git', true],
]);

/**
 * Runs the server checkout in a real sh with stand-ins for ssh and git on PATH. The ssh stand-in rejects
 * StrictHostKeyChecking=accept-new unless $sshSupportsAcceptNew, like OpenSSH older than 7.6. The git
 * stand-in copies the SSH config that git would use. Returns that config.
 */
function serverGitCheckoutSshConfig(Application $application, bool $sshSupportsAcceptNew): string
{
    $dir = sys_get_temp_dir().'/coolify-git-ssh-setup-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0700, true);
    mkdir("{$dir}/root/.ssh", 0700, true);
    mkdir("{$dir}/etc", 0700, true);
    file_put_contents("{$dir}/root/.ssh/config", "Host internal-git\n    ProxyCommand nc proxy 22\n");
    file_put_contents("{$dir}/etc/ssh_config", "Host *\n    SendEnv LANG\n");
    file_put_contents("{$dir}/bin/ssh", $sshSupportsAcceptNew
        ? "#!/bin/sh\nexit 0\n"
        : "#!/bin/sh\ncase \"\$*\" in *accept-new*) echo 'command-line line 0: unsupported option \"accept-new\".' >&2; exit 255;; esac\nexit 0\n");
    file_put_contents("{$dir}/bin/git", "#!/bin/sh\ncp {$dir}/root/.ssh/coolify_git_test-uuid.config {$dir}/used.config\n");
    chmod("{$dir}/bin/ssh", 0755);
    chmod("{$dir}/bin/git", 0755);

    $command = str_replace(
        ['/root/.ssh', '/etc/ssh/ssh_config'],
        ["{$dir}/root/.ssh", "{$dir}/etc/ssh_config"],
        $application->serverCheckoutCommand('test-uuid', "{$dir}/checkout"),
    );
    $result = Process::env(['PATH' => "{$dir}/bin:".getenv('PATH')])->run(['sh', '-ec', $command]);
    $config = (string) @file_get_contents("{$dir}/used.config");
    $leftover = file_exists("{$dir}/root/.ssh/coolify_git_test-uuid.config");
    Process::run(['rm', '-rf', $dir]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($leftover)->toBeFalse();

    return str_replace($dir, '', $config);
}

test('on a server with OpenSSH older than 7.6 git over ssh checks every host key strictly, without Include', function () {
    $this->application->update(['git_repository' => 'git@git.example.com:org/repo.git']);

    $config = serverGitCheckoutSshConfig($this->application->fresh(), sshSupportsAcceptNew: false);

    expect($config)->toStartWith("Host *\n    StrictHostKeyChecking yes\n    UserKnownHostsFile /root/.ssh/coolify_git_test-uuid.known_hosts\n    HashKnownHosts no\n")
        ->and($config)->not->toContain('accept-new')
        ->and($config)->not->toContain('Include')
        ->and($config)->toContain("Host internal-git\n    ProxyCommand nc proxy 22\n")
        ->and(strpos($config, 'Host internal-git'))->toBeLessThan(strpos($config, 'SendEnv LANG'));
});

test('on a server with a current OpenSSH git over ssh uses the same config as the helper container', function () {
    $this->application->update(['git_repository' => 'git@git.example.com:org/repo.git']);
    preg_match_all("/echo '([^']+)'/", gitSshSetupCommand('test-uuid'), $matches);

    $config = serverGitCheckoutSshConfig($this->application->fresh(), sshSupportsAcceptNew: true);

    expect($config)->toBe(base64_decode($matches[1][1]))
        ->and($config)->toContain('StrictHostKeyChecking accept-new');
});

test('the old OpenSSH fallback stays one sudo shell for a non-root user', function () {
    $this->application->update(['git_repository' => 'git@git.example.com:org/repo.git']);

    $lsRemote = serverGitRemoteStatusScript($this->application->fresh());

    expect($lsRemote)->toContain("sudo bash -c 'trap ")
        ->and($lsRemote)->toContain('then echo')
        ->and($lsRemote)->not->toContain('$(sudo')
        ->and($lsRemote)->not->toMatch('/^\s*(sudo )?(if|then|else|fi)\b/m');
});

test('an unknown git host on a server without trust on first use tells the user to fetch its host key', function () {
    $reason = (fn (string $summary, string $errorOutput) => $this->gitFailureReason($summary, $errorOutput))
        ->call($this->application, 'Failed to read Git source.', "No ED25519 host key is known for git.example.com and you have requested strict checking.\nHost key verification failed.\nfatal: Could not read from remote repository.");

    expect($reason)->toContain('No SSH host key is saved for this Git host')
        ->and($reason)->toContain('Source &gt; SSH host keys')
        ->and($reason)->toContain('Fetch current host key');
});

test('git ls-remote over ssh without a deploy key uses the coolify ssh config', function (bool $execInDocker) {
    $this->application->update(['git_repository' => 'git@github.com:coollabsio/coolify-examples.git']);

    ['commands' => $commands] = $this->application->fresh()->generateGitLsRemoteCommands('test-uuid', $execInDocker);

    expect($commands)->toContain('GIT_SSH_COMMAND=')
        ->and($commands)->toContain('-F /root/.ssh/coolify_git_test-uuid')
        ->and($commands)->toContain('git ls-remote');
})->with(['on the server' => false, 'in the helper container' => true]);

test('git ls-remote over https sets no ssh command', function () {
    ['commands' => $commands] = $this->application->generateGitLsRemoteCommands('test-uuid', false);

    expect($commands)->not->toContain('GIT_SSH_COMMAND');
});
