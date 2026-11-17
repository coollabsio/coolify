<?php

/**
 * The deployment gives each Compose service Coolify's .env file. The env files of the user stay in
 * front of it, so they can hold defaults that Coolify variables override.
 *
 * @see https://github.com/coollabsio/coolify/issues/2004
 */
it('keeps the env files of the user in front of the Coolify env file', function (mixed $envFile, array $expected) {
    expect(composeEnvFilesWithCoolifyEnv($envFile))->toBe($expected);
})->with([
    'no env_file' => [null, ['.env']],
    'one file as a string' => ['.env.defaults', ['.env.defaults', '.env']],
    'a list of files' => [['.env.defaults', './config/app.env'], ['.env.defaults', './config/app.env', '.env']],
    'the Coolify file before other files' => [['.env', '.env.local'], ['.env.local', '.env']],
    'the Coolify file with a dot path' => [['./.env', '.env.local'], ['.env.local', '.env']],
    'long syntax' => [[['path' => '.env.defaults', 'required' => false]], [['path' => '.env.defaults', 'required' => false], '.env']],
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
