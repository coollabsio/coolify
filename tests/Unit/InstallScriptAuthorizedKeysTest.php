<?php

use Symfony\Component\Process\Process;

it('removes only the previous Coolify key from authorized_keys', function (string $path) {
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$path);
    preg_match('/^\s*(sed -i .*~\/\.ssh\/authorized_keys)$/m', $script, $matches);
    expect($matches)->toHaveKey(1);

    $home = sys_get_temp_dir().'/coolify-install-keys-'.bin2hex(random_bytes(4));
    mkdir($home.'/.ssh', 0700, true);
    file_put_contents($home.'/.ssh/authorized_keys', implode("\n", [
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOperatorKey coolify-disposable',
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOperatorKey operator-lab',
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKey root@coolify',
        'no-pty ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOptions deploy@coolify.example.com',
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOldCoolifyKey coolify',
        '',
    ]));

    $process = new Process(['bash', '-c', $matches[1]], null, ['HOME' => $home]);
    $process->mustRun();

    expect(file_get_contents($home.'/.ssh/authorized_keys'))->toBe(implode("\n", [
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOperatorKey coolify-disposable',
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOperatorKey operator-lab',
        'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHostKey root@coolify',
        'no-pty ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOptions deploy@coolify.example.com',
        '',
    ]));

    exec('rm -rf '.escapeshellarg($home));
})->with([
    'stable install script' => ['scripts/install.sh'],
    'nightly install script' => ['other/nightly/install.sh'],
]);
