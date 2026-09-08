<?php

use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Support\Collection;

/**
 * Count domains in a comma-separated FQDN string (e.g. "https://a.com,https://b.com").
 */
function countDomains(?string $fqdn): int
{
    if (! filled($fqdn)) {
        return 0;
    }

    return collect(explode(',', $fqdn))
        ->map(fn ($d) => trim($d))
        ->filter()
        ->count();
}

function isValidDomainUrl(string $url): bool
{
    $components = parse_url($url);

    if ($components === false) {
        return false;
    }

    $scheme = $components['scheme'] ?? '';
    $host = $components['host'] ?? '';

    if (! in_array(strtolower($scheme), ['http', 'https'], true) || $host === '') {
        return false;
    }

    $urlToValidate = $scheme.'://';

    if (isset($components['user'])) {
        $urlToValidate .= $components['user'];

        if (isset($components['pass'])) {
            $urlToValidate .= ':'.$components['pass'];
        }

        $urlToValidate .= '@';
    }

    $urlToValidate .= str_replace('_', '-', $host);

    if (isset($components['port'])) {
        $urlToValidate .= ':'.$components['port'];
    }

    if (isset($components['path'])) {
        $urlToValidate .= $components['path'];
    }

    if (isset($components['query'])) {
        $urlToValidate .= '?'.$components['query'];
    }

    if (isset($components['fragment'])) {
        $urlToValidate .= '#'.$components['fragment'];
    }

    return filter_var($urlToValidate, FILTER_VALIDATE_URL) !== false;
}

function checkDomainUsage(ServiceApplication|Application|null $resource = null, ?string $domain = null)
{
    $conflicts = [];

    // Get the current team for filtering
    $currentTeam = null;
    if ($resource) {
        $currentTeam = $resource->team();
    }

    if ($resource) {
        if ($resource->getMorphClass() === Application::class && $resource->build_pack === 'dockercompose') {
            $domains = data_get(json_decode($resource->docker_compose_domains, true), '*.domain');
            $domains = collect($domains);
        } else {
            $domains = collect($resource->fqdns);
        }
    } elseif ($domain) {
        $domains = collect([$domain]);
    } else {
        return ['conflicts' => [], 'hasConflicts' => false];
    }

    $domains = $domains->map(function ($domain) {
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }

        return str($domain);
    });

    // Filter applications by team if we have a current team
    $appsQuery = Application::query();
    if ($currentTeam) {
        $appsQuery = $appsQuery->whereHas('environment.project', function ($query) use ($currentTeam) {
            $query->where('team_id', $currentTeam->id);
        });
    }
    $apps = $appsQuery->get();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        $conflicts[] = [
                            'domain' => $naked_domain,
                            'resource_name' => $app->name,
                            'resource_link' => $app->link(),
                            'resource_type' => 'application',
                            'message' => "Domain $naked_domain is already in use by application '{$app->name}'",
                        ];
                    }
                } elseif ($domain) {
                    $conflicts[] = [
                        'domain' => $naked_domain,
                        'resource_name' => $app->name,
                        'resource_link' => $app->link(),
                        'resource_type' => 'application',
                        'message' => "Domain $naked_domain is already in use by application '{$app->name}'",
                    ];
                }
            }
        }
    }

    // Filter service applications by team if we have a current team
    $serviceAppsQuery = ServiceApplication::query();
    if ($currentTeam) {
        $serviceAppsQuery = $serviceAppsQuery->whereHas('service.environment.project', function ($query) use ($currentTeam) {
            $query->where('team_id', $currentTeam->id);
        });
    }
    $apps = $serviceAppsQuery->get();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        $conflicts[] = [
                            'domain' => $naked_domain,
                            'resource_name' => $app->service->name,
                            'resource_link' => $app->service->link(),
                            'resource_type' => 'service',
                            'message' => "Domain $naked_domain is already in use by service '{$app->service->name}'",
                        ];
                    }
                } elseif ($domain) {
                    $conflicts[] = [
                        'domain' => $naked_domain,
                        'resource_name' => $app->service->name,
                        'resource_link' => $app->service->link(),
                        'resource_type' => 'service',
                        'message' => "Domain $naked_domain is already in use by service '{$app->service->name}'",
                    ];
                }
            }
        }
    }

    if ($resource) {
        $settings = instanceSettings();
        if (data_get($settings, 'fqdn')) {
            $domain = data_get($settings, 'fqdn');
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                $conflicts[] = [
                    'domain' => $naked_domain,
                    'resource_name' => 'Coolify Instance',
                    'resource_link' => '#',
                    'resource_type' => 'instance',
                    'message' => "Domain $naked_domain is already in use by this Coolify instance",
                ];
            }
        }
    }

    return [
        'conflicts' => $conflicts,
        'hasConflicts' => count($conflicts) > 0,
    ];
}

