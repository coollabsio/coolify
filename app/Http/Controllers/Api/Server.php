<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server as ModelsServer;
use Illuminate\Http\Request;

class Server extends Controller
{
    public function servers(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $servers = ModelsServer::whereTeamId($teamId)->select('id', 'name', 'uuid', 'ip', 'user', 'port')->get()->load(['settings'])->map(function ($server) {
            $server['is_reachable'] = $server->settings->is_reachable;
            $server['is_usable'] = $server->settings->is_usable;

            return $server;
        });

        return response()->json($servers);
    }

    public function server_by_uuid(Request $request)
    {
        $with_resources = $request->query('resources');
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $server = ModelsServer::whereTeamId($teamId)->whereUuid(request()->uuid)->first();
        if (is_null($server)) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        if ($with_resources) {
            $server['resources'] = $server->definedResources()->map(function ($resource) {
                $payload = [
                    'id' => $resource->id,
                    'uuid' => $resource->uuid,
                    'name' => $resource->name,
                    'type' => $resource->type(),
                    'created_at' => $resource->created_at,
                    'updated_at' => $resource->updated_at,
                ];
                if ($resource->type() === 'service') {
                    $payload['status'] = $resource->status();
                } else {
                    $payload['status'] = $resource->status;
                }

                return $payload;
            });
        } else {
            $server->load(['settings']);
        }

        return response()->json($server);
    }

    public function get_domains_by_server(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->query->get('uuid');
        if ($uuid) {
            $domains = Application::getDomainsByUuid($uuid);

            return response()->json([
                'uuid' => $uuid,
                'domains' => $domains,
            ]);
        }
        $projects = Project::where('team_id', $teamId)->get();
        $domains = collect();
        $applications = $projects->pluck('applications')->flatten();
        $settings = InstanceSettings::get();
        if ($applications->count() > 0) {
            foreach ($applications as $application) {
                $ip = $application->destination->server->ip;
                $fqdn = str($application->fqdn)->explode(',')->map(function ($fqdn) {
                    return str($fqdn)->replace('http://', '')->replace('https://', '')->replace('/', '');
                });
                if ($ip === 'host.docker.internal') {
                    if ($settings->public_ipv4) {
                        $domains->push([
                            'domain' => $fqdn,
                            'ip' => $settings->public_ipv4,
                        ]);
                    }
                    if ($settings->public_ipv6) {
                        $domains->push([
                            'domain' => $fqdn,
                            'ip' => $settings->public_ipv6,
                        ]);
                    }
                    if (! $settings->public_ipv4 && ! $settings->public_ipv6) {
                        $domains->push([
                            'domain' => $fqdn,
                            'ip' => $ip,
                        ]);
                    }
                } else {
                    $domains->push([
                        'domain' => $fqdn,
                        'ip' => $ip,
                    ]);
                }
            }
        }
        $services = $projects->pluck('services')->flatten();
        if ($services->count() > 0) {
            foreach ($services as $service) {
                $service_applications = $service->applications;
                if ($service_applications->count() > 0) {
                    foreach ($service_applications as $application) {
                        $fqdn = str($application->fqdn)->explode(',')->map(function ($fqdn) {
                            return str($fqdn)->replace('http://', '')->replace('https://', '')->replace('/', '');
                        });
                        if ($ip === 'host.docker.internal') {
                            if ($settings->public_ipv4) {
                                $domains->push([
                                    'domain' => $fqdn,
                                    'ip' => $settings->public_ipv4,
                                ]);
                            }
                            if ($settings->public_ipv6) {
                                $domains->push([
                                    'domain' => $fqdn,
                                    'ip' => $settings->public_ipv6,
                                ]);
                            }
                            if (! $settings->public_ipv4 && ! $settings->public_ipv6) {
                                $domains->push([
                                    'domain' => $fqdn,
                                    'ip' => $ip,
                                ]);
                            }
                        } else {
                            $domains->push([
                                'domain' => $fqdn,
                                'ip' => $ip,
                            ]);
                        }
                    }
                }
            }
        }
        $domains = $domains->groupBy('ip')->map(function ($domain) {
            return $domain->pluck('domain')->flatten();
        })->map(function ($domain, $ip) {
            return [
                'ip' => $ip,
                'domains' => $domain,
            ];
        })->values();

        return response()->json($domains);
    }
}
