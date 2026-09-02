<?php

namespace App\Support;

class DomainPortOverrides
{
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
            ->unique('domain')
            ->values();

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
}
