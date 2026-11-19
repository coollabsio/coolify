<?php

use App\Services\TraefikAcmeService;

function traefikAcmeFixture(): string
{
    return json_encode([
        'letsencrypt' => [
            'Account' => ['Email' => 'admin@example.com'],
            'Certificates' => [
                [
                    'domain' => [
                        'main' => 'example.com',
                        'sans' => ['www.example.com', '*.example.com'],
                    ],
                    'certificate' => base64_encode('first-certificate'),
                    'key' => base64_encode('first-key'),
                    'Store' => 'default',
                ],
                [
                    'domain' => ['main' => 'api.example.net'],
                    'certificate' => base64_encode('second-certificate'),
                    'key' => base64_encode('second-key'),
                ],
            ],
        ],
        'zerossl' => [
            'Account' => ['Email' => 'admin@example.org'],
            'Certificates' => [[
                'domain' => ['main' => 'example.org', 'sans' => []],
                'certificate' => base64_encode('third-certificate'),
                'key' => base64_encode('third-key'),
            ]],
        ],
    ], JSON_THROW_ON_ERROR);
}

it('lists every certificate from every ACME resolver without exposing secrets', function () {
    $certificates = app(TraefikAcmeService::class)->certificates(traefikAcmeFixture());

    expect($certificates)->toHaveCount(3)
        ->and($certificates[0])->toMatchArray([
            'resolver' => 'letsencrypt',
            'main_domain' => 'example.com',
            'sans' => ['www.example.com', '*.example.com'],
            'store' => 'default',
        ])
        ->and($certificates[0])->toHaveKeys(['id', 'expires_at'])
        ->and($certificates[0])->not->toHaveKeys(['certificate', 'key', 'account']);
});

it('removes only the selected certificate and preserves all other ACME data', function () {
    $service = app(TraefikAcmeService::class);
    $certificates = $service->certificates(traefikAcmeFixture());

    $updated = json_decode($service->deleteCertificates(
        traefikAcmeFixture(),
        [$certificates[0]['id']],
    ), true, flags: JSON_THROW_ON_ERROR);

    expect($updated['letsencrypt']['Account']['Email'])->toBe('admin@example.com')
        ->and($updated['letsencrypt']['Certificates'])->toHaveCount(1)
        ->and($updated['letsencrypt']['Certificates'][0]['domain']['main'])->toBe('api.example.net')
        ->and($updated['zerossl']['Certificates'])->toHaveCount(1);
});

it('rejects invalid files and unknown certificate identifiers', function () {
    $service = app(TraefikAcmeService::class);

    expect(fn () => $service->certificates('{invalid'))
        ->toThrow(RuntimeException::class, 'invalid JSON')
        ->and(fn () => $service->deleteCertificates(traefikAcmeFixture(), ['missing']))
        ->toThrow(RuntimeException::class, 'could not be found');
});

it('reads the expiry date from the base64-encoded PEM chain Traefik stores', function () {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'example.com'], $key), null, $key, 30);
    openssl_x509_export($certificate, $pem);
    $expiresAt = gmdate('Y-m-d H:i:s', openssl_x509_parse($pem)['validTo_time_t']);

    $contents = json_encode(['letsencrypt' => ['Certificates' => [[
        'domain' => ['main' => 'example.com'],
        'certificate' => base64_encode($pem),
        'key' => base64_encode('key'),
    ]]]], JSON_THROW_ON_ERROR);

    expect(app(TraefikAcmeService::class)->certificates($contents)[0]['expires_at'])->toBe($expiresAt);
});

it('removes several certificates of different resolvers in one pass', function () {
    $service = app(TraefikAcmeService::class);
    $certificates = $service->certificates(traefikAcmeFixture());

    $updated = json_decode($service->deleteCertificates(
        traefikAcmeFixture(),
        [$certificates[1]['id'], $certificates[2]['id']],
    ), true, flags: JSON_THROW_ON_ERROR);

    expect(collect($updated['letsencrypt']['Certificates'])->pluck('domain.main')->all())->toBe(['example.com'])
        ->and($updated['zerossl']['Certificates'])->toBe([])
        ->and($updated['zerossl']['Account']['Email'])->toBe('admin@example.org')
        ->and(fn () => $service->deleteCertificates(traefikAcmeFixture(), [$certificates[0]['id'], 'missing']))
        ->toThrow(RuntimeException::class, 'could not be found');
});

it('finds the host names in Traefik labels, configuration files, and resource URLs', function () {
    $text = '{"traefik.http.routers.http-0-abc.rule":"Host(`App.Example.com`) && PathPrefix(`/`)"}'."\n"
        ."rule: Host(`api.example.net`)\ndomains:\n  - main: '*.wild.example.org'\nhttps://shop.example.com:8443/path,http://www.example.com";

    expect(app(TraefikAcmeService::class)->hostnames($text))
        ->toHaveKeys(['app.example.com', 'api.example.net', '*.wild.example.org', 'shop.example.com', 'www.example.com']);
});

it('treats a certificate as used when a host matches its domain, an alternative name, or its wildcard', function (array $hosts, bool $inUse) {
    $certificate = ['main_domain' => 'Example.com', 'sans' => ['www.example.com', '*.apps.example.com']];

    expect(app(TraefikAcmeService::class)->isInUse($certificate, array_fill_keys($hosts, true)))->toBe($inUse);
})->with([
    'main domain' => [['example.com'], true],
    'alternative name' => [['www.example.com'], true],
    'host below the wildcard' => [['shop.apps.example.com'], true],
    'wildcard named in the configuration' => [['*.apps.example.com'], true],
    'host two levels below the wildcard' => [['a.shop.apps.example.com'], false],
    'parent of the wildcard' => [['apps.example.com'], false],
    'other domain' => [['example.net', 'other.example.com'], false],
    'no hosts' => [[], false],
]);
