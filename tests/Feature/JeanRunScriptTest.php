<?php

use Symfony\Component\Process\Process;

it('uses the jean launcher in the run configuration', function () {
    $config = json_decode(file_get_contents(base_path('jean.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($config['scripts']['run'])->toBe('./scripts/dev run')
        ->and($config['ports'][0]['port'])->toBe(8000);
});

it('starts the testing host and restores localhost when kvm is not selected', function () {
    $directory = sys_get_temp_dir().'/coolify-jean-run-test-'.bin2hex(random_bytes(4));
    mkdir($directory.'/bin', 0777, true);
    $log = $directory.'/commands.log';
    file_put_contents($directory.'/bin/docker', <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$JEAN_TEST_LOG"
if [[ "$1" == inspect ]]; then
  echo 'running healthy'
fi
BASH);
    chmod($directory.'/bin/docker', 0755);

    try {
        $process = new Process(['bash', base_path('scripts/dev'), 'run'], base_path(), [
            'PATH' => $directory.'/bin:'.getenv('PATH'),
            'JEAN_TEST_LOG' => $log,
            'COOLIFY_DEV_SERVER_BACKEND' => 'testing-host',
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(file_get_contents($log))->toContain('compose -f docker-compose.yml -f docker-compose.dev.yml up -d')
            ->toContain('inspect --format {{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}} coolify')
            ->toContain('compose -f docker-compose.yml -f docker-compose.dev.yml exec -T coolify php artisan db:seed --class=ServerSeeder --force')
            ->toContain('compose -f docker-compose.yml -f docker-compose.dev.yml logs -f')
            ->toContain('compose -f docker-compose.yml -f docker-compose.dev.yml down')
            ->not->toContain('docker-compose.dev-kvm.yml');
    } finally {
        unlink($directory.'/bin/docker');
        if (file_exists($log)) {
            unlink($log);
        }
        rmdir($directory.'/bin');
        rmdir($directory);
    }
});

it('starts the kvm vm and uses the kvm compose override when available', function () {
    if (! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! file_exists('/dev/kvm') || filetype('/dev/kvm') !== 'char' || ! is_readable('/dev/kvm') || ! is_writable('/dev/kvm')) {
        $this->markTestSkipped('KVM and root access are required for this launcher branch.');
    }

    $directory = sys_get_temp_dir().'/coolify-jean-kvm-test-'.bin2hex(random_bytes(4));
    mkdir($directory.'/bin', 0777, true);
    $log = $directory.'/commands.log';
    $fake = <<<'BASH'
#!/usr/bin/env bash
printf '%s %s\n' "$(basename "$0")" "$*" >> "$JEAN_TEST_LOG"
if [[ "$1" == inspect ]]; then
  echo 'running healthy'
fi
BASH;

    foreach (['docker', 'php', 'virsh'] as $binary) {
        file_put_contents($directory.'/bin/'.$binary, $fake);
        chmod($directory.'/bin/'.$binary, 0755);
    }

    try {
        $process = new Process(['bash', base_path('scripts/dev'), 'run'], base_path(), [
            'PATH' => $directory.'/bin:'.getenv('PATH'),
            'JEAN_TEST_LOG' => $log,
            'COOLIFY_DEV_KVM_PROFILE' => 'debian-root',
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and(file_get_contents($log))->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.dev-kvm.yml up -d')
            ->toContain('docker inspect --format {{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}} coolify')
            ->toContain('php artisan dev:qemu debian-root --as-localhost')
            ->toContain('docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.dev-kvm.yml down')
            ->not->toContain('db:seed --class=ServerSeeder');
    } finally {
        foreach (['docker', 'php', 'virsh'] as $binary) {
            unlink($directory.'/bin/'.$binary);
        }
        if (file_exists($log)) {
            unlink($log);
        }
        rmdir($directory.'/bin');
        rmdir($directory);
    }
});

it('stops waiting when the coolify container exits', function () {
    $directory = sys_get_temp_dir().'/coolify-health-test-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/docker', <<<'BASH'
#!/usr/bin/env bash
echo 'exited unhealthy'
BASH);
    chmod($directory.'/docker', 0755);

    try {
        $process = new Process(['bash', base_path('scripts/dev'), 'start'], base_path(), [
            'PATH' => $directory.':'.getenv('PATH'),
            'COOLIFY_DEV_SERVER_BACKEND' => 'testing-host',
        ]);
        $process->run();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('did not become healthy');
    } finally {
        unlink($directory.'/docker');
        rmdir($directory);
    }
});
