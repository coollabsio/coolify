<?php

namespace App\Helpers;

use App\Models\SslCertificate;
use Carbon\CarbonImmutable;

class SslHelper
{
    private const DEFAULT_ORGANIZATION_NAME = 'Coolify';

    public static function generateSslCertificate(
        string $commonName,
        array $additionalSans,
        string $resourceType,
        int $resourceId,
        ?string $organizationName = null,
    ): SslCertificate {
        $organizationName ??= self::DEFAULT_ORGANIZATION_NAME;

        try {
            $privateKey = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'secp521r1',
            ]);

            if ($privateKey === false) {
                throw new \RuntimeException('Failed to generate private key: '.openssl_error_string());
            }

            if (! openssl_pkey_export($privateKey, $privateKeyStr)) {
                throw new \RuntimeException('Failed to export private key: '.openssl_error_string());
            }

            $dn = [
                'commonName' => $commonName,
                'organizationName' => $organizationName,
                'subjectAltName' => implode(', ', array_merge(["DNS:$commonName"], $additionalSans)),
            ];

            $csr = openssl_csr_new($dn, $privateKey, [
                'digest_alg' => 'sha512',
                'config' => null,
                'encrypt_key' => false,
            ]);

            if ($csr === false) {
                throw new \RuntimeException('Failed to generate CSR: '.openssl_error_string());
            }

            $certificate = openssl_csr_sign(
                $csr,
                null,
                $privateKey,
                90,
                [
                    'digest_alg' => 'sha512',
                    'config' => null,
                ],
                random_int(PHP_INT_MIN, PHP_INT_MAX)
            );

            if ($certificate === false) {
                throw new \RuntimeException('Failed to sign certificate: '.openssl_error_string());
            }

            if (! openssl_x509_export($certificate, $certificateStr)) {
                throw new \RuntimeException('Failed to export certificate: '.openssl_error_string());
            }

            return SslCertificate::create([
                'ssl_certificate' => $certificateStr,
                'ssl_private_key' => $privateKeyStr,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'valid_until' => CarbonImmutable::now()->addDays(90),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('SSL Certificate generation failed: '.$e->getMessage(), 0, $e);
        }
    }
}
