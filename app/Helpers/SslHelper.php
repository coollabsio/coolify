<?php

namespace App\Helpers;

use App\Models\SslCertificate;
use Carbon\Carbon;

class SslHelper
{
    private const DEFAULT_KEY_BITS = 4096;

    private const DEFAULT_DIGEST_ALG = 'sha256';

    private const DEFAULT_VALIDITY_YEARS = 10;

    private const DEFAULT_ORG_NAME = 'Coolify';

    public static function generateSslCertificate(
        string $resourceType,
        int $resourceId,
        string $commonName,
        ?Carbon $validUntil = null,
        ?string $organizationName = null
    ): SslCertificate {
        $validUntil ??= Carbon::now()->addYears(self::DEFAULT_VALIDITY_YEARS);
        $organizationName ??= self::DEFAULT_ORG_NAME;

        try {
            $privateKey = openssl_pkey_new([
                'private_key_bits' => self::DEFAULT_KEY_BITS,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'encrypt_key' => true,
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
            ];

            $csr = openssl_csr_new($dn, $privateKey, [
                'digest_alg' => self::DEFAULT_DIGEST_ALG,
                'config' => null,
                'encrypt_key' => true,
            ]);

            if ($csr === false) {
                throw new \RuntimeException('Failed to generate CSR: '.openssl_error_string());
            }

            $validityDays = max(1, Carbon::now()->diffInDays($validUntil));

            $certificate = openssl_csr_sign(
                $csr,
                null,
                $privateKey,
                $validityDays,
                [
                    'digest_alg' => self::DEFAULT_DIGEST_ALG,
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
                'valid_until' => $validUntil,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('SSL Certificate generation failed: '.$e->getMessage(), 0, $e);
        }
    }
}
