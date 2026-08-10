<?php

/**
 * Shared domain URL helpers used by application/service parsers and updateCompose.
 *
 * TDD targets:
 * - getHostWithoutPort(): COOLIFY_FQDN / SERVICE_FQDN host-only form (no scheme, no port)
 * - firstDomainFromList(): comma-separated FQDN lists (same as updateCompose / serviceParser)
 * - getFqdnWithoutPort() remains the scheme+host form for COOLIFY_URL / SERVICE_URL
 */

// ---------------------------------------------------------------------------
// getHostWithoutPort — expected behavior (fails until helper exists / is correct)
// ---------------------------------------------------------------------------

test('getHostWithoutPort strips scheme and port from simple urls', function () {
    expect(getHostWithoutPort('http://git.example.com:80'))->toBe('git.example.com')
        ->and(getHostWithoutPort('https://n8n.example.com:5678'))->toBe('n8n.example.com')
        ->and(getHostWithoutPort('http://git.example.com'))->toBe('git.example.com');
});

test('getHostWithoutPort preserves path segments', function () {
    expect(getHostWithoutPort('http://git.example.com:80/v1/realtime'))
        ->toBe('git.example.com/v1/realtime');
});

test('getHostWithoutPort keeps real host when credentials are present', function () {
    // Legacy COOLIFY_FQDN strip: replace scheme then before(':') → "user"
    expect(getHostWithoutPort('http://user:secret@git.example.com:80'))
        ->toBe('git.example.com');
});

test('getHostWithoutPort handles scheme-less host:port', function () {
    expect(getHostWithoutPort('git.example.com:80'))->toBe('git.example.com');
});

test('getHostWithoutPort returns original when host cannot be parsed', function () {
    expect(getHostWithoutPort('not-a-url'))->toBe('not-a-url');
});

test('legacy COOLIFY_FQDN strip corrupts credential urls (documents why we refactor)', function () {
    $fqdn = 'http://user:secret@git.example.com:80';

    $legacy = (string) str($fqdn)
        ->replace('http://', '')
        ->replace('https://', '')
        ->before(':');

    expect($legacy)->toBe('user')
        ->and($legacy)->not->toBe(getHostWithoutPort($fqdn));
});

// ---------------------------------------------------------------------------
// firstDomainFromList
// ---------------------------------------------------------------------------

test('firstDomainFromList returns the first comma-separated domain trimmed', function () {
    expect(firstDomainFromList('http://a.example.com:80,http://b.example.com:80'))
        ->toBe('http://a.example.com:80')
        ->and(firstDomainFromList('  https://only.example.com  '))
        ->toBe('https://only.example.com')
        ->and(firstDomainFromList(null))
        ->toBe('')
        ->and(firstDomainFromList(''))
        ->toBe('');
});

// ---------------------------------------------------------------------------
// updateCompose / parser pairing: URL + FQDN from the same helpers
// ---------------------------------------------------------------------------

test('url and host helpers stay paired for ported service domains', function () {
    $fqdn = 'http://git.example.com:80';

    $urlValue = getFqdnWithoutPort($fqdn);
    $fqdnValue = getHostWithoutPort($fqdn);
    $port = '80';

    expect($urlValue)->toBe('http://git.example.com')
        ->and($fqdnValue)->toBe('git.example.com')
        ->and($urlValue.':'.$port)->toBe('http://git.example.com:80')
        ->and($fqdnValue.':'.$port)->toBe('git.example.com:80')
        ->and($urlValue.':'.$port)->not->toContain('/:');
});

test('first domain then helpers match multi-domain service FQDN handling', function () {
    $saved = 'http://git.example.com:80,http://git-alt.example.com:80';
    $first = firstDomainFromList($saved);

    expect($first)->toBe('http://git.example.com:80')
        ->and(getFqdnWithoutPort($first))->toBe('http://git.example.com')
        ->and(getHostWithoutPort($first))->toBe('git.example.com');
});
