<?php

namespace App\Services;

use RuntimeException;

class TraefikAcmeService
{
    /**
     * @return array<int, array{id: string, resolver: string, main_domain: string, sans: array<int, string>, store: ?string, expires_at: ?string}>
     */
    public function certificates(string $contents): array
    {
        $data = $this->decode($contents);
        $certificates = [];

        foreach ($data as $resolver => $resolverData) {
            if (! is_string($resolver) || ! is_array($resolverData)) {
                continue;
            }

            $resolverCertificates = data_get($resolverData, 'Certificates', []);
            if (! is_array($resolverCertificates)) {
                continue;
            }

            foreach ($resolverCertificates as $certificate) {
                if (! is_array($certificate)) {
                    continue;
                }

                $mainDomain = data_get($certificate, 'domain.main');
                if (! is_string($mainDomain) || $mainDomain === '') {
                    continue;
                }

                $certificateSans = data_get($certificate, 'domain.sans', []);
                $sans = array_values(array_filter(
                    is_array($certificateSans) ? $certificateSans : [],
                    fn (mixed $domain): bool => is_string($domain) && $domain !== '',
                ));

                $certificates[] = [
                    'id' => $this->certificateId($resolver, $certificate),
                    'resolver' => $resolver,
                    'main_domain' => $mainDomain,
                    'sans' => $sans,
                    'store' => is_string(data_get($certificate, 'Store')) ? data_get($certificate, 'Store') : null,
                    'expires_at' => $this->expirationDate(data_get($certificate, 'certificate')),
                ];
            }
        }

        return $certificates;
    }

    /**
     * Removes the certificates with the given identifiers from every resolver.
     *
     * @param  array<int, string>  $certificateIds
     */
    public function deleteCertificates(string $contents, array $certificateIds): string
    {
        $data = $this->decode($contents);
        $pending = array_fill_keys($certificateIds, true);

        foreach ($data as $resolver => $resolverData) {
            $certificates = is_string($resolver) && is_array($resolverData) ? ($resolverData['Certificates'] ?? null) : null;
            if (! is_array($certificates)) {
                continue;
            }

            $remaining = [];
            foreach ($certificates as $certificate) {
                $id = is_array($certificate) ? $this->certificateId($resolver, $certificate) : null;
                if ($id !== null && isset($pending[$id])) {
                    unset($pending[$id]);

                    continue;
                }

                $remaining[] = $certificate;
            }

            $data[$resolver]['Certificates'] = $remaining;
        }

        if ($certificateIds === [] || $pending !== []) {
            throw new RuntimeException('The selected certificate could not be found.');
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    /**
     * Every host name in the text, such as the hosts of Traefik rules, labels, and resource URLs.
     *
     * @return array<string, true>
     */
    public function hostnames(string $text): array
    {
        preg_match_all('/(?<![a-z0-9-])(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}(?![a-z0-9-])/', strtolower($text), $matches);

        return array_fill_keys($matches[0], true);
    }

    /**
     * True when a host in use matches the main domain or an alternative name of the certificate.
     *
     * @param  array{main_domain: string, sans: array<int, string>}  $certificate
     * @param  array<string, true>  $hostnames
     */
    public function isInUse(array $certificate, array $hostnames): bool
    {
        foreach ([$certificate['main_domain'], ...$certificate['sans']] as $domain) {
            $domain = strtolower($domain);
            if (isset($hostnames[$domain])) {
                return true;
            }

            // A wildcard certificate covers exactly one label below its domain.
            if (str_starts_with($domain, '*.')) {
                $suffix = substr($domain, 1);
                foreach (array_keys($hostnames) as $hostname) {
                    $label = str_ends_with($hostname, $suffix) ? substr($hostname, 0, -strlen($suffix)) : '';
                    if ($label !== '' && ! str_contains($label, '.')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function decode(string $contents): array
    {
        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('The Traefik ACME file contains invalid JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException('The Traefik ACME file contains invalid JSON.');
        }

        return $data;
    }

    /** @param array<string, mixed> $certificate */
    private function certificateId(string $resolver, array $certificate): string
    {
        return hash('sha256', $resolver."\0".json_encode($certificate, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function expirationDate(mixed $encodedCertificate): ?string
    {
        if (! is_string($encodedCertificate) || $encodedCertificate === '') {
            return null;
        }

        // Traefik stores the PEM certificate chain as base64. OpenSSL reads the first certificate.
        $pem = base64_decode($encodedCertificate, true);
        if ($pem === false) {
            return null;
        }

        $details = openssl_x509_parse($pem);
        $expiresAt = data_get($details, 'validTo_time_t');

        return is_int($expiresAt) ? gmdate('Y-m-d H:i:s', $expiresAt) : null;
    }
}