function checkIfDomainIsAlreadyUsedViaAPI(Collection|array $domains, ?string $teamId = null, ?string $uuid = null)
{
    $conflicts = [];

    if (is_null($teamId)) {
        return ['error' => 'Team ID is required.'];
    }
    if (is_array($domains)) {
        $domains = collect($domains);
    }

    $domains = $domains->map(function ($domain) {
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }

        return str($domain);
    });

    $applications = Application::ownedByCurrentTeamAPI($teamId)->get(['fqdn', 'uuid', 'name', 'id', 'docker_compose_domains', 'build_pack']);
    $serviceApplications = ServiceApplication::ownedByCurrentTeamAPI($teamId)->with('service:id,name')->get(['fqdn', 'uuid', 'id', 'service_id']);

    if ($uuid) {
        $applications = $applications->filter(fn ($app) => $app->uuid !== $uuid);
        $serviceApplications = $serviceApplications->filter(fn ($app) => $app->uuid !== $uuid);
    }

    foreach ($applications as $app) {
        if (! is_null($app->fqdn)) {
            $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
            foreach ($list_of_domains as $domain) {
                if (str($domain)->endsWith('/')) {
                    $domain = str($domain)->beforeLast('/');
                }
                $naked_domain = str($domain)->value();
                if ($domains->contains($naked_domain)) {
                    $conflicts[] = [
                        'domain' => $naked_domain,
                        'resource_name' => $app->name,
                        'resource_uuid' => $app->uuid,
                        'resource_type' => 'application',
                        'message' => "Domain $naked_domain is already in use by application '{$app->name}'",
                    ];
                }
            }
        }

        if ($app->build_pack === 'dockercompose' && ! empty($app->docker_compose_domains)) {
            $dockerComposeDomains = json_decode($app->docker_compose_domains, true);
            if (is_array($dockerComposeDomains)) {
                foreach ($dockerComposeDomains as $serviceName => $domainConfig) {
                    $domainValue = data_get($domainConfig, 'domain');
                    if (empty($domainValue)) {
                        continue;
                    }
                    $list_of_domains = collect(explode(',', $domainValue))->filter(fn ($fqdn) => $fqdn !== '');
                    foreach ($list_of_domains as $domain) {
                        if (str($domain)->endsWith('/')) {
                            $domain = str($domain)->beforeLast('/');
                        }
                        $naked_domain = str($domain)->value();
                        if ($domains->contains($naked_domain)) {
                            $conflicts[] = [
                                'domain' => $naked_domain,
                                'resource_name' => $app->name,
                                'resource_uuid' => $app->uuid,
                                'resource_type' => 'application',
                                'service_name' => $serviceName,
                                'message' => "Domain $naked_domain is already in use by application '{$app->name}' (service: {$serviceName})",
                            ];
                        }
                    }
                }
            }
        }
    }

    foreach ($serviceApplications as $app) {
        if (str($app->fqdn)->isEmpty()) {
            continue;
        }
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                $conflicts[] = [
                    'domain' => $naked_domain,
                    'resource_name' => $app->service->name ?? 'Unknown Service',
                    'resource_uuid' => $app->uuid,
                    'resource_type' => 'service',
                    'message' => "Domain $naked_domain is already in use by service '{$app->service->name}'",
                ];
            }
        }
    }

    // Check instance-level domain
    $settings = instanceSettings();
    if (data_get($settings, 'fqdn')) {
        $domain = data_get($settings, 'fqdn');
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }
        $naked_domain = str($domain)->value();
        if ($domains->contains($naked_domain)) {
            $conflicts[] = [
                'domain' => $naked_domain,
                'resource_name' => 'Coolify Instance',
                'resource_uuid' => null,
                'resource_type' => 'instance',
                'message' => "Domain $naked_domain is already in use by this Coolify instance",
            ];
        }
    }

    return [
        'conflicts' => $conflicts,
        'hasConflicts' => count($conflicts) > 0,
    ];
}

