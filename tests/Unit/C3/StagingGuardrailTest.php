<?php

/**
 * Connect3 fork: pure-function coverage for hostname rules and the Traefik guardrail
 * (PRD 4.2, 5.2, 5.7; acceptance criteria 2 to 5).
 */
const C3_TEST_APEX = 'sites.c3-staging.test';

function c3TestLabels(array $domains, array $extra = []): array
{
    return fqdnLabelsForTraefik(
        uuid: 'appuuid',
        domains: collect($domains),
        onlyPort: 3000,
        is_force_https_enabled: true,
        serviceLabels: $extra === [] ? null : collect($extra),
    )->values()->all();
}

function c3MiddlewaresOf(array $labels, string $router): array
{
    $chain = collect($labels)->first(fn ($l) => str_starts_with($l, "traefik.http.routers.{$router}.middlewares="));

    return $chain === null ? [] : explode(',', str($chain)->after('.middlewares=')->toString());
}

describe('slug and hostname rules', function () {
    test('slugs are normalised to DNS-safe lowercase', function () {
        expect(c3_normalizeSlug('Todd Plumbing'))->toBe('todd-plumbing')
            ->and(c3_normalizeSlug('  Rogers--HVAC  '))->toBe('rogers-hvac')
            ->and(c3_normalizeSlug(''))->toBeNull()
            ->and(c3_normalizeSlug(null))->toBeNull();
    });

    test('slug validity rejects the reserved double dash and bad edges', function () {
        expect(c3_isValidSlug('todd-plumbing'))->toBeTrue()
            ->and(c3_isValidSlug('a'))->toBeTrue()
            ->and(c3_isValidSlug('pr-1--todd'))->toBeFalse()
            ->and(c3_isValidSlug('-todd'))->toBeFalse()
            ->and(c3_isValidSlug('Todd'))->toBeFalse()
            ->and(c3_isValidSlug(str_repeat('a', 64)))->toBeFalse();
    });

    test('staging hosts are built and parsed both ways', function () {
        expect(c3_stagingHost('todd-plumbing', C3_TEST_APEX))->toBe('todd-plumbing.'.C3_TEST_APEX)
            ->and(c3_stagingHost('todd-plumbing', C3_TEST_APEX, 'pr-12'))->toBe('pr-12--todd-plumbing.'.C3_TEST_APEX)
            ->and(c3_slugFromStagingHost('todd-plumbing.'.C3_TEST_APEX, C3_TEST_APEX))->toBe('todd-plumbing')
            ->and(c3_slugFromStagingHost('pr-12--todd-plumbing.'.C3_TEST_APEX, C3_TEST_APEX))->toBe('todd-plumbing')
            ->and(c3_slugFromStagingHost('a.b.'.C3_TEST_APEX, C3_TEST_APEX))->toBeNull()
            ->and(c3_slugFromStagingHost(C3_TEST_APEX, C3_TEST_APEX))->toBeNull()
            ->and(c3_slugFromStagingHost('example.com', C3_TEST_APEX))->toBeNull();
    });

    test('apex normalisation strips scheme, path and dots', function () {
        expect(c3_normalizeApex('https://Sites.Example.com/'))->toBe('sites.example.com')
            ->and(c3_normalizeApex(' .sites.example.com. '))->toBe('sites.example.com')
            ->and(c3_normalizeApex(''))->toBeNull();
    });

    test('domain lists are parsed into unique bare hosts', function () {
        expect(c3_parseDomainList('https://Example.com/, www.example.com:443 example.com'))
            ->toBe(['example.com', 'www.example.com'])
            ->and(c3_parseDomainList(['not a host', 'ok.example.com']))->toBe(['ok.example.com'])
            ->and(c3_parseDomainList(null))->toBe([]);
    });

    test('generated passwords meet the 20 character minimum', function () {
        expect(strlen(c3_generatePassword()))->toBeGreaterThanOrEqual(20)
            ->and(strlen(c3_generatePassword(8)))->toBeGreaterThanOrEqual(20)
            ->and(c3_generatePassword())->not->toBe(c3_generatePassword());
    });
});

