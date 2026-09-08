<?php

namespace App\Support;

class DomainPortOverrides
{
    /**
     * @param  array<string, int|string>|null  $overrides
     * @return array<string, int|string>
     */
    public static function sorted(?array $overrides): array
    {
        return collect($overrides ?? [])->sortKeys()->all();
    }

    public static function withoutPort(string $url): string
    {
        $parts = DomainUrlParts::split($url);

        return DomainUrlParts::compose($parts['scheme'], $parts['host'], path: $parts['path']);
    }

    /**
     * @param  array<string, int|string>|null  $existing
     * @return array{fqdn: ?string, overrides: ?array<string, int>}
     */
    public static function normalize(?string $fqdn, ?array $existing): array
    {
        if (blank($fqdn)) {
            return ['fqdn' => null, 'overrides' => null];
        }

        $existingOverrides = $existing ?? [];
        $normalizedDomains = collect(explode(',', $fqdn))
            ->map(fn (string $domain): string => trim($domain))
            ->filter()
            ->map(function (string $domain) use ($existingOverrides): array {
                $portlessDomain = self::withoutPort($domain);
                $parts = DomainUrlParts::split($domain);
                $port = $parts['port'] !== ''
                    ? (int) $parts['port']
                    : ($existingOverrides[$portlessDomain] ?? null);

                return ['domain' => $portlessDomain, 'port' => $port];
            })
            ->keyBy('domain')
            ->values();

        $effectiveOverrides = $normalizedDomains
            ->filter(fn (array $domain): bool => filled($domain['port']))
            ->mapWithKeys(fn (array $domain): array => [$domain['domain'] => (int) $domain['port']]);

        $normalizedDomains = $normalizedDomains->map(function (array $domain) use ($effectiveOverrides): array {
            if (filled($domain['port'])) {
                return $domain;
            }

            $counterpart = self::wwwCounterpart($domain['domain']);
            $domain['port'] = $counterpart === null ? null : $effectiveOverrides->get($counterpart);

            return $domain;
        });

        $normalizedFqdn = $normalizedDomains->pluck('domain')->implode(',');
        $overrides = $normalizedDomains
            ->filter(fn (array $domain): bool => filled($domain['port']))
            ->mapWithKeys(fn (array $domain): array => [$domain['domain'] => (int) $domain['port']])
            ->all();

        return [
            'fqdn' => $normalizedFqdn === '' ? null : $normalizedFqdn,
            'overrides' => $overrides ?: null,
        ];
    }

    private static function wwwCounterpart(string $url): ?string
    {
        $parts = DomainUrlParts::split($url);
        $host = $parts['host'];

        if ($host === '') {
            return null;
        }

        $counterpartHost = str_starts_with(strtolower($host), 'www.')
            ? substr($host, 4)
            : 'www.'.$host;

        return DomainUrlParts::compose($parts['scheme'], $counterpartHost, path: $parts['path']);
    }
}
