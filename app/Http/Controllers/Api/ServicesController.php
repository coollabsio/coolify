<?php

namespace App\Http\Controllers\Api;

use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Http\Controllers\Controller;
use App\Jobs\DeleteResourceJob;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ServicesController extends Controller
{
    private function removeSensitiveData($service)
    {
        $token = auth()->user()->currentAccessToken();
        $service->makeHidden([
            'id',
        ]);
        if ($token->can('view:sensitive')) {
            return serializeApiResponse($service);
        }

        $service->makeHidden([
            'docker_compose_raw',
            'docker_compose',
        ]);

        return serializeApiResponse($service);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all services.',
        path: '/services',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all services',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Service')
                        )
                    ),
                ]
            ),
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
    public function services(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $services = collect();
        foreach ($projects as $project) {
            $services->push($project->services()->get());
        }
        foreach ($services as $service) {
            $service = $this->removeSensitiveData($service);
        }

        return response()->json($services->flatten());
    }

    #[OA\Post(
        summary: 'Create',
        description: 'Create a one-click service',
        path: '/services',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'type'],
                    properties: [
                        'type' => [
                            'description' => 'The one-click service type',
                            'type' => 'string',
                            'enum' => [
                                'activepieces',
                                'appsmith',
                                'appwrite',
                                'authentik',
                                'babybuddy',
                                'budge',
                                'changedetection',
                                'chatwoot',
                                'classicpress-with-mariadb',
                                'classicpress-with-mysql',
                                'classicpress-without-database',
                                'cloudflared',
                                'code-server',
                                'dashboard',
                                'directus',
                                'directus-with-postgresql',
                                'docker-registry',
                                'docuseal',
                                'docuseal-with-postgres',
                                'dokuwiki',
                                'duplicati',
                                'emby',
                                'embystat',
                                'fider',
                                'filebrowser',
                                'firefly',
                                'formbricks',
                                'ghost',
                                'gitea',
                                'gitea-with-mariadb',
                                'gitea-with-mysql',
                                'gitea-with-postgresql',
                                'glance',
                                'glances',
                                'glitchtip',
                                'grafana',
                                'grafana-with-postgresql',
                                'grocy',
                                'heimdall',
                                'homepage',
                                'jellyfin',
                                'kuzzle',
                                'listmonk',
                                'logto',
                                'mediawiki',
                                'meilisearch',
                                'metabase',
                                'metube',
                                'minio',
                                'moodle',
                                'n8n',
                                'n8n-with-postgresql',
                                'next-image-transformation',
                                'nextcloud',
                                'nocodb',
                                'odoo',
                                'openblocks',
                                'pairdrop',
                                'penpot',
                                'phpmyadmin',
                                'pocketbase',
                                'posthog',
                                'reactive-resume',
                                'rocketchat',
                                'shlink',
                                'slash',
                                'snapdrop',
                                'statusnook',
                                'stirling-pdf',
                                'supabase',
                                'syncthing',
                                'tolgee',
                                'trigger',
                                'trigger-with-external-database',
                                'twenty',
                                'umami',
                                'unleash-with-postgresql',
                                'unleash-without-database',
                                'uptime-kuma',
                                'vaultwarden',
                                'vikunja',
                                'weblate',
                                'whoogle',
                                'wordpress-with-mariadb',
                                'wordpress-with-mysql',
                                'wordpress-without-database',
                            ],
                        ],
                        'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Name of the service.'],
                        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'Description of the service.'],
                        'project_uuid' => ['type' => 'string', 'description' => 'Project UUID.'],
                        'environment_name' => ['type' => 'string', 'description' => 'Environment name.'],
                        'server_uuid' => ['type' => 'string', 'description' => 'Server UUID.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'Destination UUID. Required if server has multiple destinations.'],
                        'instant_deploy' => ['type' => 'boolean', 'default' => false, 'description' => 'Start the service immediately after creation.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Create a service.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'description' => 'Service UUID.'],
                                'domains' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Service domains.'],
                            ]
                        )
                    ),
                ]
            ),
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
    public function create_service(Request $request)
    {
        $allowedFields = ['type', 'name', 'description', 'project_uuid', 'environment_name', 'server_uuid', 'destination_uuid', 'instant_deploy'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'type' => 'string|required',
            'project_uuid' => 'string|required',
            'environment_name' => 'string|required',
            'server_uuid' => 'string|required',
            'destination_uuid' => 'string',
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'instant_deploy' => 'boolean',
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
        $serverUuid = $request->server_uuid;
        $instantDeploy = $request->instant_deploy ?? false;
        if ($request->is_public && ! $request->public_port) {
            $request->offsetSet('is_public', false);
        }
        $project = Project::whereTeamId($teamId)->whereUuid($request->project_uuid)->first();
        if (! $project) {
            return response()->json(['message' => 'Project not found.'], 404);
        }
        $environment = $project->environments()->where('name', $request->environment_name)->first();
        if (! $environment) {
            return response()->json(['message' => 'Environment not found.'], 404);
        }
        $server = Server::whereTeamId($teamId)->whereUuid($serverUuid)->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }
        $destinations = $server->destinations();
        if ($destinations->count() == 0) {
            return response()->json(['message' => 'Server has no destinations.'], 400);
        }
        if ($destinations->count() > 1 && ! $request->has('destination_uuid')) {
            return response()->json(['message' => 'Server has multiple destinations and you do not set destination_uuid.'], 400);
        }
        $destination = $destinations->first();
        $services = get_service_templates();
        $serviceKeys = $services->keys();
        if ($serviceKeys->contains($request->type)) {
            $oneClickServiceName = $request->type;
            $oneClickService = data_get($services, "$oneClickServiceName.compose");
            $oneClickDotEnvs = data_get($services, "$oneClickServiceName.envs", null);
            if ($oneClickDotEnvs) {
                $oneClickDotEnvs = str(base64_decode($oneClickDotEnvs))->split('/\r\n|\r|\n/')->filter(function ($value) {
                    return ! empty($value);
                });
            }
            if ($oneClickService) {
                $service_payload = [
                    'name' => "$oneClickServiceName-".str()->random(10),
                    'docker_compose_raw' => base64_decode($oneClickService),
                    'environment_id' => $environment->id,
                    'service_type' => $oneClickServiceName,
                    'server_id' => $server->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination->getMorphClass(),
                ];
                if ($oneClickServiceName === 'cloudflared') {
                    data_set($service_payload, 'connect_to_docker_network', true);
                }
                $service = Service::create($service_payload);
                $service->name = "$oneClickServiceName-".$service->uuid;
                $service->save();
                if ($oneClickDotEnvs?->count() > 0) {
                    $oneClickDotEnvs->each(function ($value) use ($service) {
                        $key = str()->before($value, '=');
                        $value = str(str()->after($value, '='));
                        $generatedValue = $value;
                        if ($value->contains('SERVICE_')) {
                            $command = $value->after('SERVICE_')->beforeLast('_');
                            $generatedValue = generateEnvValue($command->value(), $service);
                        }
                        EnvironmentVariable::create([
                            'key' => $key,
                            'value' => $generatedValue,
                            'service_id' => $service->id,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    });
                }
                $service->parse(isNew: true);
                if ($instantDeploy) {
                    StartService::dispatch($service);
                }
                $domains = $service->applications()->get()->pluck('fqdn')->sort();
                $domains = $domains->map(function ($domain) {
                    return str($domain)->beforeLast(':')->value();
                });

                return response()->json([
                    'uuid' => $service->uuid,
                    'domains' => $domains,
                ]);
            }

            return response()->json(['message' => 'Service not found.'], 404);
        } else {
            return response()->json(['message' => 'Invalid service type.', 'valid_service_types' => $serviceKeys], 400);
        }

        return response()->json(['message' => 'Invalid service type.'], 400);
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get service by UUID.',
        path: '/services/{uuid}',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get a service by Uuid.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/Service'
                        )
                    ),
                ]
            ),
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
    public function service_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return response()->json($this->removeSensitiveData($service));
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete service by UUID.',
        path: '/services/{uuid}',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Delete a service by Uuid',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Service deletion request queued.'],
                            ],
                        )
                    ),
                ]
            ),
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
    public function delete_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['message' => 'UUID is required.'], 404);
        }
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        DeleteResourceJob::dispatch($service);

        return response()->json([
            'message' => 'Service deletion request queued.',
        ]);
    }

    #[OA\Get(
        summary: 'Start',
        description: 'Start service. `Post` request is also accepted.',
        path: '/services/{uuid}/start',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
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
                description: 'Start service.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Service starting request queued.'],
                            ])
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
    public function action_deploy(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        if (str($service->status())->contains('running')) {
            return response()->json(['message' => 'Service is already running.'], 400);
        }
        StartService::dispatch($service);

        return response()->json(
            [
                'message' => 'Service starting request queued.',
            ],
            200
        );
    }

    #[OA\Get(
        summary: 'Stop',
        description: 'Stop service. `Post` request is also accepted.',
        path: '/services/{uuid}/stop',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
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
                description: 'Stop service.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Service stopping request queued.'],
                            ])
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
    public function action_stop(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        if (str($service->status())->contains('stopped') || str($service->status())->contains('exited')) {
            return response()->json(['message' => 'Service is already stopped.'], 400);
        }
        StopService::dispatch($service);

        return response()->json(
            [
                'message' => 'Service stopping request queued.',
            ],
            200
        );
    }

    #[OA\Get(
        summary: 'Restart',
        description: 'Restart service. `Post` request is also accepted.',
        path: '/services/{uuid}/restart',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the service.',
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
                description: 'Restart service.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Service restaring request queued.'],
                            ])
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
    public function action_restart(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }
        RestartService::dispatch($service);

        return response()->json(
            [
                'message' => 'Service restarting request queued.',
            ],
            200
        );

    }
}