describe('staging label guardrail', function () {
    test('staging routers get the auth+noindex chain prepended and the staging resolver', function () {
        $labels = c3_enforceStagingLabels(c3TestLabels(['https://todd.'.C3_TEST_APEX]), C3_TEST_APEX, 'c3wildcard')->all();

        expect(c3MiddlewaresOf($labels, 'https-0-appuuid'))->toBe(['c3-todd-auth@file', 'c3-noindex@file', 'gzip'])
            ->and(c3MiddlewaresOf($labels, 'http-0-appuuid'))->toBe(['c3-todd-auth@file', 'c3-noindex@file', 'redirect-to-https'])
            ->and($labels)->toContain('traefik.http.routers.https-0-appuuid.tls.certresolver=c3wildcard')
            ->and($labels)->not->toContain('traefik.http.routers.https-0-appuuid.tls.certresolver=letsencrypt');
    });

    test('preview hostnames resolve to the project slug', function () {
        $labels = c3_enforceStagingLabels(c3TestLabels(['https://pr-7--todd.'.C3_TEST_APEX]), C3_TEST_APEX)->all();

        expect(c3MiddlewaresOf($labels, 'https-0-appuuid'))->toContain('c3-todd-auth@file');
    });

    test('live domains are left untouched', function () {
        $original = c3TestLabels(['https://example.com']);
        $labels = c3_enforceStagingLabels($original, C3_TEST_APEX, 'c3wildcard')->all();

        expect($labels)->toEqualCanonicalizing($original)
            ->and($labels)->toContain('traefik.http.routers.https-0-appuuid.tls.certresolver=letsencrypt');
    });

    test('mixed resources protect only the staging router', function () {
        $labels = c3_enforceStagingLabels(c3TestLabels(['https://example.com', 'https://todd.'.C3_TEST_APEX]), C3_TEST_APEX)->all();

        expect(c3MiddlewaresOf($labels, 'https-0-appuuid'))->toBe(['gzip'])
            ->and(c3MiddlewaresOf($labels, 'https-1-appuuid'))->toBe(['c3-todd-auth@file', 'c3-noindex@file', 'gzip']);
    });

    test('custom labels that removed every middleware still get the chain (acceptance 5)', function () {
        $custom = [
            'traefik.enable=true',
            'traefik.http.routers.https-0-appuuid.rule=Host(`todd.'.C3_TEST_APEX.'`) && PathPrefix(`/`)',
            'traefik.http.routers.https-0-appuuid.entryPoints=https',
            'traefik.http.routers.https-0-appuuid.tls=true',
            'traefik.http.routers.https-0-appuuid.service=https-0-appuuid',
            'traefik.http.services.https-0-appuuid.loadbalancer.server.port=3000',
        ];
        $labels = c3_enforceStagingLabels($custom, C3_TEST_APEX, 'c3wildcard')->all();

        expect($labels)->toContain('traefik.http.routers.https-0-appuuid.middlewares=c3-todd-auth@file,c3-noindex@file')
            ->and($labels)->toContain('traefik.http.routers.https-0-appuuid.tls.certresolver=c3wildcard')
            ->and(count($labels))->toBe(count($custom) + 2);
    });

    test('a chain already containing the guard is not duplicated', function () {
        $once = c3_enforceStagingLabels(c3TestLabels(['https://todd.'.C3_TEST_APEX]), C3_TEST_APEX)->all();
        $twice = c3_enforceStagingLabels($once, C3_TEST_APEX)->all();

        expect($twice)->toEqual($once);
    });

    test('routers without any Host() fail closed', function () {
        $labels = c3_enforceStagingLabels([
            'traefik.http.routers.catchall.rule=PathPrefix(`/`)',
            'traefik.http.routers.catchall.entryPoints=https',
        ], C3_TEST_APEX)->all();

        expect(c3MiddlewaresOf($labels, 'catchall'))->toBe(['c3-unknown-auth@file', 'c3-noindex@file']);
    });

    test('HostRegexp rules mentioning the apex fail closed, others pass', function () {
        $staging = c3_enforceStagingLabels(['traefik.http.routers.r.rule=HostRegexp(`^.+\.'.str_replace('.', '\.', C3_TEST_APEX).'$`)'], C3_TEST_APEX)->all();
        $live = c3_enforceStagingLabels(['traefik.http.routers.r.rule=HostRegexp(`^.+\.example\.com$`)'], C3_TEST_APEX)->all();

        expect(c3MiddlewaresOf($staging, 'r'))->not->toBeEmpty()
            ->and(c3MiddlewaresOf($live, 'r'))->toBeEmpty();
    });

    test('without an apex nothing changes', function () {
        $original = c3TestLabels(['https://todd.'.C3_TEST_APEX]);

        expect(c3_enforceStagingLabels($original, null)->all())->toEqual($original)
            ->and(c3_enforceStagingLabels($original, '')->all())->toEqual($original);
    });
});

