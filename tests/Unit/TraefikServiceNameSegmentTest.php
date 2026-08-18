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
