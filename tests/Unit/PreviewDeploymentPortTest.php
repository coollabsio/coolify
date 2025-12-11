<?php

/**
 * Unit tests for preview deployment port handling.
 *
 * Tests verify that preview FQDNs correctly preserve the port from the original
 * domain URL, which is required for Caddy proxy labels to work correctly.
 *
 * @see https://github.com/coollabsio/coolify/issues/2184
 */

use Spatie\Url\Url;

it('extracts port from domain URL with custom port', function () {
    $domain = 'https://example.com:3000';
    $url = Url::fromString($domain);

    $port = $url->getPort();

    expect($port)->toBe(3000);
});

it('returns null port for domain URL without port', function () {
    $domain = 'https://example.com';
    $url = Url::fromString($domain);

    $port = $url->getPort();

    expect($port)->toBeNull();
});

it('extracts port from HTTP URL with custom port', function () {
    $domain = 'http://example.com:8080';
    $url = Url::fromString($domain);

    $port = $url->getPort();

    expect($port)->toBe(8080);
});

it('generates preview FQDN with port preserved', function () {
    $domain = 'https://example.com:3000';
    $url = Url::fromString($domain);
    $template = '{{pr_id}}.{{domain}}';
    $pullRequestId = 42;

    $host = $url->getHost();
    $schema = $url->getScheme();
    $portInt = $url->getPort();
    $port = $portInt !== null ? ':'.$portInt : '';

    $preview_fqdn = str_replace('{{random}}', 'abc123', $template);
    $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
    $preview_fqdn = str_replace('{{pr_id}}', $pullRequestId, $preview_fqdn);
    $preview_fqdn = "$schema://$preview_fqdn{$port}";

    expect($preview_fqdn)->toBe('https://42.example.com:3000');
});

it('generates preview FQDN without port when original has no port', function () {
    $domain = 'https://example.com';
    $url = Url::fromString($domain);
    $template = '{{pr_id}}.{{domain}}';
    $pullRequestId = 42;

    $host = $url->getHost();
    $schema = $url->getScheme();
    $portInt = $url->getPort();
    $port = $portInt !== null ? ':'.$portInt : '';

    $preview_fqdn = str_replace('{{random}}', 'abc123', $template);
    $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
    $preview_fqdn = str_replace('{{pr_id}}', $pullRequestId, $preview_fqdn);
    $preview_fqdn = "$schema://$preview_fqdn{$port}";

    expect($preview_fqdn)->toBe('https://42.example.com');
});

it('handles multiple domains with different ports', function () {
    $domains = [
        'https://app.example.com:3000',
        'https://api.example.com:8080',
        'https://web.example.com',
    ];

    $preview_fqdns = [];
    $template = 'pr-{{pr_id}}.{{domain}}';
    $pullRequestId = 123;

    foreach ($domains as $domain) {
        $url = Url::fromString($domain);
        $host = $url->getHost();
        $schema = $url->getScheme();
        $portInt = $url->getPort();
        $port = $portInt !== null ? ':'.$portInt : '';

        $preview_fqdn = str_replace('{{random}}', 'xyz', $template);
        $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
        $preview_fqdn = str_replace('{{pr_id}}', $pullRequestId, $preview_fqdn);
        $preview_fqdn = "$schema://$preview_fqdn{$port}";
        $preview_fqdns[] = $preview_fqdn;
    }

    expect($preview_fqdns[0])->toBe('https://pr-123.app.example.com:3000');
    expect($preview_fqdns[1])->toBe('https://pr-123.api.example.com:8080');
    expect($preview_fqdns[2])->toBe('https://pr-123.web.example.com');
});

it('extracts all URL components correctly', function () {
    $domain = 'https://app.example.com:3000/api/v1';
    $url = Url::fromString($domain);

    expect($url->getScheme())->toBe('https');
    expect($url->getHost())->toBe('app.example.com');
    expect($url->getPort())->toBe(3000);
    expect($url->getPath())->toBe('/api/v1');
});

it('formats port string correctly for URL construction', function () {
    // Test port formatting logic
    $testCases = [
        ['port' => 3000, 'expected' => ':3000'],
        ['port' => 8080, 'expected' => ':8080'],
        ['port' => 80, 'expected' => ':80'],
        ['port' => 443, 'expected' => ':443'],
        ['port' => null, 'expected' => ''],
    ];

    foreach ($testCases as $case) {
        $portInt = $case['port'];
        $port = $portInt !== null ? ':'.$portInt : '';

        expect($port)->toBe($case['expected']);
    }
});