describe('dynamic proxy configuration', function () {
    test('global config carries the noindex header, https redirect and robots override', function () {
        $config = c3_globalDynamicConfig(C3_TEST_APEX, 'c3wildcard', true);

        expect(data_get($config, 'http.middlewares.c3-noindex.headers.customResponseHeaders.X-Robots-Tag'))->toBe('noindex, nofollow, noarchive, nosnippet')
            ->and(data_get($config, 'http.middlewares.c3-redirect-https.redirectScheme.scheme'))->toBe('https')
            ->and(data_get($config, 'http.middlewares.c3-robots-path.replacePath.path'))->toBe('/c3/robots.txt')
            ->and(data_get($config, 'http.routers.c3-robots-https.rule'))->toBe('HostRegexp(`^[a-z0-9-]+\.sites\.c3-staging\.test$`) && Path(`/robots.txt`)')
            ->and(data_get($config, 'http.routers.c3-robots-https.priority'))->toBe(100000)
            ->and(data_get($config, 'http.routers.c3-robots-https.tls.certResolver'))->toBe('c3wildcard')
            ->and(data_get($config, 'http.routers.c3-robots-https.tls.domains.0.main'))->toBe(C3_TEST_APEX)
            ->and(data_get($config, 'http.routers.c3-robots-https.tls.domains.0.sans.0'))->toBe('*.'.C3_TEST_APEX)
            ->and(data_get($config, 'http.routers.c3-robots-http.entryPoints'))->toBe(['http'])
            ->and(data_get($config, 'http.services.c3-coolify.loadBalancer.servers.0.url'))->toBe('http://coolify:8080');
    });

    test('global config without a token has no wildcard domains', function () {
        $config = c3_globalDynamicConfig(C3_TEST_APEX);

        expect(data_get($config, 'http.routers.c3-robots-https.tls'))->toBe(['certResolver' => 'letsencrypt']);
    });

    test('staged project config has only the basic auth middleware', function () {
        $config = c3_projectDynamicConfig('todd', 'todd', '$2y$10$hash', 'staged', ['example.com'], 'https-0-appuuid');

        expect(data_get($config, 'http.middlewares.c3-todd-auth.basicAuth.users'))->toBe(['todd:$2y$10$hash'])
            ->and(data_get($config, 'http.routers'))->toBeNull();
    });

    test('live project config routes each client domain to the docker service', function () {
        $config = c3_projectDynamicConfig('todd', 'todd', 'hash', 'live', ['Example.com', 'https://www.example.com/'], 'https-0-appuuid');

        expect(data_get($config, 'http.routers.c3-todd-live-0.rule'))->toBe('Host(`example.com`)')
            ->and(data_get($config, 'http.routers.c3-todd-live-0.service'))->toBe('https-0-appuuid@docker')
            ->and(data_get($config, 'http.routers.c3-todd-live-0.tls.certResolver'))->toBe('letsencrypt')
            ->and(data_get($config, 'http.routers.c3-todd-live-0.middlewares'))->toBeNull()
            ->and(data_get($config, 'http.routers.c3-todd-live-0-http.middlewares'))->toBe(['c3-redirect-https'])
            ->and(data_get($config, 'http.routers.c3-todd-live-1.rule'))->toBe('Host(`www.example.com`)');
    });

    test('live state without a routable service emits no routers', function () {
        $config = c3_projectDynamicConfig('todd', 'todd', 'hash', 'live', ['example.com'], null);

        expect(data_get($config, 'http.routers'))->toBeNull();
    });

    test('proxy extras add the DNS-01 resolver only when enabled', function () {
        $base = ['services' => ['traefik' => ['command' => ['--ping=true']]]];

        expect(c3_applyProxyExtras($base, false))->toBe($base);

        $with = c3_applyProxyExtras($base, true);
        expect($with['services']['traefik']['command'])->toContain('--certificatesresolvers.c3wildcard.acme.dnschallenge.provider=cloudflare')
            ->and($with['services']['traefik']['command'])->toContain('--certificatesresolvers.c3wildcard.acme.storage=/traefik/acme-c3.json')
            ->and($with['services']['traefik']['environment'])->toBe(['CF_DNS_API_TOKEN_FILE=/traefik/c3_cloudflare_token']);
    });
});
