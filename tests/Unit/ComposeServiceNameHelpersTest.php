<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use Illuminate\Support\Collection;

test('normalizeComposeServiceName replaces hyphens and dots', function () {
    expect(normalizeComposeServiceName('another-service'))->toBe('another_service')
        ->and(normalizeComposeServiceName('api.test'))->toBe('api_test')
        ->and(normalizeComposeServiceName('api'))->toBe('api');
});

test('findComposeServiceName resolves legacy underscore keys to original names', function () {
    $services = ['another-service', 'api.test', 'api'];

    expect(findComposeServiceName('another-service', $services))->toBe('another-service')
        ->and(findComposeServiceName('another_service', $services))->toBe('another-service')
        ->and(findComposeServiceName('api.test', $services))->toBe('api.test')
        ->and(findComposeServiceName('api_test', $services))->toBe('api.test')
        ->and(findComposeServiceName('missing', $services))->toBeNull();
});

test('getComposeServiceDomainString reads original and legacy keys without dotted data_get bugs', function () {
    $domains = [
        'api.test' => ['domain' => 'https://dotted.example.com'],
        'another_service' => ['domain' => 'https://legacy.example.com'],
    ];

    expect(getComposeServiceDomainString($domains, 'api.test'))->toBe('https://dotted.example.com')
        ->and(getComposeServiceDomainString($domains, 'another-service'))->toBe('https://legacy.example.com')
        ->and(getComposeServiceDomainString($domains, 'missing'))->toBeNull();
});

test('putComposeServiceDomain writes original service keys and drops twin underscore keys', function () {
    $domains = putComposeServiceDomain(
        ['another_service' => ['domain' => 'https://old.example.com']],
        'another-service',
        'https://new.example.com',
        ['another-service', 'analytics'],
    );

    expect($domains)->toHaveKey('another-service')
        ->and($domains)->not->toHaveKey('another_service')
        ->and($domains['another-service']['domain'])->toBe('https://new.example.com');
});

test('rekeyComposeDomainsToServiceNames migrates underscore keys to original compose names', function () {
    $rekeyed = rekeyComposeDomainsToServiceNames(
        [
            'another_service' => ['domain' => 'https://hyphen.example.com'],
            'api_test' => ['domain' => 'https://dotted.example.com'],
            'orphan' => ['domain' => 'https://orphan.example.com'],
        ],
        ['another-service', 'api.test'],
    );

    expect($rekeyed)->toHaveKey('another-service')
        ->and($rekeyed)->not->toHaveKey('another_service')
        ->and($rekeyed['another-service']['domain'])->toBe('https://hyphen.example.com')
        ->and($rekeyed)->toHaveKey('api.test')
        ->and($rekeyed['api.test']['domain'])->toBe('https://dotted.example.com')
        ->and($rekeyed)->toHaveKey('orphan');
});

test('rekeyComposeDomainsToServiceNames prefers the canonical domain regardless of twin key order', function (array $domains) {
    $rekeyed = rekeyComposeDomainsToServiceNames($domains, ['another-service']);

    expect($rekeyed['another-service']['domain'])->toBe('https://canonical.example.com');
})->with([
    'legacy key first' => [[
        'another_service' => ['domain' => 'https://legacy.example.com'],
        'another-service' => ['domain' => 'https://canonical.example.com'],
    ]],
    'canonical key first' => [[
        'another-service' => ['domain' => 'https://canonical.example.com'],
        'another_service' => ['domain' => 'https://legacy.example.com'],
    ]],
]);

test('rekeyComposeDomainsToServiceNames never lets empty canonical wipe filled legacy', function (array $domains) {
    $rekeyed = rekeyComposeDomainsToServiceNames($domains, ['another-service']);

    expect($rekeyed)->toHaveKey('another-service')
        ->and($rekeyed)->not->toHaveKey('another_service')
        ->and($rekeyed['another-service']['domain'])->toBe('https://legacy.example.com');
})->with([
    'empty canonical first' => [[
        'another-service' => ['domain' => ''],
        'another_service' => ['domain' => 'https://legacy.example.com'],
    ]],
    'filled legacy first' => [[
        'another_service' => ['domain' => 'https://legacy.example.com'],
        'another-service' => ['domain' => ''],
    ]],
]);

test('getComposeServiceDomainString prefers filled twin over blank original key', function () {
    $domains = [
        'another-service' => ['domain' => ''],
        'another_service' => ['domain' => 'https://legacy.example.com'],
    ];

    expect(getComposeServiceDomainString($domains, 'another-service'))->toBe('https://legacy.example.com')
        ->and(getComposeServiceDomainString($domains, 'another_service'))->toBe('https://legacy.example.com');
});

test('preferredComposeServiceNamesFromDomainKeys collapses underscore twins', function () {
    expect(preferredComposeServiceNamesFromDomainKeys(['web_api', 'web-api', 'api']))->toEqualCanonicalizing(['web-api', 'api']);
});

