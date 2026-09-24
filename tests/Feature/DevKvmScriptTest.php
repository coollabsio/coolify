<?php

use Symfony\Component\Process\Process;

it('shows start and stop usage and rejects extra arguments', function () {
    $script = base_path('scripts/dev');

    $help = new Process(['bash', $script, '--help'], base_path());
    $help->run();

    $invalid = new Process(['bash', $script, 'start', 'ubuntu-root', 'extra'], base_path());
    $invalid->run();
    $unknown = new Process(['bash', $script, 'restart'], base_path());
    $unknown->run();

    expect($help->isSuccessful())->toBeTrue()
        ->and($help->getOutput())->toContain('start [qemu-profile]', 'stop [qemu-profile]', 'run [qemu-profile]')
        ->and($invalid->isSuccessful())->toBeFalse()
        ->and($unknown->isSuccessful())->toBeFalse();
});

it('stops only the container stack when testing-host is selected', function () {
    $directory = sys_get_temp_dir().'/coolify-dev-container-test-'.bin2hex(random_bytes(4));
    mkdir($directory.'/bin', 0777, true);
    $log = $directory.'/commands.log';
    $fake = <<<'BASH'
#!/usr/bin/env bash
printf '%s %s\n' "$(basename "$0")" "$*" >> "$DEV_KVM_LOG"
BASH;
    foreach (['docker', 'virsh'] as $binary) {
        file_put_contents($directory.'/bin/'.$binary, $fake);
        chmod($directory.'/bin/'.$binary, 0755);
    }

    try {
        $process = new Process(['bash', base_path('scripts/dev'), 'stop'], base_path(), [
            'PATH' => $directory.'/bin:'.getenv('PATH'),
            'DEV_KVM_LOG' => $log,
            'COOLIFY_DEV_SERVER_BACKEND' => 'testing-host',
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(file_get_contents($log))->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml stop')
            ->not->toContain('virsh')
            ->not->toContain('docker-compose.dev-kvm.yml');
    } finally {
        foreach (['docker', 'virsh'] as $binary) {
            unlink($directory.'/bin/'.$binary);
        }
        if (file_exists($log)) {
            unlink($log);
        }
        rmdir($directory.'/bin');
        rmdir($directory);
    }
});

it('shuts down the selected vm and stops the compose services', function () {
    $directory = sys_get_temp_dir().'/coolify-dev-kvm-test-'.bin2hex(random_bytes(4));
    mkdir($directory.'/bin', 0777, true);
    $log = $directory.'/commands.log';
    $fake = <<<'BASH'
#!/usr/bin/env bash
printf '%s %s\n' "$(basename "$0")" "$*" >> "$DEV_KVM_LOG"
if [[ "$(basename "$0") $3" == 'virsh domstate' ]]; then
  echo running
fi
if [[ "$(basename "$0") $3" == 'virsh list' ]]; then
  echo coolify-dev-debian-root
fi
BASH;
    file_put_contents($directory.'/bin/docker', $fake);
    file_put_contents($directory.'/bin/virsh', $fake);
    chmod($directory.'/bin/docker', 0755);
    chmod($directory.'/bin/virsh', 0755);

    try {
        $process = new Process(['bash', base_path('scripts/dev'), 'stop', 'debian-root'], base_path(), [
            'PATH' => $directory.'/bin:'.getenv('PATH'),
            'DEV_KVM_LOG' => $log,
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(file_get_contents($log))->toContain('virsh --connect qemu:///system list --all --name')
            ->toContain('virsh --connect qemu:///system domstate coolify-dev-debian-root')
            ->toContain('virsh --connect qemu:///system shutdown coolify-dev-debian-root')
            ->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.dev-kvm.yml stop')
            ->not->toContain('dev-kvm.yml down');
    } finally {
        unlink($directory.'/bin/docker');
        unlink($directory.'/bin/virsh');
        if (file_exists($log)) {
            unlink($log);
        }
        rmdir($directory.'/bin');
        rmdir($directory);
    }
});
