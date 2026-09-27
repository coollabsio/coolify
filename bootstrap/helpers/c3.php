<?php

/**
 * Connect3 fork helpers.
 *
 * Everything hostname- and Traefik-related for the Connect3 staging/live model lives here
 * (PRD sections 4.2, 5 and 7.2). The functions are pure wherever possible so the guardrail
 * can be unit tested without a database or a server.
 *
 * Naming conventions used across the fork:
 *   - Traefik middlewares:  c3-noindex, c3-redirect-https, c3-robots-path, c3-<slug>-auth
 *   - Traefik routers:      c3-robots-http(s), c3-<slug>-live-<n>(-http)
 *   - Dynamic config files: c3_global.yaml, c3_<slug>.yaml
 *   - ACME resolver:        c3wildcard (DNS-01 via Cloudflare) next to upstream's letsencrypt (HTTP-01)
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

const C3_NOINDEX_VALUE = 'noindex, nofollow, noarchive, nosnippet';

const C3_ROBOTS_PATH = '/c3/robots.txt';

const C3_ROBOTS_BODY = "User-agent: *\nDisallow: /\n";

const C3_WILDCARD_RESOLVER = 'c3wildcard';

const C3_DEFAULT_RESOLVER = 'letsencrypt';

const C3_PREVIEW_URL_TEMPLATE = 'pr-{{pr_id}}--{{domain}}';

const C3_CLOUDFLARE_TOKEN_FILE = 'c3_cloudflare_token';

const C3_STATE_STAGED = 'staged';

const C3_STATE_LIVE = 'live';

function c3_normalizeApex(?string $apex): ?string
{
    if ($apex === null) {
        return null;
    }
    $apex = str($apex)->trim()->lower();
    if ($apex->contains('://')) {
        $apex = $apex->after('://');
    }
    $apex = $apex->before('/')->trim('.')->toString();

    return $apex === '' ? null : $apex;
}

function c3_stagingApex(): ?string
{
    try {
        return c3_normalizeApex(data_get(instanceSettings(), 'c3_staging_apex'));
    } catch (Throwable) {
        return null;
    }
}

function c3_enabled(): bool
{
    return c3_stagingApex() !== null;
}

function c3_hasCloudflareToken(): bool
{
    try {
        return filled(data_get(instanceSettings(), 'c3_cloudflare_dns_token'));
    } catch (Throwable) {
        return false;
    }
}

/**
 * Resolver used for routers under the staging apex. DNS-01 wildcard when a Cloudflare
 * token is configured, upstream's HTTP-01 resolver otherwise.
 */
function c3_stagingCertResolver(): string
{
    return c3_hasCloudflareToken() ? C3_WILDCARD_RESOLVER : C3_DEFAULT_RESOLVER;
}

function c3_normalizeSlug(?string $slug): ?string
{
    if ($slug === null) {
        return null;
    }
    $slug = Str::slug(str($slug)->trim()->lower()->toString(), '-');
    $slug = preg_replace('/-{2,}/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');

    return $slug === '' ? null : $slug;
}

/**
 * DNS-safe, lowercase, no leading/trailing dash, and never containing the reserved
 * "--" separator used for preview/branch prefixes.
 */
function c3_isValidSlug(?string $slug): bool
{
    if ($slug === null || $slug === '') {
        return false;
    }
    if (str_contains($slug, '--')) {
        return false;
    }

    return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) === 1;
}

function c3_stagingHost(string $slug, string $apex, ?string $prefix = null): string
{
    $prefix = c3_normalizeSlug($prefix);

    return ($prefix ? "{$prefix}--" : '')."{$slug}.{$apex}";
}

function c3_isStagingHost(?string $host, ?string $apex): bool
{
    if (blank($host) || blank($apex)) {
        return false;
    }
    $host = strtolower(rtrim($host, '.'));
    $apex = strtolower($apex);

    return $host !== $apex && str_ends_with($host, '.'.$apex);
}

/**
 * "pr-12--todd-plumbing.staging.example" -> "todd-plumbing"
 */
