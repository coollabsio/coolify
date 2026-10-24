<?php

namespace App\Http\Controllers\Api;

use App\Actions\Server\DeleteServer;
use App\Actions\Server\ValidateServer;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Enums\ServerRole;
use App\Http\Controllers\Api\Concerns\RequiresDeployAbility;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteResourceJob;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Application;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server as ModelsServer;
use App\Models\Team;
use App\Rules\ValidServerIp;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Support\ValidationPatterns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Stringable;

class ServersController extends Controller
{
    use RequiresDeployAbility;

    /**
     * The server name goes into one-line config files on the server (for example the log drain config), so it must not contain control characters such as new lines.
     */
    private const SERVER_NAME_CONTROL_CHARACTERS_PATTERN = '/[\x00-\x1F\x7F]/';

    private function removeSensitiveDataFromSettings($settings)
    {
        if (request()->attributes->get('can_read_sensitive', false) === true) {
            $settings = $settings->makeVisible([
                'sentinel_token',
                'sentinel_custom_url',
                'logdrain_newrelic_license_key',
                'logdrain_axiom_api_key',
                'logdrain_custom_config',
                'logdrain_custom_config_parser',
            ]);
        }

        return serializeApiResponse($settings)
            ->put('is_build_server', $settings->effectiveServerRole() === ServerRole::BUILD);
    }

    /**
     * Another instance manages a transferred server. Changing its host or credentials would let
     * "Enable management" connect to a different host, so these fields stay as they are.
     *
     * @return array<string, array<int, string>>
     */
    private function transferredServerConnectionChanges(Request $request, ModelsServer $server): array
    {
        $changed = [
            'ip' => $request->filled('ip') && ModelsServer::normalizeIp($request->input('ip')) !== ModelsServer::normalizeIp($server->getRawOriginal('ip')),
            'user' => $request->filled('user') && (string) $request->input('user') !== (string) $server->user,
            'port' => $request->filled('port') && (int) $request->input('port') !== (int) $server->port,
            'private_key_uuid' => $request->filled('private_key_uuid') && $request->input('private_key_uuid') !== $server->privateKey?->uuid,
        ];

        return collect($changed)
            ->filter()
            ->map(fn () => ['This field cannot be changed on a transferred server.'])
            ->all();
    }

    /**
     * Rejects requests where the deprecated is_build_server flag disagrees with server_role.
     */
    private function legacyBuildServerRoleConflict(Request $request): ?JsonResponse
    {
        if (! $request->filled('is_build_server') || ! $request->filled('server_role')) {
            return null;
        }

        $wantsBuildOnly = $request->boolean('is_build_server');
        $roleIsBuildOnly = $request->string('server_role')->toString() === ServerRole::BUILD->value;
        if ($wantsBuildOnly === $roleIsBuildOnly) {
            return null;
        }

        return response()->json([
            'message' => 'Validation failed.',
            'errors' => ['is_build_server' => ['is_build_server is deprecated and conflicts with server_role. Send only server_role.']],
        ], 422);
    }

