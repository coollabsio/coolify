<?php

use App\Support\DomainConnect\CloudflareDomainConnect;

function domainConnectTestKeyPair(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);

    return [
        'private' => $privateKey,
        'public' => $details['key'],
    ];
}

beforeEach(function () {
    $pair = domainConnectTestKeyPair();
    $this->privateKey = $pair['private'];
    $this->publicKey = $pair['public'];

    config([
        'constants.coolify.self_hosted' => false, // isCloud()
        'services.domain_connect.provider_id' => 'coolify.io',
        'services.domain_connect.service_id' => 'hosting',
        'services.domain_connect.key_id' => '_dcpubkeyv1',
        'services.domain_connect.private_key' => $this->privateKey,
    ]);
});

it('is configured when a private key is present', function () {
    $connect = new CloudflareDomainConnect;

    expect($connect->isConfigured())->toBeTrue();

    config(['services.domain_connect.private_key' => null]);

    expect((new CloudflareDomainConnect)->isConfigured())->toBeFalse();
});

it('builds a signed cloudflare domain connect apply url like resend', function () {
    $connect = new CloudflareDomainConnect;

    $url = $connect->buildHostingApplyUrl(
        domain: 'example.com',
        ip: '203.0.113.10',
        host: 'app',
    );

    expect($url)
        ->toStartWith('https://dash.cloudflare.com/domainconnect/v2/domainTemplates/providers/coolify.io/services/hosting/apply?')
        ->toContain('domain=example.com')
        ->toContain('host=app')
        ->toContain('ip=203.0.113.10')
        ->toContain('key=_dcpubkeyv1')
        ->toContain('sig=');

    $query = parse_url($url, PHP_URL_QUERY);
    parse_str($query, $params);

    expect($params)
        ->toHaveKey('domain')
        ->toHaveKey('host')
        ->toHaveKey('ip')
        ->toHaveKey('key')
        ->toHaveKey('sig');

    // Signature must be last query parameter (Cloudflare requirement).
    expect(str_ends_with($query, 'sig='.rawurlencode($params['sig'])) || str_ends_with($query, 'sig='.$params['sig']))
        ->toBeTrue();

    // Verify RSA-SHA256 over the query excluding key and sig.
    $parts = [];
    foreach (explode('&', $query) as $part) {
        if (str_starts_with($part, 'key=') || str_starts_with($part, 'sig=')) {
            continue;
        }
        $parts[] = $part;
    }
    $signedPayload = implode('&', $parts);

    $ok = openssl_verify(
        $signedPayload,
        base64_decode($params['sig'], true),
        $this->publicKey,
        OPENSSL_ALGO_SHA256,
    );

    expect($ok)->toBe(1);
});

it('includes empty host for apex domains', function () {
    $connect = new CloudflareDomainConnect;

    $url = $connect->buildHostingApplyUrl(
        domain: 'example.com',
        ip: '203.0.113.10',
        host: null,
    );

    expect($url)->toContain('host=');
});

it('splits hostnames into domain and host', function () {
    expect(CloudflareDomainConnect::splitHostname('example.com'))
        ->toBe(['domain' => 'example.com', 'host' => ''])
        ->and(CloudflareDomainConnect::splitHostname('app.example.com'))
        ->toBe(['domain' => 'example.com', 'host' => 'app'])
        ->and(CloudflareDomainConnect::splitHostname('https://api.staging.example.com/path'))
        ->toBe(['domain' => 'example.com', 'host' => 'api.staging'])
        ->and(CloudflareDomainConnect::splitHostname('app.example.co.uk'))
        ->toBe(['domain' => 'example.co.uk', 'host' => 'app'])
        ->and(CloudflareDomainConnect::splitHostname('api.staging.example.com.au'))
        ->toBe(['domain' => 'example.com.au', 'host' => 'api.staging']);
});

it('rejects invalid domains and ips', function () {
    $connect = new CloudflareDomainConnect;

    expect(fn () => $connect->buildHostingApplyUrl(domain: 'not-a-domain', ip: '203.0.113.10'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $connect->buildHostingApplyUrl(domain: 'example.com', ip: 'not-an-ip'))
        ->toThrow(InvalidArgumentException::class);
});