/**
 * Normalize a compose service name the way Coolify historically did for env var keys
 * (hyphens and dots → underscores). Used for comparison and SERVICE_* env names only —
 * docker_compose_domains keys should use the original compose service name.
 */
function normalizeComposeServiceName(string $serviceName): string
{
    return str($serviceName)->replace('-', '_')->replace('.', '_')->toString();
}

/**
 * Resolve a candidate key (original or legacy-normalized) to the original compose service name.
 *
 * @param  iterable<int|string, mixed>  $serviceNames
 */
function findComposeServiceName(string $candidate, iterable $serviceNames): ?string
{
    $names = collect($serviceNames)->map(fn ($name) => (string) $name)->values();

    if ($names->containsStrict($candidate)) {
        return $candidate;
    }

    $normalized = normalizeComposeServiceName($candidate);
    $matches = $names->filter(
        fn ($name) => normalizeComposeServiceName($name) === $normalized
    )->values();

    return $matches->count() === 1 ? $matches->first() : null;
}

/**
 * Collapse domain-map keys that only differ by hyphen/dot/underscore into one preferred name.
 * Prefers a key that still has `-` or `.` over a fully underscore-normalized twin.
 *
 * @param  iterable<int|string, mixed>  $domainKeys
 * @return list<string>
 */
function preferredComposeServiceNamesFromDomainKeys(iterable $domainKeys): array
{
    $groups = [];
    foreach ($domainKeys as $key) {
        $key = (string) $key;
        $groups[normalizeComposeServiceName($key)][] = $key;
    }

    $preferred = [];
    foreach ($groups as $normalized => $keys) {
        $keys = array_values(array_unique($keys));
        $withSeparators = array_values(array_filter(
            $keys,
            fn (string $key) => $key !== $normalized
        ));
        $preferred[] = $withSeparators[0] ?? $keys[0];
    }

    return $preferred;
}

/**
 * Read domain string for a compose service from docker_compose_domains.
 * Prefers a filled domain on the requested key; falls back to any filled twin
 * (legacy underscore keys). Blank entries do not shadow filled twins.
 * Uses collection key access (not dotted data_get) so names like "api.test" work.
 *
 * @param  array<string, mixed>|Collection<string, mixed>  $domains
 */
function getComposeServiceDomainString(array|Collection $domains, string $serviceName): ?string
{
    $domains = collect($domains);
    $normalized = normalizeComposeServiceName($serviceName);
    $matches = [];

    foreach ($domains as $key => $entry) {
        $key = (string) $key;
        if ($key !== $serviceName
            && $key !== $normalized
            && normalizeComposeServiceName($key) !== $normalized) {
            continue;
        }

        $matches[] = [
            'key' => $key,
            'domain' => composeDomainEntryString($entry),
            'is_requested' => $key === $serviceName,
        ];
    }

    if ($matches === []) {
        return null;
    }

    $filled = array_values(array_filter(
        $matches,
        fn (array $match) => filled($match['domain'])
    ));

    if ($filled !== []) {
        foreach ($filled as $match) {
            if ($match['is_requested']) {
                return $match['domain'];
            }
        }

        return $filled[0]['domain'];
    }

    foreach ($matches as $match) {
        if ($match['is_requested']) {
            return $match['domain'];
        }
    }

    return $matches[0]['domain'];
}

/**
 * Determine whether a compose service already has a domain-map entry, including
 * an explicitly empty entry left when a user removes its generated domain.
 *
 * @param  array<string, mixed>|Collection<string, mixed>  $domains
 */
function hasComposeServiceDomainEntry(array|Collection $domains, string $serviceName): bool
{
    $normalized = normalizeComposeServiceName($serviceName);

    foreach (collect($domains)->keys() as $key) {
        $key = (string) $key;
        if ($key === $serviceName || normalizeComposeServiceName($key) === $normalized) {
            return true;
        }
    }

    return false;
}

function composeDomainEntryString(mixed $entry): ?string
{
    if (is_object($entry)) {
        $entry = (array) $entry;
    }

    if (! is_array($entry)) {
        return is_string($entry) ? $entry : null;
    }

    $domain = $entry['domain'] ?? null;

    return is_string($domain) ? $domain : null;
}

/**
 * Choose which domain string wins when merging twin compose domain keys.
 * Filled values always beat blanks; when both are filled, prefer the canonical key.
 */