    private function removeSensitiveData($server)
    {
        $server->makeHidden([
            'id',
        ]);
        $canReadSensitive = request()->attributes->get('can_read_sensitive', false) === true;
        if ($canReadSensitive) {
            $server->makeVisible([
                'logdrain_axiom_api_key',
                'logdrain_newrelic_license_key',
            ]);
        }

        $serialized = serializeApiResponse($server);
        if (! $canReadSensitive && is_array($serialized->get('proxy'))) {
            $serialized->put('proxy', Arr::except($serialized->get('proxy'), ['last_saved_proxy_configuration']));
        }

        return $serialized;
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
        $this->authorize('view', $server);
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

        $server->append('unreachable_notification_sent');
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
        $server = ModelsServer::whereTeamId($teamId)->whereUuid($request->route('uuid'))->first();
        if (is_null($server)) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $uuid = $request->query('uuid');
        if ($uuid) {
            $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
            if (! $application) {
                return response()->json(['message' => 'Application not found.'], 404);
            }

            return response()->json(serializeApiResponse($application->fqdns));
        }
        $projects = Project::where('team_id', $teamId)->get();
        $domains = collect();
        $applications = $projects->pluck('applications')->flatten()->filter(function ($application) use ($server) {
            return $application->destination?->server?->id === $server->id;
        });
        $settings = instanceSettings();
        if ($applications->count() > 0) {
            foreach ($applications as $application) {
                $ip = $application->destination->server->ip;
                $fqdn = str($application->fqdn)->explode(',')->map(fn ($fqdn) => self::domainHostWithoutPort((string) $fqdn))->filter(function (Stringable $fqdn) {
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
        $services = $projects->pluck('services')->flatten()->filter(function ($service) use ($server) {
            return $service->server_id === $server->id;
        });
        if ($services->count() > 0) {
            foreach ($services as $service) {
                $service_applications = $service->applications;
                if ($service_applications->count() > 0) {
                    foreach ($service_applications as $application) {
                        $fqdn = str($application->fqdn)->explode(',')->map(fn ($fqdn) => self::domainHostWithoutPort((string) $fqdn))->filter(function (Stringable $fqdn) {
                            return $fqdn->isNotEmpty();
                        });
                        $serviceIp = $server->ip;
                        if ($serviceIp === 'host.docker.internal') {
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
                                    'ip' => $serviceIp,
                                ]);
                            }
                        } else {
                            $domains->push([
                                'domain' => $fqdn,
                                'ip' => $serviceIp,
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
                        'name' => ['type' => 'string', 'example' => 'My Server', 'description' => 'The name of the server. Must not contain control characters such as new lines.'],
                        'description' => ['type' => 'string', 'example' => 'My Server Description', 'description' => 'The description of the server.'],
                        'ip' => ['type' => 'string', 'example' => '127.0.0.1', 'description' => 'The IP of the server.'],
                        'port' => ['type' => 'integer', 'example' => 22, 'description' => 'The port of the server.'],
                        'user' => ['type' => 'string', 'example' => 'root', 'description' => 'The user of the server.'],
                        'private_key_uuid' => ['type' => 'string', 'example' => 'og888os', 'description' => 'The UUID of the private key.'],
                        'server_role' => ['type' => 'string', 'enum' => ['deployment', 'build', 'both'], 'example' => 'both', 'description' => 'Server role.'],
                        'is_build_server' => ['type' => 'boolean', 'deprecated' => true, 'description' => 'Deprecated: use server_role instead. true sets server_role to build, false sets it to both. Must not conflict with server_role.'],
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
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_server(Request $request)
    {
        $allowedFields = ['name', 'description', 'ip', 'port', 'user', 'private_key_uuid', 'server_role', 'is_build_server', 'instant_validate', 'proxy_type'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $this->authorize('create', [ModelsServer::class]);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'name' => ['string', 'max:255', 'not_regex:'.self::SERVER_NAME_CONTROL_CHARACTERS_PATTERN],
            'description' => 'string|nullable',
            'ip' => ['string', 'required', new ValidServerIp],
            'port' => 'integer|nullable|between:1,65535',
            'private_key_uuid' => 'string|required',
            'user' => ValidationPatterns::serverUsernameRules(required: false),
            'server_role' => 'string|nullable|in:deployment,build,both',
            'is_build_server' => 'boolean|nullable',
            'instant_validate' => 'boolean|nullable',
            'proxy_type' => 'string|nullable',
        ], [
            ...ValidationPatterns::serverUsernameMessages(),
            'name.not_regex' => 'The name must not contain control characters such as new lines.',
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
        $legacyRoleConflict = $this->legacyBuildServerRoleConflict($request);
        if ($legacyRoleConflict) {
            return $legacyRoleConflict;
        }
        $serverRole = match (true) {
            $request->filled('server_role') => ServerRole::from($request->string('server_role')->toString()),
            $request->filled('is_build_server') && $request->boolean('is_build_server') => ServerRole::BUILD,
            default => ServerRole::BOTH,
        };

        if ($serverRole === ServerRole::DEPLOYMENT && ! ModelsServer::buildServers($teamId)->exists()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['server_role' => ['Add a usable build server before you set this server to deployments only.']],
            ], 422);
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
        $foundServer = ModelsServer::whereIp(ModelsServer::normalizeIp($request->ip))->first();
        if ($foundServer) {
            return response()->json(['message' => 'A server with this IP/Domain is already in use.'], 400);
        }
        if ($request->instant_validate) {
            ModelsServer::throttleValidation($teamId, $request->ip);
        }

        $proxyType = $request->proxy_type ? str($request->proxy_type)->upper() : ProxyTypes::TRAEFIK->value;

        try {
            $server = Team::createServerWithinLimit($teamId, [
                'name' => $request->name,
                'description' => $request->description,
                'ip' => $request->ip,
                'port' => $request->port,
                'user' => $request->user,
                'private_key_id' => $privateKey->id,
                'team_id' => $teamId,
            ]);
        } catch (ValidationException) {
            return response()->json(['message' => 'Server limit reached for your subscription.'], 400);
        }
        $server->proxy->set('type', $proxyType);
        $server->proxy->set('status', ProxyStatus::EXITED->value);
        $server->save();

        $server->settings()->update([
            'server_role' => $serverRole,
            'is_build_server' => $serverRole === ServerRole::BUILD,
        ]);
        if ($request->instant_validate) {
            ValidateServer::dispatch($server);
        }

        auditLog('api.server.created', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'ip' => $server->ip,
            'server_role' => $serverRole->value,
        ]);

        return response()->json([
            'uuid' => $server->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update Server. Changing proxy_type needs a token with the write and deploy permissions.',
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
                        'name' => ['type' => 'string', 'description' => 'The name of the server. Must not contain control characters such as new lines.'],
                        'description' => ['type' => 'string', 'description' => 'The description of the server.'],
                        'ip' => ['type' => 'string', 'description' => 'The IP of the server.'],
                        'port' => ['type' => 'integer', 'description' => 'The port of the server.'],
                        'user' => ['type' => 'string', 'description' => 'The user of the server.'],
                        'private_key_uuid' => ['type' => 'string', 'description' => 'The UUID of the private key.'],
                        'server_role' => ['type' => 'string', 'enum' => ['deployment', 'build', 'both'], 'description' => 'Server role.'],
                        'is_build_server' => ['type' => 'boolean', 'deprecated' => true, 'description' => 'Deprecated: use server_role instead. true sets server_role to build, false changes a build server to both and leaves other roles unchanged. Must not conflict with server_role.'],
                        'instant_validate' => ['type' => 'boolean', 'description' => 'Instant validate.'],
                        'proxy_type' => ['type' => 'string', 'enum' => ['traefik', 'caddy', 'none'], 'description' => 'The proxy type. Changing it starts the new proxy on the server and needs the deploy permission.'],
                        'concurrent_builds' => ['type' => 'integer', 'description' => 'Number of concurrent builds.'],
                        'dynamic_timeout' => ['type' => 'integer', 'description' => 'Deployment timeout in seconds.'],
                        'deployment_queue_limit' => ['type' => 'integer', 'description' => 'Maximum number of queued deployments.'],
                        'server_disk_usage_notification_threshold' => ['type' => 'integer', 'description' => 'Server disk usage notification threshold (%).'],
                        'server_disk_usage_check_frequency' => ['type' => 'string', 'description' => 'Cron expression for disk usage check frequency.'],
                        'server_disk_usage_notification_interval_hours' => ['type' => 'integer', 'description' => 'Minimum hours between high disk usage notifications (1-720). Default: 24.'],
                        'connection_timeout' => ['type' => 'integer', 'description' => 'SSH connection timeout in seconds (1-300). Default: 10.'],
                        'wildcard_domain' => ['type' => 'string', 'nullable' => true, 'description' => 'Wildcard domain (URL) used to generate domains for new resources on this server. Null removes it.'],
                        'server_timezone' => ['type' => 'string', 'description' => 'Timezone of the server, for example Europe/Berlin. Scheduled tasks and backups use it.'],
                        'backup_compression_cpu_percentage' => ['type' => 'integer', 'enum' => [25, 50, 75, 100], 'description' => 'Maximum CPU percentage that backup compression can use. Default: 25.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(response: 403, description: 'The token needs the deploy permission to change the proxy type.'),
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
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_server(Request $request)
    {
        $allowedFields = ['name', 'description', 'ip', 'port', 'user', 'private_key_uuid', 'server_role', 'is_build_server', 'instant_validate', 'proxy_type', 'concurrent_builds', 'dynamic_timeout', 'deployment_queue_limit', 'server_disk_usage_notification_threshold', 'server_disk_usage_check_frequency', 'server_disk_usage_notification_interval_hours', 'connection_timeout', 'is_terminal_enabled', 'wildcard_domain', 'server_timezone', 'backup_compression_cpu_percentage'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'name' => ['string', 'max:255', 'nullable', 'not_regex:'.self::SERVER_NAME_CONTROL_CHARACTERS_PATTERN],
            'description' => 'string|nullable',
            'ip' => ['string', 'nullable', new ValidServerIp],
            'port' => 'integer|nullable|between:1,65535',
            'private_key_uuid' => 'string|nullable',
            'user' => ValidationPatterns::serverUsernameRules(required: false),
            'server_role' => 'string|nullable|in:deployment,build,both',
            'is_build_server' => 'boolean|nullable',
            'instant_validate' => 'boolean|nullable',
            'proxy_type' => 'string|nullable',
            'concurrent_builds' => 'integer|min:1',
            'dynamic_timeout' => 'integer|min:1',
            'deployment_queue_limit' => 'integer|min:1',
            'server_disk_usage_notification_threshold' => 'integer|min:1|max:100',
            'server_disk_usage_check_frequency' => 'string',
            'server_disk_usage_notification_interval_hours' => 'integer|min:1|max:720',
            'connection_timeout' => 'integer|min:1|max:300',
            'is_terminal_enabled' => 'boolean|nullable',
            'wildcard_domain' => 'nullable|url',
            'server_timezone' => ['string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! validate_timezone($value)) {
                    $fail('The server_timezone must be a valid timezone.');
                }
            }],
            'backup_compression_cpu_percentage' => 'integer|in:25,50,75,100',
        ], [
            ...ValidationPatterns::serverUsernameMessages(),
            'name.not_regex' => 'The name must not contain control characters such as new lines.',
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
        $this->authorize('update', $server);
        if ($server->isTransferredAway() && ($connectionErrors = $this->transferredServerConnectionChanges($request, $server))) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $connectionErrors,
            ], 422);
        }
        if ($request->instant_validate) {
            ModelsServer::throttleValidation($teamId, $request->ip ?? $server->ip);
        }
        if ($request->proxy_type) {
            $validProxyTypes = collect(ProxyTypes::cases())->map(function ($proxyType) {
                return str($proxyType->value)->lower();
            });
            if (! $validProxyTypes->contains(str($request->proxy_type)->lower())) {
                return response()->json(['message' => 'Invalid proxy type.'], 422);
            }
        }
        $updateFields = $request->only(['name', 'description', 'ip', 'port', 'user']);
        if ($request->filled('private_key_uuid')) {
            $privateKey = PrivateKey::whereTeamId($teamId)->whereUuid($request->private_key_uuid)->first();
            if (! $privateKey) {
                return response()->json(['message' => 'Private key not found.'], 404);
            }
            $updateFields['private_key_id'] = $privateKey->id;
        }

        if ($request->has('server_disk_usage_check_frequency') && ! validate_cron_expression($request->server_disk_usage_check_frequency)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['server_disk_usage_check_frequency' => ['Invalid Cron / Human expression for Disk Usage Check Frequency.']],
            ], 422);
        }

        $legacyRoleConflict = $this->legacyBuildServerRoleConflict($request);
        if ($legacyRoleConflict) {
            return $legacyRoleConflict;
        }

        $serverRole = null;
        if ($request->filled('server_role')) {
            $serverRole = ServerRole::from($request->string('server_role')->toString());
        } elseif ($request->filled('is_build_server')) {
            // Legacy flag: true makes the server build only, false turns a build only server back into a regular one.
            if ($request->boolean('is_build_server')) {
                $serverRole = ServerRole::BUILD;
            } elseif ($server->isBuildServer()) {
                $serverRole = ServerRole::BOTH;
            }
        }

        if ($serverRole === ServerRole::BUILD && ! $server->isBuildServer() && ! $server->isEmpty()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['server_role' => ['A server with existing resources cannot be configured as build only.']],
            ], 422);
        }

        if ($serverRole === ServerRole::DEPLOYMENT && $server->hasEnabledGithubRunners()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['server_role' => ['Disable the GitHub runners before you set this server to deployments only.']],
            ], 422);
        }

        if ($serverRole === ServerRole::DEPLOYMENT && ! ModelsServer::buildServers($teamId)->whereKeyNot($server->id)->exists()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['server_role' => ['Add a usable build server before you set this server to deployments only.']],
            ], 422);
        }

        $proxyTypeChanged = $request->proxy_type && ! str($request->proxy_type)->lower()->exactly(str($server->proxyType())->lower());
        if ($proxyTypeChanged && ($response = $this->deployAbilityForbiddenResponse($request, 'Changing the proxy type starts the new proxy on the server, so it needs a token with the deploy permission.'))) {
            return $response;
        }

        $server->update($updateFields);
        if ($serverRole !== null) {
            $server->settings()->update([
                'server_role' => $serverRole,
                'is_build_server' => $serverRole === ServerRole::BUILD,
            ]);
        }

        if ($request->has('is_terminal_enabled')) {
            $server->settings()->update([
                'is_terminal_enabled' => $request->boolean('is_terminal_enabled'),
            ]);
        }

        $advancedSettings = $request->only(['concurrent_builds', 'dynamic_timeout', 'deployment_queue_limit', 'server_disk_usage_notification_threshold', 'server_disk_usage_check_frequency', 'server_disk_usage_notification_interval_hours', 'connection_timeout', 'server_timezone', 'backup_compression_cpu_percentage']);
        if (! empty($advancedSettings)) {
            $server->settings()->update(array_filter($advancedSettings, fn ($value) => ! is_null($value)));
        }
        if ($request->has('wildcard_domain')) {
            $server->settings()->update(['wildcard_domain' => $request->input('wildcard_domain')]);
        }

        if ($proxyTypeChanged) {
            $server->changeProxy($request->proxy_type, async: true);
        }

        if ($request->instant_validate) {
            ValidateServer::dispatch($server);
        }

        auditLog('api.server.updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'changed_fields' => array_values(array_intersect($allowedFields, array_keys($request->all()))),
        ]);