test('legacy underscore domain keys still resolve for hyphenated compose services', function () {
    // Existing production shape: domains stored under underscore keys only.
    $legacy = [
        'another_service' => ['domain' => 'https://legacy.example.com'],
        'web' => ['domain' => 'https://web.example.com'],
    ];

    expect(getComposeServiceDomainString($legacy, 'another-service'))->toBe('https://legacy.example.com')
        ->and(getComposeServiceDomainString($legacy, 'another_service'))->toBe('https://legacy.example.com')
        ->and(getComposeServiceDomainString($legacy, 'web'))->toBe('https://web.example.com');
});

test('SERVICE env keys stay underscore-normalized for both storage shapes', function () {
    // Mirrors ApplicationDeploymentJob: domain map key may be original or legacy.
    foreach (['another-service', 'another_service'] as $storageKey) {
        $envKey = str(normalizeComposeServiceName($storageKey))->upper()->toString();
        expect($envKey)->toBe('ANOTHER_SERVICE')
            ->and('SERVICE_URL_'.$envKey)->toBe('SERVICE_URL_ANOTHER_SERVICE');
    }
});

test('findComposeServiceName maps SERVICE env fragments used by serviceParser magic path', function () {
    // serviceParser historically only did underscore→hyphen, so API_TEST became name "api-test"
    // and missed compose service "api.test". normalize-based lookup finds the real name.
    $services = ['another-service', 'api.test', 'web'];

    expect(findComposeServiceName('another_service', $services))->toBe('another-service')
        ->and(findComposeServiceName('api_test', $services))->toBe('api.test')
        ->and(findComposeServiceName('api-test', $services))->toBe('api.test') // same normalized form
        ->and(findComposeServiceName('web', $services))->toBe('web');

    // Exact DB lookup the old serviceParser used would miss dotted names:
    expect(in_array('api-test', $services, true))->toBeFalse()
        ->and(in_array('api.test', $services, true))->toBeTrue();
});

test('normalized service name collisions only resolve exact matches', function () {
    $services = ['api-test', 'api.test'];

    expect(findComposeServiceName('api-test', $services))->toBe('api-test')
        ->and(findComposeServiceName('api.test', $services))->toBe('api.test')
        ->and(findComposeServiceName('api_test', $services))->toBeNull();
});

test('rekeyComposeDomainsToServiceNames keeps ambiguous normalized keys separate', function () {
    $rekeyed = rekeyComposeDomainsToServiceNames(
        [
            'api-test' => ['domain' => 'https://hyphen.example.com'],
            'api.test' => ['domain' => 'https://dot.example.com'],
            'api_test' => ['domain' => 'https://legacy.example.com'],
        ],
        ['api-test', 'api.test'],
    );

    expect($rekeyed['api-test']['domain'])->toBe('https://hyphen.example.com')
        ->and($rekeyed['api.test']['domain'])->toBe('https://dot.example.com')
        ->and($rekeyed['api_test']['domain'])->toBe('https://legacy.example.com');
});

test('empty parsed compose services do not wipe existing domains', function () {
    $application = new Application;
    $application->docker_compose_domains = json_encode([
        'web' => ['domain' => 'https://web.example.com'],
    ]);

    $method = new ReflectionMethod($application, 'reconcileDockerComposeDomains');
    $method->invoke($application, collect(['services' => []]));

    expect($application->docker_compose_domains)->toBe(json_encode([
        'web' => ['domain' => 'https://web.example.com'],
    ]));
});

test('normalized service collisions retain domains under their exact compose keys', function () {
    $domains = collect([
        'api-test' => ['domain' => 'https://hyphen.example.com'],
        'api.test' => ['domain' => 'https://dot.example.com'],
    ]);

    $rekeyed = rekeyComposeDomainsToServiceNames($domains, collect(['api-test', 'api.test']));

    expect($rekeyed)->toBe([
        'api-test' => ['domain' => 'https://hyphen.example.com'],
        'api.test' => ['domain' => 'https://dot.example.com'],
    ]);
});

test('General rekeys form domains to original compose service names', function (array|Collection $domains) {
    $component = new General;
    $component->parsedServices = collect([
        'services' => collect([
            'web-api' => [],
            'metrics.internal' => [],
        ]),
    ]);
    $component->parsedServiceDomains = $domains;

    $method = new ReflectionMethod($component, 'composeDomainsForStorage');
    $stored = $method->invoke($component);

    expect($stored)->toBe([
        'web-api' => ['domain' => 'https://api.example.com'],
        'metrics.internal' => ['domain' => 'https://metrics.example.com'],
    ]);
})->with([
    'array' => [[
        'web_api' => ['domain' => 'https://api.example.com'],
        'metrics_internal' => ['domain' => 'https://metrics.example.com'],
    ]],
    'collection' => [collect([
        'web_api' => ['domain' => 'https://api.example.com'],
        'metrics_internal' => ['domain' => 'https://metrics.example.com'],
    ])],
]);
