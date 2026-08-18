<?php

use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

test('server with no CA certificate returns null from sslCertificates query', function () {
    $caCert = $this->server->sslCertificates()
        ->where('is_ca_certificate', true)
        ->first();

    expect($caCert)->toBeNull();
});

test('accessing property on null CA cert throws an error', function () {
    // This test verifies the exact scenario that caused the 500 error:
    // querying for a CA cert on a server that has none, then trying to access properties
    $caCert = $this->server->sslCertificates()
        ->where('is_ca_certificate', true)
        ->first();

    expect($caCert)->toBeNull();

    // Without the fix, the code would do:
    // caCert: $caCert->ssl_certificate  <-- 500 error
    expect(fn () => $caCert->ssl_certificate)
        ->toThrow(ErrorException::class);
});

test('CA certificate can be retrieved when it exists on the server', function () {
    // Create a CA certificate directly (simulating what generateCaCertificate does)
    SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => 'test-ca-cert',
        'ssl_private_key' => 'test-ca-key',
        'common_name' => 'Coolify CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    $caCert = $this->server->sslCertificates()
        ->where('is_ca_certificate', true)
        ->first();

    expect($caCert)->not->toBeNull()
        ->and($caCert->is_ca_certificate)->toBeTruthy()
        ->and($caCert->ssl_certificate)->toBe('test-ca-cert')
        ->and($caCert->ssl_private_key)->toBe('test-ca-key');
});

test('non-CA certificate is not returned when querying for CA certificate', function () {
    // Create only a regular (non-CA) certificate
    SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => false,
        'ssl_certificate' => 'test-cert',
        'ssl_private_key' => 'test-key',
        'common_name' => 'test-db-uuid',
        'valid_until' => now()->addYear(),
    ]);

    $caCert = $this->server->sslCertificates()
        ->where('is_ca_certificate', true)
        ->first();

    // The CA cert query should return null since only a regular cert exists
    expect($caCert)->toBeNull();
});
