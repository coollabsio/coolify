<?php

test('traefikServiceNameHash is stable and length 4', function () {
    expect(traefikServiceNameHash('api.test'))->toBe(substr(md5('api.test'), 0, 4))
        ->and(traefikServiceNameHash('api.test'))->toHaveLength(4)
        ->and(traefikServiceNameHash('api.test'))->not->toBe(traefikServiceNameHash('api-test'));
});

test('traefikSafeServiceNameSegment replaces dots and always appends hash', function () {
    $segment = traefikSafeServiceNameSegment('api.test');
    $hash = traefikServiceNameHash('api.test');

    expect($segment)->toBe("api-test-{$hash}")
        ->and($segment)->not->toContain('.')
        ->and(str_ends_with($segment, "-{$hash}"))->toBeTrue();
});

test('traefikSafeServiceNameSegment keeps api.test and api-test distinct', function () {
    $dot = traefikSafeServiceNameSegment('api.test');
    $hyphen = traefikSafeServiceNameSegment('api-test');

    expect($dot)->not->toBe($hyphen)
        ->and($dot)->toStartWith('api-test-')
        ->and($hyphen)->toStartWith('api-test-');
});

test('traefikSafeServiceNameSegment normalizes underscores and odd characters', function () {
    $segment = traefikSafeServiceNameSegment('web_api.v2');
    $hash = traefikServiceNameHash('web_api.v2');

    expect($segment)->toBe("web-api-v2-{$hash}")
        ->and($segment)->not->toContain('.')
        ->and($segment)->not->toContain('_');
});

test('fqdnLabelsForTraefik embeds safe hashed service segment without dotted router names', function () {
    $uuid = 'testuuid1234';
    $hashDot = traefikServiceNameHash('api.test');
    $hashHyphen = traefikServiceNameHash('api-test');

    $dotLabels = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: collect(['https://dot.example.com']),
        is_force_https_enabled: true,
        service_name: 'api.test',
        image: 'nginx:alpine',
    );

    $hyphenLabels = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: collect(['https://hyphen.example.com']),
        is_force_https_enabled: true,
        service_name: 'api-test',
        image: 'nginx:alpine',
    );

    $dotHttps = $dotLabels->first(
        fn (string $line) => str_contains($line, "traefik.http.routers.https-0-{$uuid}-api-test-{$hashDot}.rule=")
    );
    $hyphenHttps = $hyphenLabels->first(
        fn (string $line) => str_contains($line, "traefik.http.routers.https-0-{$uuid}-api-test-{$hashHyphen}.rule=")
    );

    expect($dotHttps)->not->toBeNull()
        ->and($hyphenHttps)->not->toBeNull()
        ->and($dotHttps)->not->toContain('api.test')
        ->and($dotHttps)->not->toBe($hyphenHttps)
        ->and($hashDot)->not->toBe($hashHyphen);

    $ruleLines = $dotLabels->merge($hyphenLabels)->filter(
        fn (string $line) => str_contains($line, 'traefik.http.routers.') && str_contains($line, '.rule=')
    );

    // Traefik label path must stay at traefik.http.routers.{name}.rule (5 segments).
    foreach ($ruleLines as $line) {
        $key = str($line)->before('=')->toString();
        expect(explode('.', $key))->toHaveCount(5);
    }
});

test('fqdnLabelsForTraefik hyphenated services also receive a hash suffix', function () {
    $uuid = 'appuuid';
    $hash = traefikServiceNameHash('another-service');

    $labels = fqdnLabelsForTraefik(
        uuid: $uuid,
        domains: collect(['https://another.example.com']),
        service_name: 'another-service',
        image: 'nginx:alpine',
    );

    $routerLine = $labels->first(
        fn (string $line) => str_contains($line, 'traefik.http.routers.https-0-') && str_contains($line, '.rule=')
    );

    expect($routerLine)->toContain("https-0-{$uuid}-another-service-{$hash}.rule=");
});

test('application labels keep redirect capture groups single escaped before compose generation', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'application-uuid',
        domains: collect(['https://example.com']),
        redirect_direction: 'www',
        escape_redirect_replacement_for_compose: false,
    );

    expect($labels)
        ->toContain('traefik.http.middlewares.0-application-uuid-to-www.redirectregex.replacement=${1}://www.${2}');
});

test('fqdnLabelsForTraefik routes each portless domain to its override port', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'appuuid',
        domains: collect(['https://one.example.com', 'https://two.example.com']),
        onlyPort: 80,
        domainPortOverrides: [
            'https://one.example.com' => 3000,
            'https://two.example.com' => 8080,
        ],
    );

    expect($labels)
        ->toContain('traefik.http.routers.https-0-appuuid.rule=Host(`one.example.com`) && PathPrefix(`/`)')
        ->toContain('traefik.http.services.https-0-appuuid.loadbalancer.server.port=3000')
        ->toContain('traefik.http.routers.https-1-appuuid.rule=Host(`two.example.com`) && PathPrefix(`/`)')
        ->toContain('traefik.http.services.https-1-appuuid.loadbalancer.server.port=8080')
        ->not->toContain('Host(`one.example.com:3000`)')
        ->not->toContain('Host(`two.example.com:8080`)');
});

test('fqdnLabelsForTraefik uses onlyPort when a portless domain has no override', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'appuuid',
        domains: collect(['https://plain.example.com']),
        onlyPort: 4000,
        domainPortOverrides: [],
    );

    expect($labels)
        ->toContain('traefik.http.routers.https-0-appuuid.rule=Host(`plain.example.com`) && PathPrefix(`/`)')
        ->toContain('traefik.http.services.https-0-appuuid.loadbalancer.server.port=4000');
});

test('fqdnLabelsForTraefik keeps routing a legacy port-bearing FQDN without an override map', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'appuuid',
        domains: collect(['https://legacy.example.com:9090']),
        onlyPort: 80,
        domainPortOverrides: [],
    );

    expect($labels)
        ->toContain('traefik.http.routers.https-0-appuuid.rule=Host(`legacy.example.com`) && PathPrefix(`/`)')
        ->toContain('traefik.http.services.https-0-appuuid.loadbalancer.server.port=9090');
});
