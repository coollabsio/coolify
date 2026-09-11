<?php

use App\Actions\Sentinel\EnsureFluxCertificateAuthority;
use App\Actions\Server\RepairSentinelFluxTrust;
use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('repairs only the versioned public CA trust files and validates the restarted service', function () {
    $authority = EnsureFluxCertificateAuthority::run();
    $script = RepairSentinelFluxTrust::repairScript($authority->certificate_pem, $authority->version);

    expect($script)
        ->toContain('/etc/coolify/sentinel-flux-ca.pem.new')
        ->toContain('/etc/coolify/sentinel-flux-ca.version.new')
        ->toContain('chmod 0644 /etc/coolify/sentinel-flux-ca.pem.new')
        ->toContain('chmod 0644 /etc/coolify/sentinel-flux-ca.version.new')
        ->toContain('mv -f /etc/coolify/sentinel-flux-ca.pem.new /etc/coolify/sentinel-flux-ca.pem')
        ->toContain('mv -f /etc/coolify/sentinel-flux-ca.version.new /etc/coolify/sentinel-flux-ca.version')
        ->toContain("grep -v -E '^(FLUX_CA_PATH|FLUX_TRUST_BUNDLE_VERSION)='")
        ->toContain('FLUX_CA_PATH=/etc/coolify/sentinel-flux-ca.pem')
        ->toContain('FLUX_TRUST_BUNDLE_VERSION='.$authority->version)
        ->toContain('rollback()')
        ->toContain('if [ "$changed" = true ] && [ "$completed" != true ]; then')
        ->toContain('restore_file /etc/coolify/sentinel.env')
        ->toContain('restore_file /etc/coolify/sentinel-flux-ca.pem')
        ->toContain('systemctl restart sentinel.service')
        ->toContain('systemctl is-active --quiet sentinel.service')
        ->toContain('curl --fail --silent http://127.0.0.1:8888/api/health')
        ->not->toContain($authority->certificate_pem)
        ->not->toContain($authority->private_key_pem)
        ->not->toContain(base64_encode($authority->private_key_pem))
        ->not->toContain('docker pull')
        ->not->toContain('docker create')
        ->not->toContain('/usr/local/bin/sentinel.new')
        ->not->toContain('TOKEN=')
        ->not->toContain('PUSH_ENDPOINT=');

    expect(strpos($script, 'openssl x509 -in /etc/coolify/sentinel-flux-ca.pem.new -noout'))
        ->toBeLessThan(strrpos($script, 'systemctl restart sentinel.service'));
    expect(strrpos($script, 'changed=true'))
        ->toBeGreaterThan(strpos($script, 'had_ca_version=true'));
});

it('uses a sudo-safe encoded command for non-root server users', function () {
    $server = new Server(['user' => 'coolify']);
    $command = RepairSentinelFluxTrust::remoteCommand('set -eu');

    expect(parseCommandsByLineForSudo(collect([$command]), $server)[0])
        ->toStartWith("sudo bash -c '")
        ->toContain('base64 -d | bash');
});
