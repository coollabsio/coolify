<?php

use App\Actions\Sentinel\EnsureFluxCertificateAuthority;
use App\Actions\Server\InstallSentinelHost;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('installs a public CA bundle and version atomically before Sentinel starts', function () {
    $authority = EnsureFluxCertificateAuthority::run();
    $script = InstallSentinelHost::installationScript(
        token: 'sentinel-token',
        endpoint: 'http://coolify:8000/api/v1/sentinel',
        image: 'ghcr.io/coollabsio/sentinel-host:main',
        certificate: $authority->certificate_pem,
        trustBundleVersion: $authority->version,
    );
    $environment = InstallSentinelHost::environmentFile('sentinel-token', 'http://coolify:8000/api/v1/sentinel', $authority->version);
    $unit = InstallSentinelHost::serviceUnit();

    expect($script)
        ->toContain("docker pull 'ghcr.io/coollabsio/sentinel-host:main'")
        ->toContain('docker create "$image" /sentinel')
        ->toContain('docker cp "$container_id:/sentinel" "$temporary_binary"')
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
        ->toBeLessThan(strpos($script, 'systemctl enable sentinel.service'));
    expect(strrpos($script, 'changed=true'))
        ->toBeGreaterThan(strpos($script, 'had_ca_version=true'));
});

it('rejects unsafe installer inputs', function (string $token, string $endpoint, string $image) {
    expect(fn () => InstallSentinelHost::installationScript($token, $endpoint, $image, '', 0))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'token with a newline' => ["token\nvalue", 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main'],
    'invalid endpoint' => ['token', 'not-a-url', 'ghcr.io/coollabsio/sentinel-host:main'],
    'unsafe image' => ['token', 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main; reboot'],
]);

it('does not use ssh while the host agent feature is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);
    Process::fake();

    expect(InstallSentinelHost::run(new Server))->toBeNull();

    Process::assertNothingRan();
});

it('does not use ssh outside development', function () {
    config()->set('app.env', 'production');
    config()->set('constants.sentinel.host_enabled', true);
    Process::fake();

    expect(InstallSentinelHost::run(new Server))->toBeNull();

    Process::assertNothingRan();
});

it('elevates the encoded installer for a non-root server user', function () {
    $server = new Server(['user' => 'coolify']);
    $command = InstallSentinelHost::remoteCommand('set -eu');

    expect(parseCommandsByLineForSudo(collect([$command]), $server)[0])
        ->toStartWith("sudo bash -c '")
        ->toContain('base64 -d | bash');
});
