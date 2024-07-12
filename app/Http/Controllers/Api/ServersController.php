<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server as ModelsServer;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Stringable;

class ServersController extends Controller
{
    private function removeSensitiveDataFromSettings($settings)
    {
        $token = auth()->user()->currentAccessToken();
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($settings);
        }
        $settings = $settings->makeHidden([
            'metrics_token',
        ]);

        return serializeApiResponse($settings);
    }

    private function removeSensitiveData($server)
    {
        $token = auth()->user()->currentAccessToken();
        $server->makeHidden([
            'id',
        ]);
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($server);
        }

        return serializeApiResponse($server);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all servers.',
        path: '/servers',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all servers.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Server')
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function servers(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $servers = ModelsServer::whereTeamId($teamId)->select('id', 'name', 'uuid', 'ip', 'user', 'port')->get()->load(['settings'])->map(function ($server) {
            $server['is_reachable'] = $server->settings->is_reachable;
            $server['is_usable'] = $server->settings->is_usable;

            return $server;
        });
        $servers = $servers->map(function ($server) {
            $settings = $this->removeSensitiveDataFromSettings($server->settings);
            $server = $this->removeSensitiveData($server);
            data_set($server, 'settings', $settings);

            return $server;
        });

        return response()->json($servers);
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get server by UUID.',
        path: '/servers/{uuid}',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server\'s Uuid', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get server by UUID',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/Server'
                        )
                    ),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function server_by_uuid(Request $request)
    {
        $with_resources = $request->query('resources');
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
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

        $settings = $this->removeSensitiveDataFromSettings($server->settings);
        $server = $this->removeSensitiveData($server);
        data_set($server, 'settings', $settings);

        return response()->json(serializeApiResponse($server));
    }

    #[OA\Get(
        summary: 'Resources',
        description: 'Get resources by server.',
        path: '/servers/{uuid}/resources',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server\'s Uuid', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get resources by server',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'id' => ['type' => 'integer'],
                                    'uuid' => ['type' => 'string'],
                                    'name' => ['type' => 'string'],
                                    'type' => ['type' => 'string'],
                                    'created_at' => ['type' => 'string'],
                                    'updated_at' => ['type' => 'string'],
                                    'status' => ['type' => 'string'],
                                ]
                            )
                        )),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function resources_by_server(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $server = ModelsServer::whereTeamId($teamId)->whereUuid(request()->uuid)->first();
        if (is_null($server)) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
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
        $server = $this->removeSensitiveData($server);
        ray($server);

        return response()->json(serializeApiResponse(data_get($server, 'resources')));
    }

    #[OA\Get(
        summary: 'Domains',
        description: 'Get domains by server.',
        path: '/servers/{uuid}/domains',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server\'s Uuid', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get domains by server',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    'ip' => ['type' => 'string'],
                                    'domains' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ]
                            )
                        )),
                ]),
            new OA\Response(
                response: 401,
                ref: '#/components/responses/401',
            ),
            new OA\Response(
                response: 400,
                ref: '#/components/responses/400',
            ),
        ]
    )]
    public function domains_by_server(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->get('uuid');
        if ($uuid) {
            $domains = Application::getDomainsByUuid($uuid);

            return response()->json(serializeApiResponse($domains));
        }
        $projects = Project::where('team_id', $teamId)->get();
        $domains = collect();
        $applications = $projects->pluck('applications')->flatten();
        $settings = \App\Models\InstanceSettings::get();
        if ($applications->count() > 0) {
            foreach ($applications as $application) {
                $ip = $application->destination->server->ip;
                $fqdn = str($application->fqdn)->explode(',')->map(function ($fqdn) {
                    $f = str($fqdn)->replace('http://', '')->replace('https://', '')->explode('/');

                    return str(str($f[0])->explode(':')[0]);
                })->filter(function (Stringable $fqdn) {
                    return $fqdn->isNotEmpty();
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
                            $f = str($fqdn)->replace('http://', '')->replace('https://', '')->explode('/');

                            return str(str($f[0])->explode(':')[0]);
                        })->filter(function (Stringable $fqdn) {
                            return $fqdn->isNotEmpty();
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

        return response()->json(serializeApiResponse($domains));
    }
}
