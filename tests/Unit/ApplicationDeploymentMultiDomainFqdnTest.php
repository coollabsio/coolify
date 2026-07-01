<?php

use Spatie\Url\Url;

/**
 * Tests for the COOLIFY_URL / COOLIFY_FQDN generation in set_coolify_variables().
 *
 * The application (or preview) fqdn may be a comma-separated list of domains,
 * e.g. a docker compose service with multiple domains. Passing the whole list
 * to Url::fromString() as one URL makes the second scheme's colon parse as a
 * host:port separator, which mangles both generated values:
 *
 *   fqdn: https://a.example.com,https://b.example.com
 *   COOLIFY_URL:  https://a.example.com,https//b.example.com   (colon lost)
 *   COOLIFY_FQDN: a.example.com,https                          (truncated)
 *
 * The fix parses each comma-separated entry separately, mirroring how
 * bootstrap/helpers/parsers.php already handles multi-domain fqdns.
 */

// Simulates the parsing logic from ApplicationDeploymentJob::set_coolify_variables()
function parseCoolifyVariablesFqdn(string $fqdn): array
{
    $parsed = str($fqdn)->explode(',')->map(fn ($entry) => Url::fromString(trim($entry)));
    $host = $parsed->map(fn (Url $entry) => $entry->getHost())->implode(',');
    $url = $parsed->map(fn (Url $entry) => (string) $entry->withPort(null))->implode(',');

    return ['url' => $url, 'fqdn' => $host];
}

it('keeps single-domain behavior: host extracted and port stripped', function () {
    $result = parseCoolifyVariablesFqdn('https://app.example.com:8443');

    expect($result['url'])->toBe('https://app.example.com');
    expect($result['fqdn'])->toBe('app.example.com');
});

it('preserves every scheme colon in a multi-domain fqdn', function () {
    $result = parseCoolifyVariablesFqdn('https://a.example.com,https://b.example.com,https://c.example.com');

    expect($result['url'])->toBe('https://a.example.com,https://b.example.com,https://c.example.com');
    expect($result['url'])->not->toContain('https//');
    expect($result['fqdn'])->toBe('a.example.com,b.example.com,c.example.com');
});

it('strips ports from each entry of a multi-domain fqdn', function () {
    $result = parseCoolifyVariablesFqdn('https://a.example.com:8443,http://b.example.com:8080');

    expect($result['url'])->toBe('https://a.example.com,http://b.example.com');
    expect($result['fqdn'])->toBe('a.example.com,b.example.com');
});

it('trims whitespace around comma-separated entries', function () {
    $result = parseCoolifyVariablesFqdn('https://a.example.com, https://b.example.com');

    expect($result['url'])->toBe('https://a.example.com,https://b.example.com');
    expect($result['fqdn'])->toBe('a.example.com,b.example.com');
});
