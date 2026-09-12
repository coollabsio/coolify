<?php

use App\Actions\Node\InstallSentinel;
use App\Actions\Sentinel\EnsureFluxCertificateAuthority;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('installs a public CA bundle and version atomically before Sentinel starts', function () {
    $authority = EnsureFluxCertificateAuthority::run();
    $script = InstallSentinel::installationScript(
        token: 'sentinel-token',
        endpoint: 'http://coolify:8000/api/v1/sentinel',
        image: 'ghcr.io/coollabsio/sentinel-host:main',
        certificate: $authority->certificate_pem,
        trustBundleVersion: $authority->version,
    );
    $environment = InstallSentinel::environmentFile('sentinel-token', 'http://coolify:8000/api/v1/sentinel', $authority->version);
    $unit = InstallSentinel::serviceUnit();

    expect($script)
        ->toContain('runtime="$(command -v docker || command -v podman || true)"')
        ->toContain('test -n "$runtime"')
        ->toContain('"$runtime" pull')
        ->toContain('"$runtime" create "$image" /sentinel')
        ->toContain('"$runtime" cp "$container_id:/sentinel" "$temporary_binary"')
        ->toContain('install -m 0755 "$temporary_binary" /usr/local/bin/sentinel.new')
        ->toContain('mv -f /usr/local/bin/sentinel.new /usr/local/bin/sentinel')
        ->toContain('/etc/coolify/sentinel-flux-ca.pem.new')
        ->toContain('/etc/coolify/sentinel-flux-ca.version.new')
        ->toContain('chmod 0644 /etc/coolify/sentinel-flux-ca.pem.new')
        ->toContain('chmod 0644 /etc/coolify/sentinel-flux-ca.version.new')
        ->toContain('mv -f /etc/coolify/sentinel-flux-ca.pem.new /etc/coolify/sentinel-flux-ca.pem')
        ->toContain('mv -f /etc/coolify/sentinel-flux-ca.version.new /etc/coolify/sentinel-flux-ca.version')
        ->not->toContain($authority->certificate_pem)
        ->not->toContain($authority->private_key_pem)
        ->not->toContain(base64_encode($authority->private_key_pem))
        ->and($environment)->toContain('CONTROL_PLANE_ENABLED=true')
        ->toContain('FLUX_CA_PATH=/etc/coolify/sentinel-flux-ca.pem')
        ->toContain('FLUX_TRUST_BUNDLE_VERSION='.$authority->version)
        ->toContain('TOKEN=sentinel-token')
        ->toContain('PUSH_ENDPOINT=http://coolify:8000/api/v1/sentinel')
        ->and($unit)->toContain('ExecStart=/usr/local/bin/sentinel')
        ->toContain('EnvironmentFile=/etc/coolify/sentinel.env')
        ->not->toContain('docker.service')
        ->and($script)
        ->toContain('rollback()')
        ->toContain('if [ "$changed" = true ] && [ "$completed" != true ]; then')
        ->toContain('restore_file /etc/coolify/sentinel.env')
        ->toContain('restore_file /etc/coolify/sentinel-flux-ca.pem')
        ->toContain('restore_file /etc/systemd/system/sentinel.service')
        ->toContain('systemctl enable sentinel.service')
        ->toContain('systemctl restart sentinel.service')
        ->toContain('systemctl is-active --quiet sentinel.service')
        ->toContain('curl --fail --silent http://127.0.0.1:8888/api/health');

    expect(strpos($script, 'openssl x509 -in /etc/coolify/sentinel-flux-ca.pem.new -noout'))
        ->toBeLessThan(strrpos($script, 'systemctl enable sentinel.service'));
    expect(strrpos($script, 'changed=true'))
        ->toBeGreaterThan(strpos($script, 'had_ca_version=true'));
});

it('rejects unsafe installer inputs', function (string $token, string $endpoint, string $image) {
    $authority = EnsureFluxCertificateAuthority::run();

    expect(fn () => InstallSentinel::installationScript($token, $endpoint, $image, $authority->certificate_pem, $authority->version))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'token with a newline' => ["token\nvalue", 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main'],
    'invalid endpoint' => ['token', 'not-a-url', 'ghcr.io/coollabsio/sentinel-host:main'],
    'unsafe image' => ['token', 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main; reboot'],
]);

it('rejects an invalid trust bundle and version independently', function () {
    expect(fn () => InstallSentinel::installationScript('token', 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main', 'not-a-certificate', 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => InstallSentinel::installationScript('token', 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main', EnsureFluxCertificateAuthority::run()->certificate_pem, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('does not use ssh while the host agent feature is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);
    Process::fake();

    expect(InstallSentinel::run(new Node))->toBeNull();

    Process::assertNothingRan();
});

it('does not use ssh outside development', function () {
    config()->set('app.env', 'production');
    config()->set('constants.sentinel.host_enabled', true);
    Process::fake();

    expect(InstallSentinel::run(new Node))->toBeNull();

    Process::assertNothingRan();
});

it('does not install host-native Sentinel on legacy servers', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    Process::fake();

    expect(fn () => InstallSentinel::run(new Server))->toThrow(TypeError::class);

    Process::assertNothingRan();
});

it('elevates the encoded installer for a non-root server user', function () {
    $server = new Node(['user' => 'coolify']);
    $command = InstallSentinel::remoteCommand('set -eu');

    expect(parseCommandsByLineForSudo(collect([$command]), $server)[0])
        ->toStartWith("sudo bash -c '")
        ->toContain('base64 -d | bash');
});
