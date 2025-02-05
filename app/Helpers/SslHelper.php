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

            $countryCode = self::DEFAULT_COUNTRY_CODE;
            $state = self::DEFAULT_STATE;
            $organization = self::DEFAULT_ORGANIZATION_NAME;

            $altNames = [];
            foreach ($subjectAlternativeNames as $index => $san) {
                [$type, $value] = explode(':', $san, 2);
                $altNames[] = "{$type}.".($index + 1)." = $value";
            }
            $altNamesSection = implode("\n", $altNames);

            $basicConstraints = $isCaCertificate ? 'CA:TRUE' : 'CA:FALSE';
            $keyUsage = $isCaCertificate ? 'keyCertSign, cRLSign' : 'digitalSignature, keyEncipherment';
            $extendedKeyUsage = $isCaCertificate ? '' : 'extendedKeyUsage = serverAuth';

            $config = <<<CONF
                [req]
                prompt = no
                distinguished_name = req_distinguished_name
                req_extensions = v3_req

                [req_distinguished_name]
                C = $countryCode
                ST = $state
                O = $organization
                CN = $commonName

                [v3_req]
                basicConstraints = $basicConstraints
                keyUsage = $keyUsage
                $extendedKeyUsage
                subjectAltName = @alt_names

                [alt_names]
                $altNamesSection
            CONF;

            $tempConfig = tmpfile();
            fwrite($tempConfig, $config);
            $tempConfigPath = stream_get_meta_data($tempConfig)['uri'];

            $csr = openssl_csr_new([
                'commonName' => $commonName,
                'organizationName' => self::DEFAULT_ORGANIZATION_NAME,
                'countryName' => self::DEFAULT_COUNTRY_CODE,
                'stateOrProvinceName' => self::DEFAULT_STATE,
            ], $privateKey, [
                'digest_alg' => 'sha512',
                'config' => $tempConfigPath,
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
                    'config' => $tempConfigPath,
                    'x509_extensions' => 'v3_req',
                ],
                random_int(1, PHP_INT_MAX)
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

            fclose($tempConfig);

            return $sslCertificate;
        } catch (\Throwable $e) {
            throw new \RuntimeException('SSL Certificate generation failed: '.$e->getMessage(), 0, $e);
        }
    }
}
