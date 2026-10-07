<?php

/**
 * Load config/constants.php with SSH_COMMAND_TIMEOUT set to the given value.
 */
function sshCommandTimeoutFromEnv(?string $value): mixed
{
    $original = [$_SERVER['SSH_COMMAND_TIMEOUT'] ?? null, $_ENV['SSH_COMMAND_TIMEOUT'] ?? null, getenv('SSH_COMMAND_TIMEOUT')];

    try {
        if ($value === null) {
            unset($_SERVER['SSH_COMMAND_TIMEOUT'], $_ENV['SSH_COMMAND_TIMEOUT']);
            putenv('SSH_COMMAND_TIMEOUT');
        } else {
            $_SERVER['SSH_COMMAND_TIMEOUT'] = $_ENV['SSH_COMMAND_TIMEOUT'] = $value;
            putenv("SSH_COMMAND_TIMEOUT={$value}");
        }

        return (require config_path('constants.php'))['ssh']['command_timeout'];
    } finally {
        [$server, $env, $putenv] = $original;
        if ($server === null) {
            unset($_SERVER['SSH_COMMAND_TIMEOUT']);
        } else {
            $_SERVER['SSH_COMMAND_TIMEOUT'] = $server;
        }
        if ($env === null) {
            unset($_ENV['SSH_COMMAND_TIMEOUT']);
        } else {
            $_ENV['SSH_COMMAND_TIMEOUT'] = $env;
        }
        putenv($putenv === false ? 'SSH_COMMAND_TIMEOUT' : "SSH_COMMAND_TIMEOUT={$putenv}");
    }
}

it('never disables the SSH command timeout', function (?string $value) {
    expect(sshCommandTimeoutFromEnv($value))->toBe(3600);
})->with([
    'not set' => null,
    'zero' => '0',
    'negative' => '-5',
    'not a number' => 'never',
]);

it('keeps a positive SSH command timeout', function () {
    expect(sshCommandTimeoutFromEnv('7200'))->toBe(7200);
});