function c3_slugFromStagingHost(string $host, string $apex): ?string
{
    if (! c3_isStagingHost($host, $apex)) {
        return null;
    }
    $label = str(strtolower(rtrim($host, '.')))->beforeLast('.'.strtolower($apex))->toString();
    if (str_contains($label, '.')) {
        // deeper than one level under the apex: not a Connect3 hostname
        return null;
    }
    if (str_contains($label, '--')) {
        $label = str($label)->afterLast('--')->toString();
    }

    return c3_isValidSlug($label) ? $label : null;
}

function c3_authMiddlewareName(string $slug): string
{
    return "c3-{$slug}-auth";
}

/**
 * The middleware chain every staging router must carry, outermost first so the
 * noindex header overrides anything the application sets.
 *
 * @return array<int, string>
 */
function c3_stagingMiddlewareChain(string $slug): array
{
    return [c3_authMiddlewareName($slug).'@file', 'c3-noindex@file'];
}

function c3_dynamicFileName(string $slug): string
{
    return "c3_{$slug}.yaml";
}

function c3_generatePassword(int $length = 24): string
{
    $length = max(20, $length);
    $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $password = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

function c3_htpasswdHash(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

/**
 * Extract the Host() literals of a Traefik rule.
 *
 * @return array<int, string>
 */
function c3_hostsFromRule(string $rule): array
{
    preg_match_all('/Host\(\s*`([^`]+)`\s*\)/i', $rule, $matches);

    return array_map('strtolower', $matches[1] ?? []);
}

/**
 * Decide whether a router rule targets the staging apex. Fails closed: a rule that has no
 * Host() literal at all (path-only, or HostRegexp mentioning the apex) is treated as staging,
 * because such a router would also answer for staging hostnames.
 */
function c3_ruleTargetsStaging(string $rule, string $apex): bool
{
    $hosts = c3_hostsFromRule($rule);
    if ($hosts === []) {
        if (preg_match('/HostRegexp\(/i', $rule) === 1) {
            $needle = strtolower($rule);

            return str_contains($needle, strtolower($apex)) || str_contains($needle, str_replace('.', '\\.', strtolower($apex)));
        }

        return true;
    }
    foreach ($hosts as $host) {
        if (c3_isStagingHost($host, $apex)) {
            return true;
        }
    }

    return false;
}

/**
 * Guardrail (PRD 5.7): given the final label set for a container, make sure every Traefik
 * router whose rule targets a staging hostname carries the staging middleware chain and, when
 * TLS is on, uses the staging certificate resolver. Works on generated *and* custom labels so a
 * misconfigured application cannot opt out.
 *
 * @param  Collection<int, string>|array<int, string>  $labels
 * @return Collection<int, string>
 */
function c3_enforceStagingLabels(Collection|array $labels, ?string $apex, string $certResolver = C3_DEFAULT_RESOLVER): Collection
{
    $labels = collect($labels)->values();
    $apex = c3_normalizeApex($apex);
    if ($apex === null) {
        return $labels;
    }

    // router name => slug (or null when the slug cannot be derived)
    $stagingRouters = [];
    foreach ($labels as $label) {
        if (! is_string($label)) {
            continue;
        }
        if (preg_match('/^traefik\.http\.routers\.([^.=]+)\.rule=(.*)$/is', $label, $m) !== 1) {
            continue;
        }
        [$_, $router, $rule] = $m;
        if (! c3_ruleTargetsStaging($rule, $apex)) {
            continue;
        }
        $slug = null;
        foreach (c3_hostsFromRule($rule) as $host) {
            $slug = c3_slugFromStagingHost($host, $apex);
            if ($slug !== null) {
                break;
            }
        }
        $stagingRouters[$router] = $slug;
    }

    if ($stagingRouters === []) {
        return $labels;
    }

    $seenMiddlewares = [];
    $seenResolver = [];
    $hasTls = [];

    $labels = $labels->map(function ($label) use ($stagingRouters, $certResolver, &$seenMiddlewares, &$seenResolver, &$hasTls) {
        if (! is_string($label)) {
            return $label;
        }
        if (preg_match('/^traefik\.http\.routers\.([^.=]+)\.(.+?)=(.*)$/is', $label, $m) !== 1) {
            return $label;
        }
        [$_, $router, $key, $value] = $m;
        if (! array_key_exists($router, $stagingRouters)) {
            return $label;
        }
        $lowerKey = strtolower($key);
        if ($lowerKey === 'middlewares') {
            $seenMiddlewares[$router] = true;
            $existing = collect(explode(',', $value))->map(fn ($v) => trim($v))->filter()->values();
            $chain = collect(c3_requiredChainFor($stagingRouters[$router]));
            $merged = $chain->merge($existing->reject(fn ($v) => $chain->contains($v)))->values();

            return "traefik.http.routers.{$router}.{$key}=".$merged->join(',');
        }
        if ($lowerKey === 'tls' || str_starts_with($lowerKey, 'tls.')) {
            $hasTls[$router] = true;
        }
        if ($lowerKey === 'tls.certresolver') {
            $seenResolver[$router] = true;

            return "traefik.http.routers.{$router}.{$key}={$certResolver}";
        }

        return $label;
    });

    $additions = collect();
    foreach ($stagingRouters as $router => $slug) {
        if (! isset($seenMiddlewares[$router])) {
            $additions->push("traefik.http.routers.{$router}.middlewares=".implode(',', c3_requiredChainFor($slug)));
        }
        if (isset($hasTls[$router]) && ! isset($seenResolver[$router])) {
            $additions->push("traefik.http.routers.{$router}.tls.certresolver={$certResolver}");
        }
    }

    return $labels->merge($additions)->values();
}

/**
 * When the slug is unknown we still attach a per-slug auth middleware name that does not
 * exist, which makes Traefik disable the router. Failing closed beats serving unprotected.
 *
 * @return array<int, string>
 */
function c3_requiredChainFor(?string $slug): array
{
    return c3_stagingMiddlewareChain($slug ?? 'unknown');
}

/**
 * Traefik file-provider config shared by every staging hostname: the noindex header, the
 * https redirect used by live routers, and the robots.txt override that routes to Coolify.
 *
 * @return array<string, mixed>
 */
function c3_globalDynamicConfig(string $apex, string $certResolver = C3_DEFAULT_RESOLVER, bool $wildcardCert = false): array
{
    $apex = c3_normalizeApex($apex);
    $regex = '^[a-z0-9-]+\.'.str_replace('.', '\.', $apex).'$';
    $robotsRule = "HostRegexp(`{$regex}`) && Path(`/robots.txt`)";

    $tls = ['certResolver' => $certResolver];
    if ($wildcardCert) {
        $tls['domains'] = [[
            'main' => $apex,
            'sans' => ["*.{$apex}"],
        ]];
    }

    return [
        'http' => [
            'middlewares' => [
                'c3-noindex' => [
                    'headers' => [
                        'customResponseHeaders' => [
                            'X-Robots-Tag' => C3_NOINDEX_VALUE,
                        ],
                    ],
                ],
                'c3-redirect-https' => [
                    'redirectScheme' => [
                        'scheme' => 'https',
                        'permanent' => true,
                    ],
                ],
                'c3-robots-path' => [
                    'replacePath' => [
                        'path' => C3_ROBOTS_PATH,
                    ],
                ],
            ],
            'routers' => [
                'c3-robots-https' => [
                    'rule' => $robotsRule,
                    'priority' => 100000,
                    'entryPoints' => ['https'],
                    'service' => 'c3-coolify',
                    'middlewares' => ['c3-robots-path', 'c3-noindex'],
                    'tls' => $tls,
                ],
                'c3-robots-http' => [
                    'rule' => $robotsRule,
                    'priority' => 100000,
                    'entryPoints' => ['http'],
                    'service' => 'c3-coolify',
                    'middlewares' => ['c3-robots-path', 'c3-noindex'],
                ],
            ],
            'services' => [
                'c3-coolify' => [
                    'loadBalancer' => [
                        'servers' => [
                            ['url' => 'http://coolify:8080'],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Traefik file-provider config for one client project: its basic-auth middleware and, when
 * the site is live, the routers for its client domains. Live routers point at the docker
 * provider service of the primary application, so promotion never touches the container.
 *
 * @param  array<int, string>  $liveDomains  bare hostnames
 * @return array<string, mixed>
 */
function c3_projectDynamicConfig(
    string $slug,
    string $username,
    string $passwordHash,
    string $siteState,
    array $liveDomains,
    ?string $dockerServiceName,
    string $liveCertResolver = C3_DEFAULT_RESOLVER,
): array {
    $config = [
        'http' => [
            'middlewares' => [
                c3_authMiddlewareName($slug) => [
                    'basicAuth' => [
                        'users' => ["{$username}:{$passwordHash}"],
                        'realm' => 'Connect3 staging',
                    ],
                ],
            ],
        ],
    ];

    $liveDomains = collect($liveDomains)->map(fn ($d) => c3_normalizeHost($d))->filter()->unique()->values();
    if ($siteState === C3_STATE_LIVE && $dockerServiceName && $liveDomains->isNotEmpty()) {
        $routers = [];
        foreach ($liveDomains as $i => $host) {
            $routers["c3-{$slug}-live-{$i}"] = [
                'rule' => "Host(`{$host}`)",
                'entryPoints' => ['https'],
                'service' => "{$dockerServiceName}@docker",
                'tls' => ['certResolver' => $liveCertResolver],
            ];
            $routers["c3-{$slug}-live-{$i}-http"] = [
                'rule' => "Host(`{$host}`)",
                'entryPoints' => ['http'],
                'service' => "{$dockerServiceName}@docker",
                'middlewares' => ['c3-redirect-https'],
            ];
        }
        $config['http']['routers'] = $routers;
    }

    return $config;
}

/**
 * Accepts "https://Example.com/", "example.com:443", "example.com" and returns "example.com".
 */
function c3_normalizeHost(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = str($value)->trim()->lower();
    if ($value->contains('://')) {
        $value = $value->after('://');
    }
    $value = $value->before('/')->before(':')->trim('.')->toString();
    if ($value === '' || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value) !== 1) {
        return null;
    }

    return $value;
}

/**
 * Parse an operator-supplied list ("a.com, https://www.a.com") into unique bare hosts.
 *
 * @return array<int, string>
 */
function c3_parseDomainList(string|array|null $domains): array
{
    if ($domains === null) {
        return [];
    }
    $items = is_array($domains) ? $domains : preg_split('/[\s,]+/', $domains);

    return collect($items)->map(fn ($d) => c3_normalizeHost(is_string($d) ? $d : null))->filter()->unique()->values()->all();
}

/**
 * Add the DNS-01 wildcard resolver to the generated Traefik proxy compose config. The token
 * itself is never written into the compose file: Traefik reads it from a root-only file that
 * SyncGlobalProxyConfig maintains next to acme.json.
 *
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function c3_applyProxyExtras(array $config, bool $wildcardEnabled): array
{
    if (! $wildcardEnabled || ! isset($config['services']['traefik'])) {
        return $config;
    }
    $resolver = C3_WILDCARD_RESOLVER;
    $config['services']['traefik']['command'][] = "--certificatesresolvers.{$resolver}.acme.dnschallenge=true";
    $config['services']['traefik']['command'][] = "--certificatesresolvers.{$resolver}.acme.dnschallenge.provider=cloudflare";
    $config['services']['traefik']['command'][] = "--certificatesresolvers.{$resolver}.acme.dnschallenge.resolvers=1.1.1.1:53,1.0.0.1:53";
    $config['services']['traefik']['command'][] = "--certificatesresolvers.{$resolver}.acme.storage=/traefik/acme-c3.json";
    $config['services']['traefik']['environment'][] = 'CF_DNS_API_TOKEN_FILE=/traefik/'.C3_CLOUDFLARE_TOKEN_FILE;

    return $config;
}
