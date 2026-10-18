<?php

use App\Livewire\Server\CaCertificate\Show;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Queue::fake();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

/**
 * @return array{0: string, 1: string} The certificate and its private key.
 */
function generateSelfSignedCert(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['CN' => 'Test CA'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 365);
    openssl_x509_export($cert, $certPem);
    openssl_pkey_export($key, $keyPem);

    return [$certPem, $keyPem];
}

test('saveCaCertificate sanitizes injected commands after certificate marker', function () {
    [$validCert, $validKey] = generateSelfSignedCert();

    $caCert = SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => $validCert,
        'ssl_private_key' => $validKey,
        'common_name' => 'Coolify CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    // Inject shell command after valid certificate
    $maliciousContent = $validCert."' ; id > /tmp/pwned ; echo '";

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', $maliciousContent)
        ->call('saveCaCertificate')
        ->assertDispatched('success');

    // After save, the certificate should be the clean re-exported PEM, not the malicious input
    $caCert->refresh();
    expect($caCert->ssl_certificate)->not->toContain('/tmp/pwned');
    expect($caCert->ssl_certificate)->not->toContain('; id');
    expect($caCert->ssl_certificate)->toContain('-----BEGIN CERTIFICATE-----');
    expect($caCert->ssl_certificate)->toEndWith("-----END CERTIFICATE-----\n");
});

test('saveCaCertificate rejects completely invalid certificate', function () {
    SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => 'placeholder',
        'ssl_private_key' => 'test-key',
        'common_name' => 'Coolify CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', "not-a-cert'; rm -rf /; echo '")
        ->call('saveCaCertificate')
        ->assertDispatched('error');
});

test('saveCaCertificate rejects empty certificate content', function () {
    SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => 'placeholder',
        'ssl_private_key' => 'test-key',
        'common_name' => 'Coolify CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', '')
        ->call('saveCaCertificate')
        ->assertDispatched('error');
});

test('saveCaCertificate rejects a certificate that does not match the CA private key', function () {
    [$storedCert, $storedKey] = generateSelfSignedCert();
    [$otherCert] = generateSelfSignedCert();

    $caCert = SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => $storedCert,
        'ssl_private_key' => $storedKey,
        'common_name' => 'Coolify CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', $otherCert)
        ->call('saveCaCertificate')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($caCert->refresh()->ssl_certificate)->toBe($storedCert);
    Queue::assertNothingPushed();
});
