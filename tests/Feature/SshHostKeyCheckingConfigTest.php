<?php

/**
 * Load config/constants.php with SSH_HOST_KEY_CHECKING set to the given value.
 */
function sshHostKeyCheckingFromEnv(?string $value): mixed
{
    $original = [$_SERVER['SSH_HOST_KEY_CHECKING'] ?? null, $_ENV['SSH_HOST_KEY_CHECKING'] ?? null, getenv('SSH_HOST_KEY_CHECKING')];

    try {
        if ($value === null) {
            unset($_SERVER['SSH_HOST_KEY_CHECKING'], $_ENV['SSH_HOST_KEY_CHECKING']);
            putenv('SSH_HOST_KEY_CHECKING');
        } else {
            $_SERVER['SSH_HOST_KEY_CHECKING'] = $_ENV['SSH_HOST_KEY_CHECKING'] = $value;
            putenv("SSH_HOST_KEY_CHECKING={$value}");
        }

        return (require config_path('constants.php'))['ssh']['host_key_checking'];
    } finally {
        [$server, $env, $putenv] = $original;
        if ($server === null) {
            unset($_SERVER['SSH_HOST_KEY_CHECKING']);
        } else {
            $_SERVER['SSH_HOST_KEY_CHECKING'] = $server;
        }
        if ($env === null) {
            unset($_ENV['SSH_HOST_KEY_CHECKING']);
        } else {
            $_ENV['SSH_HOST_KEY_CHECKING'] = $env;
        }
        putenv($putenv === false ? 'SSH_HOST_KEY_CHECKING' : "SSH_HOST_KEY_CHECKING={$putenv}");
    }
}

it('normalizes the SSH host key checking mode', function (?string $value, string $expected) {
    expect(sshHostKeyCheckingFromEnv($value))->toBe($expected);
})->with([
    'default' => [null, 'warn'],
    'uppercase enforce' => ['ENFORCE', 'enforce'],
    'mixed case enforce' => ['Enforce', 'enforce'],
    'padded enforce' => [' enforce ', 'enforce'],
    'padded off' => [' OFF ', 'off'],
    'warn' => ['warn', 'warn'],
]);
