<?php

namespace App\Helpers;

use App\Models\Server;
use App\Models\SslCertificate;
use Carbon\CarbonImmutable;

class SslHelper
{
    private const DEFAULT_ORGANIZATION_NAME = 'Coolify';

    private const DEFAULT_COUNTRY_CODE = 'ZZ';

    private const DEFAULT_STATE = 'Default';

    public static function generateSslCertificate(
        string $commonName,
        array $subjectAlternativeNames = [],
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?int $serverId = null,
        int $validityDays = 365,
        ?string $caCert = null,
        ?string $caKey = null,
        bool $isCaCertificate = false,
        ?string $configurationDir = null,
        ?string $mountPath = null
    ): SslCertificate {

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

            if (! is_null($serverId) && ! $isCaCertificate) {
                $server = Server::find($serverId);
                if ($server) {
                    $ip = $server->getIp;
                    if ($ip) {
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                            $subjectAlternativeNames[] = "IP:$ip";
                        } else {
                            $subjectAlternativeNames[] = "DNS:$ip";
                        }
                    }
                }
            }

            $subjectAlternativeNames = array_unique(
                array_merge(["DNS:$commonName"], $subjectAlternativeNames)
            );
            $certificateSubject = [
                'commonName' => $commonName,
                'subjectAltName' => $subjectAlternativeNames,
                'organizationName' => self::DEFAULT_ORGANIZATION_NAME,
                'countryName' => self::DEFAULT_COUNTRY_CODE,
                'stateOrProvinceName' => self::DEFAULT_STATE,
            ];

            $csr = openssl_csr_new($certificateSubject, $privateKey, [
                'digest_alg' => 'sha512',
                'config' => null,
                'encrypt_key' => false,
            ]);

            if ($csr === false) {
                throw new \RuntimeException('Failed to generate CSR: '.openssl_error_string());
            }

            $certificate = openssl_csr_sign(
                $csr,
                $caCert ?? null,
                $caKey ?? $privateKey,
                $validityDays,
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

            SslCertificate::query()
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->where('server_id', $serverId)
                ->delete();

            $sslCertificate = SslCertificate::create([
                'ssl_certificate' => $certificateStr,
                'ssl_private_key' => $privateKeyStr,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'server_id' => $serverId,
                'configuration_dir' => $configurationDir,
                'valid_until' => CarbonImmutable::now()->addDays($validityDays),
                'is_ca_certificate' => $isCaCertificate,
                'common_name' => $commonName,
                'subject_alternative_names' => $subjectAlternativeNames,
            ]);

            if ($configurationDir && $mountPath && $resourceType && $resourceId) {
                $model = app($resourceType)->find($resourceId);

                $model->fileStorages()
                    ->where('resource_type', $model->getMorphClass())
                    ->where('resource_id', $model->id)
                    ->get()
                    ->filter(function ($storage) use ($mountPath) {
                        return in_array($storage->mount_path, [
                            $mountPath.'/server.crt',
                            $mountPath.'/server.key',
                        ]);
                    })
                    ->each(function ($storage) {
                        $storage->delete();
                    });

                $model->fileStorages()->create([
                    'fs_path' => $configurationDir.'/ssl/server.crt',
                    'mount_path' => $mountPath.'/server.crt',
                    'content' => $certificateStr,
                    'is_directory' => false,
                    'chmod' => '644',
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ]);

                $model->fileStorages()->create([
                    'fs_path' => $configurationDir.'/ssl/server.key',
                    'mount_path' => $mountPath.'/server.key',
                    'content' => $privateKeyStr,
                    'is_directory' => false,
                    'chmod' => '600',
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                ]);
            }

            return $sslCertificate;
        } catch (\Throwable $e) {
            throw new \RuntimeException('SSL Certificate generation failed: '.$e->getMessage(), 0, $e);
        }
    }
}
