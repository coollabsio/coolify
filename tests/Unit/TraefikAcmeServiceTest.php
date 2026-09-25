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

    $updated = json_decode($service->deleteCertificate(
        traefikAcmeFixture(),
        'letsencrypt',
        $certificates[0]['id'],
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
        ->and(fn () => $service->deleteCertificate(traefikAcmeFixture(), 'letsencrypt', 'missing'))
        ->toThrow(RuntimeException::class, 'could not be found');
});
