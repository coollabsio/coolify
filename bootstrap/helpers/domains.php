<?php

use App\Models\Application;
use App\Models\ServiceApplication;
use Illuminate\Support\Collection;

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

    // Check applications within the same team
    $applications = Application::ownedByCurrentTeamAPI($teamId)->get(['fqdn', 'uuid', 'name', 'id']);
    $serviceApplications = ServiceApplication::ownedByCurrentTeamAPI($teamId)->with('service:id,name')->get(['fqdn', 'uuid', 'id', 'service_id']);

    if ($uuid) {
        $applications = $applications->filter(fn ($app) => $app->uuid !== $uuid);
        $serviceApplications = $serviceApplications->filter(fn ($app) => $app->uuid !== $uuid);
    }

    foreach ($applications as $app) {
        if (is_null($app->fqdn)) {
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
                    'resource_name' => $app->name,
                    'resource_uuid' => $app->uuid,
                    'resource_type' => 'application',
                    'message' => "Domain $naked_domain is already in use by application '{$app->name}'",
                ];
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
