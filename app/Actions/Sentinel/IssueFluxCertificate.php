<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class IssueFluxCertificate
{
    use AsAction;

    /**
     * @param  list<string>  $identities
     */
    public function handle(array $identities): FluxCertificate
    {
        Validator::make(['identities' => $identities], [
            'identities' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'identities.*' => ['required', 'string', 'max:253', 'distinct:ignore_case'],
        ])->validate();

        $subjectAlternativeNames = [];
        foreach ($identities as $index => $identity) {
            if (filter_var($identity, FILTER_VALIDATE_IP) !== false) {
                $subjectAlternativeNames[] = 'IP:'.$identity;
            } elseif (filter_var($identity, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                && ! preg_match('/^[\d.]+$/D', $identity)) {
                $subjectAlternativeNames[] = 'DNS:'.$identity;
            } else {
                throw ValidationException::withMessages(["identities.{$index}" => 'Use an exact DNS name or IP address without a scheme, port, or wildcard.']);
            }
        }

        $authority = EnsureFluxCertificateAuthority::run();
        $config = tmpfile();
        if ($config === false) {
            throw new RuntimeException('Cannot create the Flux certificate OpenSSL configuration.');
        }

        try {
            $contents = <<<'CONFIG'
[req]
distinguished_name = dn
[dn]
[server_extensions]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = serverAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
CONFIG;
            $contents .= "\nsubjectAltName = ".implode(',', $subjectAlternativeNames)."\n";
            if (fwrite($config, $contents) !== strlen($contents)) {
                throw new RuntimeException('Cannot write the Flux certificate OpenSSL configuration.');
            }

            $options = [
                'config' => stream_get_meta_data($config)['uri'],
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name' => 'prime256v1',
                'digest_alg' => 'sha256',
                'x509_extensions' => 'server_extensions',
            ];
            $key = openssl_pkey_new($options);
            if ($key === false) {
                throw new RuntimeException('Cannot generate the Flux certificate private key.');
            }

            $request = openssl_csr_new(['commonName' => 'Coolify Flux'], $key, $options);
            if ($request === false) {
                throw new RuntimeException('Cannot generate the Flux certificate signing request.');
            }

            $certificate = openssl_csr_sign($request, $authority->certificate_pem, $authority->private_key_pem, 90, $options, serial_hex: bin2hex(random_bytes(16)));
            if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($key, $privateKeyPem, null, $options)) {
                throw new RuntimeException('Cannot sign or export the Flux certificate.');
            }

            $parsed = openssl_x509_parse($certificate);
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
            if ($parsed === false || $fingerprint === false) {
                throw new RuntimeException('Cannot read the Flux certificate.');
            }

            if ($parsed['validFrom_time_t'] < $authority->valid_from->timestamp
                || $parsed['validTo_time_t'] > $authority->valid_until->timestamp) {
                throw new RuntimeException('The Flux CA cannot cover the full certificate validity period.');
            }

            return $authority->certificates()->create([
                'version' => 1,
                'certificate_pem' => $certificatePem,
                'private_key_pem' => $privateKeyPem,
                'fingerprint' => $fingerprint,
                'serial_number' => strtolower($parsed['serialNumberHex']),
                'identities' => $identities,
                'valid_from' => CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']),
                'valid_until' => CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']),
                'state' => 'active',
            ]);
        } finally {
            fclose($config);
        }
    }
}
