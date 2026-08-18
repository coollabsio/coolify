<?php

/**
 * www<->non-www redirect permanence.
 *
 * Canonical host redirects default to 302 so a direction change is not cached
 * forever by browsers. Opting in flips Traefik's redirectregex.permanent flag
 * and appends Caddy's `permanent` status word, without touching anything else.
 */
function redirectPermanentTraefikLabels(string $direction, bool $permanent, string $domain = 'https://example.com'): array
{
    return fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect([$domain]),
        onlyPort: 3000,
        redirect_direction: $direction,
        is_redirect_permanent: $permanent,
    )->values()->all();
}

function redirectPermanentCaddyLabels(string $direction, bool $permanent, string $domain = 'https://example.com'): array
{
    return fqdnLabelsForCaddy(
        network: 'testnetwork',
        uuid: 'testuuid',
        domains: collect([$domain]),
        onlyPort: 3000,
        redirect_direction: $direction,
        is_redirect_permanent: $permanent,
    )->values()->all();
}

test('traefik redirects are temporary by default', function (string $direction, string $domain, string $middleware) {
    expect(redirectPermanentTraefikLabels($direction, false, $domain))
        ->toContain("traefik.http.middlewares.0-testuuid-{$middleware}.redirectregex.permanent=false");
})->with([
    'to www' => ['www', 'https://example.com', 'to-www'],
    'to non-www' => ['non-www', 'https://www.example.com', 'to-non-www'],
]);

test('traefik redirects become permanent when opted in', function (string $direction, string $domain, string $middleware) {
    expect(redirectPermanentTraefikLabels($direction, true, $domain))
        ->toContain("traefik.http.middlewares.0-testuuid-{$middleware}.redirectregex.permanent=true");
})->with([
    'to www' => ['www', 'https://example.com', 'to-www'],
    'to non-www' => ['non-www', 'https://www.example.com', 'to-non-www'],
]);

test('caddy redirects are temporary by default', function (string $direction, string $domain, string $target) {
    expect(redirectPermanentCaddyLabels($direction, false, $domain))
        ->toContain("caddy_0.redir={$target}{uri}");
})->with([
    'to www' => ['www', 'https://example.com', 'https://www.example.com'],
    'to non-www' => ['non-www', 'https://www.example.com', 'https://example.com'],
]);

test('caddy redirects carry the permanent status word when opted in', function (string $direction, string $domain, string $target) {
    expect(redirectPermanentCaddyLabels($direction, true, $domain))
        ->toContain("caddy_0.redir={$target}{uri} permanent");
})->with([
    'to www' => ['www', 'https://example.com', 'https://www.example.com'],
    'to non-www' => ['non-www', 'https://www.example.com', 'https://example.com'],
]);

test('permanence leaves every other traefik label untouched', function () {
    $temporary = redirectPermanentTraefikLabels('www', false);
    $permanent = redirectPermanentTraefikLabels('www', true);

    $onlyInTemporary = array_values(array_diff($temporary, $permanent));
    $onlyInPermanent = array_values(array_diff($permanent, $temporary));

    expect($onlyInTemporary)->toBe(['traefik.http.middlewares.0-testuuid-to-www.redirectregex.permanent=false'])
        ->and($onlyInPermanent)->toBe(['traefik.http.middlewares.0-testuuid-to-www.redirectregex.permanent=true']);
});

test('permanence leaves every other caddy label untouched', function () {
    $temporary = redirectPermanentCaddyLabels('www', false);
    $permanent = redirectPermanentCaddyLabels('www', true);

    expect(array_values(array_diff($temporary, $permanent)))
        ->toBe(['caddy_0.redir=https://www.example.com{uri}'])
        ->and(array_values(array_diff($permanent, $temporary)))
        ->toBe(['caddy_0.redir=https://www.example.com{uri} permanent']);
});

test('no redirect labels are emitted when the direction allows both hosts', function () {
    expect(collect(redirectPermanentTraefikLabels('both', true))->filter(
        fn (string $label) => str_contains($label, 'redirectregex')
    ))->toBeEmpty()
        ->and(collect(redirectPermanentCaddyLabels('both', true))->filter(
            fn (string $label) => str_contains($label, '.redir=')
        ))->toBeEmpty();
});

test('permanence does not disturb the escaped capture groups', function () {
    $labels = fqdnLabelsForTraefik(
        uuid: 'testuuid',
        domains: collect(['https://example.com']),
        redirect_direction: 'www',
        is_redirect_permanent: true,
    );

    expect($labels)
        ->toContain('traefik.http.middlewares.0-testuuid-to-www.redirectregex.replacement=$${1}://www.$${2}')
        ->toContain('traefik.http.middlewares.0-testuuid-to-www.redirectregex.permanent=true');
});
