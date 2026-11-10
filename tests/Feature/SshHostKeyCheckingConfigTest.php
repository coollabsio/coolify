<?php

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Support\Facades\Log;

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

it('logs one warning for an unknown SSH host key checking mode and uses warn', function () {
    config(['constants.ssh.host_key_checking' => 'enforec']);
    Log::spy();
    $server = new Server;

    expect(SshMultiplexingHelper::hostKeyCheckingMode($server))->toBe('warn')
        ->and(SshMultiplexingHelper::hostKeyCheckingMode($server))->toBe('warn');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'SSH_HOST_KEY_CHECKING') && $context['value'] === 'enforec');
});

it('does not log a warning for a known SSH host key checking mode', function (string $mode) {
    config(['constants.ssh.host_key_checking' => $mode]);
    Log::spy();

    SshMultiplexingHelper::hostKeyCheckingMode(new Server);

    Log::shouldNotHaveReceived('warning');
})->with(['warn', 'enforce', 'off']);

it('lets a server enforce the check when the instance warns, but never turns on an instance that is off', function (string $instanceMode, bool $disabled, bool $enforced, string $expected) {
    config(['constants.ssh.host_key_checking' => $instanceMode]);
    $server = new Server;
    $server->setRelation('settings', (new ServerSetting)->forceFill(['is_ssh_host_key_check_disabled' => $disabled, 'is_ssh_host_key_check_enforced' => $enforced]));

    expect(SshMultiplexingHelper::hostKeyCheckingMode($server))->toBe($expected);
})->with([
    'warn, server default' => ['warn', false, false, 'warn'],
    'warn, server enforces' => ['warn', false, true, 'enforce'],
    'enforce, server default' => ['enforce', false, false, 'enforce'],
    'off, server enforces' => ['off', false, true, 'off'],
    'warn, server disabled' => ['warn', true, false, 'off'],
]);
