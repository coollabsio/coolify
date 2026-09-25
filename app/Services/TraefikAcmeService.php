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

    public function deleteCertificate(string $contents, string $resolver, string $certificateId): string
    {
        $data = $this->decode($contents);
        $certificates = $data[$resolver]['Certificates'] ?? null;

        if (! is_array($certificates)) {
            throw new RuntimeException('The selected certificate could not be found.');
        }

        $remaining = [];
        $deleted = false;

        foreach ($certificates as $certificate) {
            if (! $deleted && is_array($certificate) && hash_equals($this->certificateId($resolver, $certificate), $certificateId)) {
                $deleted = true;

                continue;
            }

            $remaining[] = $certificate;
        }

        if (! $deleted) {
            throw new RuntimeException('The selected certificate could not be found.');
        }

        $data[$resolver]['Certificates'] = $remaining;

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
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

        $der = base64_decode($encodedCertificate, true);
        if ($der === false) {
            return null;
        }

        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
        $details = openssl_x509_parse($pem);
        $expiresAt = data_get($details, 'validTo_time_t');

        return is_int($expiresAt) ? gmdate('Y-m-d H:i:s', $expiresAt) : null;
    }
}
