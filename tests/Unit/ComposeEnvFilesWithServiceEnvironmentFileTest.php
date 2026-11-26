<?php

/**
 * The deployment gives each Compose service its own Coolify env file. The env files of the user stay in
 * front of it, so they can hold defaults that Coolify variables override.
 *
 * @see https://github.com/coollabsio/coolify/issues/2004
 */
it('keeps the env files of the user in front of the env file of the service', function (mixed $envFile, array $expected) {
    expect(composeEnvFilesWithServiceEnvironmentFile($envFile, 'web'))->toBe($expected);
})->with([
    'no env_file' => [null, ['.coolify.web.env']],
    'one file as a string' => ['.env.defaults', ['.env.defaults', '.coolify.web.env']],
    'a list of files' => [['.env.defaults', './config/app.env'], ['.env.defaults', './config/app.env', '.coolify.web.env']],
    'the Coolify .env file, which holds the variables of all services' => [['.env', '.env.local'], ['.env.local', '.coolify.web.env']],
    'the Coolify .env file with a dot path' => [['./.env', '.env.local'], ['.env.local', '.coolify.web.env']],
    'a Coolify service env file of an earlier parse' => [['.coolify.web.env', '.env.local'], ['.env.local', '.coolify.web.env']],
    'long syntax' => [[['path' => '.env.defaults', 'required' => false]], [['path' => '.env.defaults', 'required' => false], '.coolify.web.env']],
]);

it('resolves an env file from the directory of the Compose file', function (string $path, string $composeDirectory, ?string $expected) {
    expect(composeEnvFileProjectPath($path, $composeDirectory))->toBe($expected);
})->with([
    'file next to a root Compose file' => ['.env.defaults', '', '.env.defaults'],
    'dot path' => ['./config/app.env', '', 'config/app.env'],
    'file next to a Compose file in a subdirectory' => ['.env.defaults', 'deploy', 'deploy/.env.defaults'],
    'parent directory inside the repository' => ['../shared.env', 'deploy/prod', 'deploy/shared.env'],
    'outside the repository' => ['../secrets.env', '', null],
    'absolute path' => ['/etc/environment', '', null],
    'empty path' => ['', '', null],
]);
