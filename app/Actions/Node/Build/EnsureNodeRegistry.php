<?php

namespace App\Actions\Node\Build;

use App\Models\NodeCluster;
use App\Models\NodeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Creates the registry of a cluster on first use and keeps its server
 * certificate valid (decision 0006). The CA is the registry's own: Sentinel
 * trusts any Flux CA certificate for the Flux host, so the Flux CA must not
 * sign a certificate whose key lives on a worker Node.
 */
class EnsureNodeRegistry
{
    use AsAction;

    private const CERTIFICATE_DAYS = 90;

    private const RENEW_BEFORE_DAYS = 30;

    public function handle(NodeCluster $cluster): NodeRegistry
    {
        return DB::transaction(function () use ($cluster): NodeRegistry {
            $registry = NodeRegistry::query()->lockForUpdate()->firstWhere('node_cluster_id', $cluster->id)
                ?? $this->create($cluster);
            if ($registry->certificate_pem === null
                || $registry->certificate_valid_until === null
                || $registry->certificate_valid_until->lt(now()->addDays(self::RENEW_BEFORE_DAYS))) {
                $this->issueCertificate($registry);
            }

            return $registry;
        });
    }

    private function create(NodeCluster $cluster): NodeRegistry
    {
        $options = self::keyOptions();
        $key = openssl_pkey_new($options);
        $request = $key === false ? false : openssl_csr_new(['commonName' => 'Coolify Registry CA'], $key, $options);
        $certificate = $request === false ? false : $this->withConfig(<<<'CONFIG'
[req]
distinguished_name = dn
[dn]
[ca_extensions]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
CONFIG, fn (string $config) => openssl_csr_sign($request, null, $key, 36500, [...$options, 'config' => $config, 'x509_extensions' => 'ca_extensions'], serial_hex: bin2hex(random_bytes(16))));
        if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $privateKeyPem, null, $options)) {
            throw new RuntimeException('Cannot create the registry CA.');
        }
        $pushPassword = Str::random(40);
        $pullPassword = Str::random(40);

        return NodeRegistry::query()->create([
            'node_cluster_id' => $cluster->id,
            'ca_certificate_pem' => $certificatePem,
            'ca_private_key_pem' => $privateKeyPem,
            'push_password' => $pushPassword,
            'pull_password' => $pullPassword,
            'htpasswd' => NodeRegistry::PUSH_USERNAME.':'.password_hash($pushPassword, PASSWORD_BCRYPT)."\n"
                .NodeRegistry::PULL_USERNAME.':'.password_hash($pullPassword, PASSWORD_BCRYPT)."\n",
        ]);
    }

    private function issueCertificate(NodeRegistry $registry): void
    {
        $options = self::keyOptions();
        $key = openssl_pkey_new($options);
        $request = $key === false ? false : openssl_csr_new(['commonName' => 'Coolify Registry'], $key, $options);
        $host = explode(':', NodeRegistry::HOST)[0];
        $certificate = $request === false ? false : $this->withConfig(<<<CONFIG
[req]
distinguished_name = dn
[dn]
[server_extensions]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = serverAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
subjectAltName = DNS:{$host}
CONFIG, fn (string $config) => openssl_csr_sign($request, $registry->ca_certificate_pem, $registry->ca_private_key_pem, self::CERTIFICATE_DAYS, [...$options, 'config' => $config, 'x509_extensions' => 'server_extensions'], serial_hex: bin2hex(random_bytes(16))));
        if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $privateKeyPem, null, $options)) {
            throw new RuntimeException('Cannot issue the registry certificate.');
        }
        $parsed = openssl_x509_parse($certificate);
        $registry->update([
            'certificate_pem' => $certificatePem,
            'private_key_pem' => $privateKeyPem,
            'certificate_valid_until' => CarbonImmutable::createFromTimestampUTC((int) data_get($parsed, 'validTo_time_t')),
        ]);
    }

    /** @return array<string, mixed> */
    private static function keyOptions(): array
    {
        return [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'digest_alg' => 'sha256',
        ];
    }

    /**
     * Runs an OpenSSL call with a temporary configuration file.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private function withConfig(string $contents, callable $callback): mixed
    {
        $file = tmpfile();
        if ($file === false || fwrite($file, $contents."\n") === false) {
            throw new RuntimeException('Cannot write the registry OpenSSL configuration.');
        }
        try {
            return $callback(stream_get_meta_data($file)['uri']);
        } finally {
            fclose($file);
        }
    }
}
