<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificateAuthority;
use App\Models\InstanceSettings;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class EnsureFluxCertificateAuthority
{
    use AsAction;

    public function handle(): FluxCertificateAuthority
    {
        return (new FluxCertificateAuthority)->getConnection()->transaction(function (): FluxCertificateAuthority {
            // Lock an existing singleton, because the CA table can be empty on first use.
            InstanceSettings::query()->lockForUpdate()->findOrFail(0);

            $authority = FluxCertificateAuthority::query()->where('state', 'active')->first();
            if ($authority) {
                return $authority;
            }

            return $this->createAuthority();
        }, 3);
    }

    private function createAuthority(): FluxCertificateAuthority
    {
        $config = tmpfile();
        if ($config === false) {
            throw new RuntimeException('Cannot create the Flux CA OpenSSL configuration.');
        }

        try {
            $contents = <<<'CONFIG'
[req]
distinguished_name = dn
[dn]
[ca_extensions]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
CONFIG;
            if (fwrite($config, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write the Flux CA OpenSSL configuration.');
            }

            $options = [
                'config' => stream_get_meta_data($config)['uri'],
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
                'digest_alg' => 'sha256',
                'x509_extensions' => 'ca_extensions',
            ];
            $key = openssl_pkey_new($options);
            if ($key === false) {
                throw new RuntimeException('Cannot generate the Flux CA private key.');
            }

            $request = openssl_csr_new(['commonName' => 'Coolify Flux Installation CA'], $key, $options);
            if ($request === false) {
                throw new RuntimeException('Cannot generate the Flux CA signing request.');
            }

            $start = CarbonImmutable::now('UTC');
            $days = (int) $start->diffInDays($start->addYearsNoOverflow(100));
            $certificate = openssl_csr_sign($request, null, $key, $days, $options, serial_hex: bin2hex(random_bytes(16)));
            if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $privateKeyPem, null, $options)) {
                throw new RuntimeException('Cannot sign or export the Flux CA certificate.');
            }

            $parsed = openssl_x509_parse($certificate);
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            if ($parsed === false || $fingerprint === false) {
                throw new RuntimeException('Cannot read the Flux CA certificate.');
            }

            return FluxCertificateAuthority::query()->create([
                'version' => (FluxCertificateAuthority::query()->max('version') ?? 0) + 1,
                'certificate_pem' => $certificatePem,
                'private_key_pem' => $privateKeyPem,
                'fingerprint' => $fingerprint,
                'serial_number' => strtolower($parsed['serialNumberHex']),
                'valid_from' => CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']),
                'valid_until' => CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']),
                'state' => 'active',
            ]);
        } finally {
            fclose($config);
        }
    }
}
