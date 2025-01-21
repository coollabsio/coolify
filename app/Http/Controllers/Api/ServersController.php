<?php

namespace App\Http\Controllers\Api;

use App\Actions\Server\DeleteServer;
use App\Actions\Server\ValidateServer;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server as ModelsServer;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Stringable;

class ServersController extends Controller
{
    private function removeSensitiveDataFromSettings($settings)
    {
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $settings = $settings->makeHidden([
                'sentinel_token',
            ]);
        }

        return serializeApiResponse($settings);
    }

    private function removeSensitiveData($server)
    {
        $server->makeHidden([
            'id',
        ]);
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            // Do nothing
        }

        return serializeApiResponse($server);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all servers.',
        path: '/servers',
        operationId: 'list-servers',
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
        $servers = ModelsServer::whereTeamId($teamId)->select('id', 'name', 'uuid', 'ip', 'user', 'port', 'description')->get()->load(['settings'])->map(function ($server) {
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
        operationId: 'get-server-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server\'s UUID', schema: new OA\Schema(type: 'string')),
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
                $payload['status'] = $resource->status;

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
        operationId: 'get-resources-by-server-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server\'s UUID', schema: new OA\Schema(type: 'string')),
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
            $payload['status'] = $resource->status;

            return $payload;
        });
        $server = $this->removeSensitiveData($server);

        return response()->json(serializeApiResponse(data_get($server, 'resources')));
    }

    #[OA\Get(
        summary: 'Domains',
        description: 'Get domains by server.',
        path: '/servers/{uuid}/domains',
        operationId: 'get-domains-by-server-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server\'s UUID', schema: new OA\Schema(type: 'string')),
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
        $settings = instanceSettings();
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

    #[OA\Post(
        summary: 'Create',
        description: 'Create Server.',
        path: '/servers',
        operationId: 'create-server',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Server created.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'example' => 'My Server', 'description' => 'The name of the server.'],
                        'description' => ['type' => 'string', 'example' => 'My Server Description', 'description' => 'The description of the server.'],
                        'ip' => ['type' => 'string', 'example' => '127.0.0.1', 'description' => 'The IP of the server.'],
                        'port' => ['type' => 'integer', 'example' => 22, 'description' => 'The port of the server.'],
                        'user' => ['type' => 'string', 'example' => 'root', 'description' => 'The user of the server.'],
                        'private_key_uuid' => ['type' => 'string', 'example' => 'og888os', 'description' => 'The UUID of the private key.'],
                        'is_build_server' => ['type' => 'boolean', 'example' => false, 'description' => 'Is build server.'],
                        'instant_validate' => ['type' => 'boolean', 'example' => false, 'description' => 'Instant validate.'],
                        'proxy_type' => ['type' => 'string', 'enum' => ['traefik', 'caddy', 'none'], 'example' => 'traefik', 'description' => 'The proxy type.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Server created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'og888os', 'description' => 'The UUID of the server.'],
                            ]
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
    public function create_server(Request $request)
    {
        $allowedFields = ['name', 'description', 'ip', 'port', 'user', 'private_key_uuid', 'is_build_server', 'instant_validate', 'proxy_type'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'ip' => 'string|required',
            'port' => 'integer|nullable',
            'private_key_uuid' => 'string|required',
            'user' => 'string|nullable',
            'is_build_server' => 'boolean|nullable',
            'instant_validate' => 'boolean|nullable',
            'proxy_type' => 'string|nullable',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        if (! $request->name) {
            $request->offsetSet('name', generate_random_name());
        }
        if (! $request->user) {
            $request->offsetSet('user', 'root');
        }
        if (is_null($request->port)) {
            $request->offsetSet('port', 22);
        }
        if (is_null($request->is_build_server)) {
            $request->offsetSet('is_build_server', false);
        }
        if (is_null($request->instant_validate)) {
            $request->offsetSet('instant_validate', false);
        }
        if ($request->proxy_type) {
            $validProxyTypes = collect(ProxyTypes::cases())->map(function ($proxyType) {
                return str($proxyType->value)->lower();
            });
            if (! $validProxyTypes->contains(str($request->proxy_type)->lower())) {
                return response()->json(['message' => 'Invalid proxy type.'], 422);
            }
        }
        $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
        if (! $privateKey) {
            return response()->json(['message' => 'Private key not found.'], 404);
        }
        $allServers = ModelsServer::whereIp($request->ip)->get();
        if ($allServers->count() > 0) {
            return response()->json(['message' => 'Server with this IP already exists.'], 400);
        }

        $proxyType = $request->proxy_type ? str($request->proxy_type)->upper() : ProxyTypes::TRAEFIK->value;

        $server = ModelsServer::create([
            'name' => $request->name,
            'description' => $request->description,
            'ip' => $request->ip,
            'port' => $request->port,
            'user' => $request->user,
            'private_key_id' => $privateKey->id,
            'team_id' => $teamId,
        ]);
        $server->proxy->set('type', $proxyType);
        $server->proxy->set('status', ProxyStatus::EXITED->value);
        $server->save();

        $server->settings()->update([
            'is_build_server' => $request->is_build_server,
        ]);
        if ($request->instant_validate) {
            ValidateServer::dispatch($server);
        }

        return response()->json([
            'uuid' => $server->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update Server.',
        path: '/servers/{uuid}',
        operationId: 'update-server-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Server updated.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'name' => ['type' => 'string', 'description' => 'The name of the server.'],
                        'description' => ['type' => 'string', 'description' => 'The description of the server.'],
                        'ip' => ['type' => 'string', 'description' => 'The IP of the server.'],
                        'port' => ['type' => 'integer', 'description' => 'The port of the server.'],
                        'user' => ['type' => 'string', 'description' => 'The user of the server.'],
                        'private_key_uuid' => ['type' => 'string', 'description' => 'The UUID of the private key.'],
                        'is_build_server' => ['type' => 'boolean', 'description' => 'Is build server.'],
                        'instant_validate' => ['type' => 'boolean', 'description' => 'Instant validate.'],
                        'proxy_type' => ['type' => 'string', 'enum' => ['traefik', 'caddy', 'none'], 'description' => 'The proxy type.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Server updated.',
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
    public function update_server(Request $request)
    {
        $allowedFields = ['name', 'description', 'ip', 'port', 'user', 'private_key_uuid', 'is_build_server', 'instant_validate', 'proxy_type'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255|nullable',
            'description' => 'string|nullable',
            'ip' => 'string|nullable',
            'port' => 'integer|nullable',
            'private_key_uuid' => 'string|nullable',
            'user' => 'string|nullable',
            'is_build_server' => 'boolean|nullable',
            'instant_validate' => 'boolean|nullable',
            'proxy_type' => 'string|nullable',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        $server = ModelsServer::whereTeamId($teamId)->whereUuid($request->uuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        if ($request->proxy_type) {
            $validProxyTypes = collect(ProxyTypes::cases())->map(function ($proxyType) {
                return str($proxyType->value)->lower();
            });
            if ($validProxyTypes->contains(str($request->proxy_type)->lower())) {
                $server->changeProxy($request->proxy_type, async: true);
            } else {
                return response()->json(['message' => 'Invalid proxy type.'], 422);
            }
        }
        $server->update($request->only(['name', 'description', 'ip', 'port', 'user']));
        if ($request->is_build_server) {
            $server->settings()->update([
                'is_build_server' => $request->is_build_server,
            ]);
        }
        if ($request->instant_validate) {
            ValidateServer::dispatch($server);
        }

        return response()->json([
            'uuid' => $server->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete server by UUID.',
        path: '/servers/{uuid}',
        operationId: 'delete-server-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the server.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Server deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Server deleted.'],
                            ]
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
    public function delete_server(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'Uuid is required.'], 422);
        }
        $server = ModelsServer::whereTeamId($teamId)->whereUuid($request->uuid)->first();

        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        if ($server->definedResources()->count() > 0) {
            return response()->json(['message' => 'Server has resources, so you need to delete them before.'], 400);
        }
        if ($server->isLocalhost()) {
            return response()->json(['message' => 'Local server cannot be deleted.'], 400);
        }
        $server->delete();
        DeleteServer::dispatch($server);

        return response()->json(['message' => 'Server deleted.']);
    }

    #[OA\Get(
        summary: 'Validate',
        description: 'Validate server by UUID.',
        path: '/servers/{uuid}/validate',
        operationId: 'validate-server-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Server validation started.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Validation started.'],
                            ]
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
    public function validate_server(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        if (! $request->uuid) {
            return response()->json(['message' => 'Uuid is required.'], 422);
        }
        $server = ModelsServer::whereTeamId($teamId)->whereUuid($request->uuid)->first();

        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        ValidateServer::dispatch($server);

        return response()->json(['message' => 'Validation started.']);
    }
}