        return response()->json([
            'uuid' => $server->uuid,
        ])->setStatusCode(201);
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete server by UUID. With force or delete_from_provider, needs a token with the write and deploy permissions.',
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
                )
            ),
            new OA\Parameter(
                name: 'force',
                in: 'query',
                description: 'Also delete all resources on the server. Needs the deploy permission.',
                required: false,
                schema: new OA\Schema(type: 'boolean', default: false)
            ),
            new OA\Parameter(
                name: 'delete_from_provider',
                in: 'query',
                description: 'Also delete the server from its cloud provider (Hetzner, Vultr or DigitalOcean). This cannot be undone. Needs the deploy permission.',
                required: false,
                schema: new OA\Schema(type: 'boolean', default: false)
            ),
        ],
        responses: [
            new OA\Response(response: 403, description: 'The token needs the deploy permission to delete the resources or the cloud server.'),
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
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
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
        $this->authorize('delete', $server);

        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN);
        $deleteFromProvider = filter_var($request->query('delete_from_provider', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (is_null($deleteFromProvider)) {
            return response()->json(['message' => 'delete_from_provider must be a boolean.'], 422);
        }
        // Another Coolify instance manages a transferred server. This instance deletes only its own records:
        // never the cloud server, the containers, or the DNS records that the other instance uses.
        if ($server->isTransferredAway()) {
            if ($deleteFromProvider) {
                return response()->json(['message' => 'This server is managed by another Coolify instance. Delete its cloud server from that instance.'], 422);
            }
            app(ServerTransferClaimer::class)->deleteArchive($server);
            auditLog('api.server.deleted', ['team_id' => $teamId, 'server_uuid' => $server->uuid, 'server_name' => $server->name, 'ip' => $server->ip, 'archive' => true]);

            return response()->json(['message' => 'Server deleted.']);
        }

        if ($server->definedResources()->count() > 0 && ! $force) {
            return response()->json(['message' => 'Server has resources. Use ?force=true to delete all resources and the server, or delete resources manually first.'], 400);
        }
        if ($server->is_coolify_host) {
            return response()->json(['message' => 'Local server cannot be deleted.'], 400);
        }

        if ($deleteFromProvider) {
            $linkedProviders = array_keys(array_filter([
                'hetzner' => $server->hetzner_server_id,
                'vultr' => $server->vultr_instance_id,
                'digitalocean' => $server->digitalocean_droplet_id,
            ]));
            if (empty($linkedProviders)) {
                return response()->json(['message' => 'Server is not linked to a cloud provider.'], 422);
            }
            foreach ($linkedProviders as $provider) {
                if (! CloudProviderToken::whereTeamId($server->team_id)->whereProvider($provider)->exists()) {
                    return response()->json(['message' => "No {$provider} token found for this team. Add one before deleting the server from the cloud provider."], 422);
                }
            }
        }

        if (($force || $deleteFromProvider) && ($response = $this->deployAbilityForbiddenResponse($request, 'Deleting the resources or the cloud server needs a token with the deploy permission.'))) {
            return $response;
        }

        if ($force) {
            foreach ($server->definedResources() as $resource) {
                DeleteResourceJob::dispatch($resource);
            }
        }

        $deletedUuid = $server->uuid;
        $deletedName = $server->name;
        $deletedIp = $server->ip;
        $server->delete();
        DeleteServer::dispatch(
            $server->id,
            $deleteFromProvider,
            $server->hetzner_server_id,
            $server->cloud_provider_token_id,
            $server->team_id,
            $deleteFromProvider,
            $server->vultr_instance_id,
            $deleteFromProvider,
            $server->digitalocean_droplet_id
        );

        auditLog('api.server.deleted', [
            'team_id' => $teamId,
            'server_uuid' => $deletedUuid,
            'server_name' => $deletedName,
            'ip' => $deletedIp,
            'force' => $force,
            'delete_from_provider' => $deleteFromProvider,
        ]);

        return response()->json(['message' => 'Server deleted.']);
    }

    #[OA\Post(
        summary: 'Validate',
        description: 'Validate server by UUID. With install=true, needs a token with the write and deploy permissions.',
        path: '/servers/{uuid}/validate',
        operationId: 'validate-server-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'install',
                        description: 'Install missing prerequisites and Docker, then start the proxy. This can restart the Docker daemon. Needs the deploy permission.',
                        type: 'boolean',
                        default: false,
                    ),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 403, description: 'The token needs the deploy permission to install the server.'),
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
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
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
        $this->authorize('update', $server);

        if (! $server->canBeValidated()) {
            return response()->json([
                'message' => 'This server was transferred to another Coolify instance and cannot be revalidated here.',
            ], 422);
        }

        $validator = customApiValidator($request->all(), [
            'install' => 'boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $install = $request->boolean('install', false);
        if ($install && ($response = $this->deployAbilityForbiddenResponse($request, 'Installing the server runs commands on it and starts the proxy, so it needs a token with the deploy permission.'))) {
            return $response;
        }
        ModelsServer::throttleValidation($teamId, $server->ip);
        if ($install) {
            ValidateAndInstallServerJob::dispatch($server);
        } else {
            ValidateServer::dispatch($server);
        }

        auditLog('api.server.validated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'install' => $install,
        ]);

        $message = $install ? 'Validation and installation started.' : 'Validation started.';

        return response()->json(['message' => $message], 201);
    }

    /**
     * The host of a domain without the port: "[2a01:4f8::1]:8080" gives "[2a01:4f8::1]", "example.com:3000" gives "example.com".
     */
    private static function domainHostWithoutPort(string $fqdn): Stringable
    {
        $authority = (string) str($fqdn)->replace('http://', '')->replace('https://', '')->explode('/')[0];
        if (str_starts_with($authority, '[') && str_contains($authority, ']')) {
            return str(substr($authority, 0, strpos($authority, ']') + 1));
        }

        return str(str($authority)->explode(':')[0]);
    }
}
