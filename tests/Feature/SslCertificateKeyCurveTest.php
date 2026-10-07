<?php

use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function certificateCurve(string $certificate): ?string
{
    return openssl_pkey_get_details(openssl_pkey_get_public($certificate))['ec']['curve_name'] ?? null;
}

it('keeps the CA on P-521 and gives server certificates a P-256 key that Electron clients such as MongoDB Compass accept', function () {
    $server = Server::factory()->create(['team_id' => Team::factory()->create()->id]);

    $caCertificate = SslHelper::generateSslCertificate(
        commonName: 'Coolify CA Certificate',
        serverId: $server->id,
        isCaCertificate: true,
        validityDays: 10 * 365,
    );

    $serverCertificate = SslHelper::generateSslCertificate(
        commonName: 'database',
        serverId: $server->id,
        caCert: $caCertificate->ssl_certificate,
        caKey: $caCertificate->ssl_private_key,
    );

    expect(certificateCurve($caCertificate->ssl_certificate))->toBe('secp521r1')
        ->and(certificateCurve($serverCertificate->ssl_certificate))->toBe('prime256v1')
        ->and(openssl_x509_check_private_key($serverCertificate->ssl_certificate, $serverCertificate->ssl_private_key))->toBeTrue()
        ->and(openssl_x509_verify($serverCertificate->ssl_certificate, openssl_pkey_get_public($caCertificate->ssl_certificate)))->toBe(1);
});
