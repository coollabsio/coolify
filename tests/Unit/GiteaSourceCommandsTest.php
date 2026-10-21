<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GiteaApp;
use App\Models\PrivateKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;

beforeEach(function () {
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing(null);
});

function giteaCommandsAsString(array|Collection|string $commands): string
{
    if (is_string($commands)) {
        return $commands;
    }

    return collect($commands)->map(fn ($command) => data_get($command, 'command') ?? $command[0] ?? $command)->implode(' && ');
}

/**
 * A non-expired token short-circuits refreshGiteaToken(), so no HTTP call is made.
 */
function giteaConnectedSource(string $htmlUrl = 'https://gitea.example.test'): GiteaApp
{
    $source = new GiteaApp;
    $source->forceFill([
        'html_url' => $htmlUrl,
        'api_url' => $htmlUrl.'/api/v1',
        'is_public' => false,
        'custom_port' => 22,
        'custom_user' => 'git',
        'team_id' => 1,
    ]);
    $source->access_token = 'gt-token/with+sym';
    $source->refresh_token = 'gt-refresh-token';
    $source->expires_at = time() + 3600;

    return $source;
}

function giteaApplicationFor(GiteaApp $source, string $repository = 'owner/private-app', bool $submodules = false): Application
{
    $application = new Application;
    $application->forceFill([
        'uuid' => 'test-app-uuid',
        'git_repository' => $repository,
        'git_branch' => 'main',
        'git_commit_sha' => 'HEAD',
    ]);

    $settings = new ApplicationSetting;
    $settings->is_git_shallow_clone_enabled = false;
    $settings->is_git_submodules_enabled = $submodules;
    $settings->is_git_lfs_enabled = false;
    $application->setRelation('settings', $settings);
    $application->setRelation('source', $source);

    return $application;
}

it('clones a connected Gitea source over HTTPS with the OAuth token and per-command submodule credentials', function () {
    $application = giteaApplicationFor(giteaConnectedSource(), submodules: true);

    $result = $application->generateGitImportCommands(deployment_uuid: 'test-deployment', exec_in_docker: false);

    $expectedConfig = "git -c 'url.https://oauth2:gt-token%2Fwith%2Bsym@gitea.example.test/.insteadOf=https://gitea.example.test/'";

    expect($result['fullRepoUrl'])->toBe('https://oauth2:gt-token%2Fwith%2Bsym@gitea.example.test/owner/private-app.git');
    expect($result['commands'])
        ->not->toContain('git config --global')
        ->toContain("{$expectedConfig} clone --recurse-submodules -b 'main' 'https://oauth2:gt-token%2Fwith%2Bsym@gitea.example.test/owner/private-app.git'")
        ->toContain("{$expectedConfig} submodule update --init --recursive");
});

it('fetches the Gitea pull request ref through the OAuth git config', function () {
    $application = giteaApplicationFor(giteaConnectedSource(), submodules: true);

    $result = $application->generateGitImportCommands(
        deployment_uuid: 'test-deployment',
        pull_request_id: 7,
        exec_in_docker: false,
        only_checkout: true,
        custom_base_dir: '/artifacts/test',
    );

    expect($result['branch'])->toBe('pull/7/head:pr-7-coolify');
    expect($result['commands'])
        ->toContain(".insteadOf=https://gitea.example.test/' fetch origin 'pull/7/head:pr-7-coolify'")
        ->not->toContain('merge-requests');
});

it('runs ls-remote for a connected Gitea source with the OAuth token and custom port', function () {
    $application = giteaApplicationFor(giteaConnectedSource('http://gitea.example.test:3000'));

    $result = $application->generateGitLsRemoteCommands('test-deployment', false);

    expect($result['fullRepoUrl'])->toBe('http://oauth2:gt-token%2Fwith%2Bsym@gitea.example.test:3000/owner/private-app.git');
    expect($result['commands'])->toContain("git ls-remote 'http://oauth2:gt-token%2Fwith%2Bsym@gitea.example.test:3000/owner/private-app.git'");
});

it('falls back to the Gitea source private key only when it belongs to the source team', function (int $keyTeamId, bool $expectKey) {
    $privateKey = new PrivateKey;
    $privateKey->forceFill(['team_id' => $keyTeamId, 'private_key' => 'fake-private-key']);

    $source = new GiteaApp;
    $source->forceFill(['html_url' => 'https://gitea.example.test', 'custom_port' => 2222, 'team_id' => 1]);
    $source->setRelation('privateKey', $privateKey);

    $application = giteaApplicationFor($source, 'git@gitea.example.test:owner/private-app.git');

    $lsRemote = giteaCommandsAsString($application->generateGitLsRemoteCommands('test-deployment', false)['commands']);
    $import = $application->generateGitImportCommands(deployment_uuid: 'test-deployment', pull_request_id: 3, exec_in_docker: false);
    $importCommands = giteaCommandsAsString($import['commands']);

    foreach ([$lsRemote, $importCommands] as $commands) {
        expect($commands)->not->toContain('oauth2:');
        $expectKey
            ? expect($commands)->toContain('id_rsa_coolify_')->toContain('-p 2222')
            : expect($commands)->not->toContain('id_rsa_coolify_');
    }
    if ($expectKey) {
        expect($import['branch'])->toBe('pull/3/head:pr-3-coolify');
    }
})->with([
    'same team' => [1, true],
    'other team' => [2, false],
]);

it('clones a disconnected Gitea source without a key as a public repository', function () {
    $source = new GiteaApp;
    $source->forceFill(['html_url' => 'https://gitea.example.test', 'team_id' => 1]);
    $source->setRelation('privateKey', null);

    $application = giteaApplicationFor($source, 'https://gitea.example.test/owner/public-app.git');

    $result = $application->generateGitImportCommands(deployment_uuid: 'test-deployment', exec_in_docker: false);

    expect($result['fullRepoUrl'])->toBe('https://gitea.example.test/owner/public-app.git');
    expect(giteaCommandsAsString($result['commands']))
        ->toContain("'https://gitea.example.test/owner/public-app.git'")
        ->not->toContain('oauth2:')
        ->not->toContain('id_rsa_coolify_');
});

it('links Gitea branches and commits with Gitea URL paths', function () {
    $application = giteaApplicationFor(giteaConnectedSource());
    $application->base_directory = '/app';

    expect($application->git_branch_location)->toBe('https://gitea.example.test/owner/private-app/src/branch/main/app');
    expect($application->git_commits)->toBe('https://gitea.example.test/owner/private-app/commits/branch/main');
    expect($application->gitCommitLink('abc123'))->toBe('https://gitea.example.test/owner/private-app/commit/abc123');
});

it('converts a Gitea HTTP repository to SSH for deploy keys', function () {
    $source = new GiteaApp;
    $source->forceFill(['html_url' => 'https://gitea.example.test', 'custom_port' => 2222, 'custom_user' => 'git']);

    expect(convertGitUrl('owner/repo', 'deploy_key', $source))->toBe([
        'repository' => 'ssh://git@gitea.example.test:2222/owner/repo',
        'port' => '2222',
    ]);
});
