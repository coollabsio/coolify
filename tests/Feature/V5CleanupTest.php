<?php

use Illuminate\Support\Facades\Route;

it('does not register the archived v5 interface', function () {
    expect(Route::has('v5.dashboard'))->toBeFalse()
        ->and(collect(Route::getRoutes())->contains(fn ($route) => str_starts_with($route->uri(), 'v5')))->toBeFalse();
});

it('keeps v5 prototypes outside executable application paths', function () {
    expect(is_dir(app_path('Support/V5')))->toBeFalse()
        ->and(is_dir(resource_path('js/v5')))->toBeFalse()
        ->and(is_dir(resource_path('css/v5')))->toBeFalse()
        ->and(is_dir(resource_path('views/v5')))->toBeFalse()
        ->and(file_exists(base_path('routes/v5.php')))->toBeFalse()
        ->and(file_exists(config_path('v5.php')))->toBeFalse();
});

it('archives the previous v5 migrations and ui as documentation', function () {
    expect(glob(base_path('docs/v5/migrations/*.php.txt')))->toHaveCount(15)
        ->and(glob(base_path('docs/v5/ui/resources/js/v5/**/*.txt')))->not->toBeEmpty()
        ->and(file_exists(base_path('docs/v5/ui/resources/views/v5/app.blade.php.txt')))->toBeTrue()
        ->and(file_exists(base_path('docs/v5/archive/app/Support/V5/V5Feature.php.txt')))->toBeTrue()
        ->and(file_exists(base_path('docs/v5/archive/routes/v5.php.txt')))->toBeTrue()
        ->and(file_exists(base_path('docs/v5/archive/tests/v5/Browser/DashboardSmokeTest.php.txt')))->toBeTrue();
});

it('documents removed v5 packages and the v4 animation dependency', function () {
    $packageDocumentation = file_get_contents(base_path('docs/v5/package.md'));

    expect($packageDocumentation)
        ->toContain('`inertiajs/inertia-laravel`')
        ->toContain('`react`')
        ->toContain('`@vitejs/plugin-react`')
        ->toContain('`tw-animate-css`')
        ->toContain('must not be removed');
});

it('records active v5 decisions separately from the archived prototype', function () {
    $index = file_get_contents(base_path('docs/v5/decisions/README.md'));
    $sentinelDecision = file_get_contents(base_path('docs/v5/decisions/0001-combine-coold-with-sentinel.md'));
    $installationDecision = file_get_contents(base_path('docs/v5/decisions/0002-separate-legacy-upgrades-from-v5-installs.md'));

    expect($index)
        ->toContain('Active Coolify v5 Decisions')
        ->toContain('0001-combine-coold-with-sentinel.md')
        ->toContain('historical references')
        ->and($sentinelDecision)
        ->toContain('Status: Accepted')
        ->toContain('V4 continues to run Sentinel as a Docker container')
        ->toContain('V5 runs Sentinel as a mandatory host-native binary')
        ->toContain('coold functionality will move into the Sentinel project')
        ->toContain('typed commands and local safety checks')
        ->toContain('same Sentinel product and executable')
        ->toContain('both can run during a controlled transition')
        ->toContain('host-native Sentinel first owns the v5 control connection')
        ->toContain('retire the container after capability parity')
        ->and($index)
        ->toContain('0002-separate-legacy-upgrades-from-v5-installs.md')
        ->and($installationDecision)
        ->toContain('Status: Accepted')
        ->toContain('legacy-control-plane')
        ->toContain('prevent the v5 scheduler from placing new v5 workloads on localhost')
        ->toContain('node-controller-worker')
        ->toContain('node-controller')
        ->toContain('node-worker')
        ->toContain('in-place conversion');
});

it('replaces the normal testing host with systemd for v5 development', function () {
    $compose = file_get_contents(base_path('docker-compose.v5-dev.yml'));
    $defaultCompose = file_get_contents(base_path('docker-compose.dev.yml'));
    $dockerfile = file_get_contents(base_path('docker/testing-host/Dockerfile'));

    expect($compose)
        ->toContain('testing-host:')
        ->toContain('target: systemd')
        ->toContain('init: false')
        ->toContain('cgroup: host')
        ->toContain('/sys/fs/cgroup:/sys/fs/cgroup:rw')
        ->and($defaultCompose)
        ->not->toContain("\n  testing-host-systemd:\n")
        ->and($dockerfile)
        ->toContain('AS systemd')
        ->toContain('systemd-sysv')
        ->toContain('systemctl enable ssh.service')
        ->toContain('CMD ["/sbin/init"]');
});

it('runs the v5 development control channel with generated TLS files', function () {
    $compose = file_get_contents(base_path('docker-compose.v5-dev.yml'));

    expect($compose)
        ->toContain('flux-pki-init:')
        ->toContain('flux:initialize-tls')
        ->toContain('FLUX_PUBLIC_URL: "https://coolify-flux:7443"')
        ->toContain('FLUX_TLS_CERT_PATH: "/tls/pki/server.pem"')
        ->toContain('FLUX_TLS_KEY_PATH: "/tls/pki/server-key.pem"')
        ->toContain('FLUX_RUNTIME_UID: "1000"')
        ->toContain('chown -R 1000:1000 /data/coolify/flux')
        ->toContain('user: "1000:1000"')
        ->toContain('dev_flux_data:/data/coolify/flux')
        ->not->toContain('FLUX_DEVELOPMENT_ALLOW_PLAINTEXT: "true"')
        ->not->toContain('BEGIN PRIVATE KEY');
});