function preferComposeDomainValue(
    mixed $existingDomain,
    bool $existingIsCanonical,
    mixed $incomingDomain,
    bool $incomingIsCanonical,
): mixed {
    $existingFilled = filled($existingDomain);
    $incomingFilled = filled($incomingDomain);

    if ($existingFilled && ! $incomingFilled) {
        return $existingDomain;
    }

    if ($incomingFilled && ! $existingFilled) {
        return $incomingDomain;
    }

    if ($existingFilled && $incomingFilled) {
        if ($incomingIsCanonical && ! $existingIsCanonical) {
            return $incomingDomain;
        }

        return $existingDomain;
    }

    // Both blank: keep canonical slot when possible.
    if ($incomingIsCanonical) {
        return $incomingDomain;
    }

    return $existingDomain;
}

/**
 * Rekey docker_compose_domains to original compose service names.
 * Merges legacy underscore/dot twin keys onto the canonical compose name.
 * Never lets an empty canonical key wipe a filled legacy twin.
 *
 * @param  array<string, mixed>|Collection<string, mixed>  $domains
 * @param  iterable<int|string, mixed>  $serviceNames
 * @return array<string, mixed>
 */
function rekeyComposeDomainsToServiceNames(array|Collection $domains, iterable $serviceNames): array
{
    $domains = collect($domains);
    $serviceNames = collect($serviceNames)->map(fn ($name) => (string) $name)->values();
    $rekeyed = [];
    $canonicalDomainSources = [];

    foreach ($domains as $key => $value) {
        $key = (string) $key;
        $original = findComposeServiceName($key, $serviceNames) ?? $key;
        $isCanonical = $key === $original;

        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            $value = ['domain' => $value];
        }

        if (! isset($rekeyed[$original])) {
            $rekeyed[$original] = $value;
            $canonicalDomainSources[$original] = $isCanonical;

            continue;
        }

        $existingDomain = $rekeyed[$original]['domain'] ?? null;
        $incomingDomain = $value['domain'] ?? null;
        $rekeyed[$original] = array_merge($rekeyed[$original], $value);
        $rekeyed[$original]['domain'] = preferComposeDomainValue(
            $existingDomain,
            $canonicalDomainSources[$original],
            $incomingDomain,
            $isCanonical,
        );
        $canonicalDomainSources[$original] = $canonicalDomainSources[$original] || $isCanonical;
    }

    return $rekeyed;
}

/**
 * Resolve a SERVICE_FQDN_ / SERVICE_URL_ env fragment to a ServiceApplication by compose name.
 * Handles legacy underscore fragments and dotted compose names (api.test vs API_TEST).
 */
function findServiceApplicationForEnvName(Service $resource, string $envServiceName): ?ServiceApplication
{
    $names = $resource->applications()->pluck('name');
    $resolved = findComposeServiceName($envServiceName, $names)
        ?? findComposeServiceName(str($envServiceName)->replace('_', '-')->toString(), $names);

    if ($resolved === null) {
        return null;
    }

    return $resource->applications()->where('name', $resolved)->first();
}

/**
 * Put/update a service domain entry under the original compose service name,
 * removing legacy twin keys that resolve unambiguously to the same name.
 *
 * @param  array<string, mixed>|Collection<string, mixed>  $domains
 * @param  iterable<int|string, mixed>  $serviceNames
 * @return array<string, mixed>
 */
function putComposeServiceDomain(
    array|Collection $domains,
    string $serviceName,
    ?string $domainString,
    iterable $serviceNames = [],
): array {
    $domains = collect($domains)->all();
    $names = collect($serviceNames)->map(fn ($name) => (string) $name);
    $storageKey = findComposeServiceName($serviceName, $names) ?? $serviceName;

    $merged = ['domain' => $domainString];
    foreach (array_keys($domains) as $key) {
        $key = (string) $key;
        if ($key !== $storageKey && findComposeServiceName($key, $names) !== $storageKey) {
            continue;
        }

        $existing = $domains[$key];
        if (is_object($existing)) {
            $existing = (array) $existing;
        }
        if (is_array($existing)) {
            $merged = array_merge($existing, $merged);
        }

        if ($key !== $storageKey) {
            unset($domains[$key]);
        }
    }

    $domains[$storageKey] = $merged;

    return $domains;
}
