<?php

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\GithubApp;
use App\Models\PrivateKey;

function gitAccessApplication(array $attributes): Application
{
    $application = new Application;
    $application->forceFill(array_merge(['uuid' => 'app-uuid', 'git_branch' => 'main'], $attributes));
    $application->setRelation('settings', new ApplicationSetting);

    return $application;
}

it('passes a github app token only through git config', function () {
    $application = gitAccessApplication(['git_repository' => 'acme/mono']);
    $source = new class extends GithubApp
    {
        public function installationToken(?int $timeout = null): string
        {
            return 'ghs_secret/token';
        }
    };
    $source->forceFill(['html_url' => 'https://github.com', 'api_url' => 'https://api.github.com', 'is_public' => false]);
    $application->setRelation('source', $source);

    $access = $application->gitAccessForAffected();

    expect($access->repositoryUrl)->toBe('https://github.com/acme/mono.git')
        ->and($access->config)->toHaveCount(1)
        ->and($access->config[0])->toMatch('~\Aurl\.https://x-access-token:[^@/]+@github\.com/\.insteadOf=https://github\.com/\z~')
        ->and($access->files)->toBe([]);

    preg_match('~x-access-token:([^@]+)@~', $access->config[0], $token);
    expect(json_encode($access->redacted()))->not->toContain($token[1]);
});

it('mounts a deploy key and points ssh at the mounted files', function () {
    $application = gitAccessApplication(['git_repository' => 'git@git.example.com:acme/mono.git', 'private_key_id' => 5]);
    $key = new PrivateKey;
    $key->forceFill(['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nsecret\n-----END OPENSSH PRIVATE KEY-----\n"]);
    $application->setRelation('private_key', $key);

    $access = $application->gitAccessForAffected();

    expect($access->repositoryUrl)->toBe('git@git.example.com:acme/mono.git')
        ->and($access->files)->toHaveKeys(['id_key', 'known_hosts', 'ssh_config'])
        ->and($access->files['id_key'])->toContain('secret')
        ->and($access->files['ssh_config'])->toContain('UserKnownHostsFile /run/coolify/known_hosts')
        ->and($access->env['GIT_SSH_COMMAND'])->toContain('-F /run/coolify/ssh_config')
        ->and($access->env['GIT_SSH_COMMAND'])->toContain('-i /run/coolify/id_key')
        ->and(json_encode($access->redacted()))->not->toContain('secret');
});

it('needs no credentials for a public repository', function () {
    $application = gitAccessApplication(['git_repository' => 'https://github.com/acme/mono']);

    $access = $application->gitAccessForAffected();

    expect($access->repositoryUrl)->toBe('https://github.com/acme/mono')
        ->and($access->config)->toBe([])
        ->and($access->files)->toBe([]);
});
