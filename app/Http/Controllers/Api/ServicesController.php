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
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;
use Symfony\Component\Yaml\Yaml;

class ServicesController extends Controller
{
    private function removeSensitiveData($service)
    {
        $service->makeHidden([
            'id',
            'resourceable',
            'resourceable_id',
            'resourceable_type',
        ]);
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $service->makeHidden([
                'docker_compose_raw',
                'docker_compose',
                'value',
                'real_value',
            ]);
        }

        return serializeApiResponse($service);
    }

    private function applyServiceUrls(Service $service, array $urlsArray, string $teamId, bool $forceDomainOverride = false): ?array
    {
        $errors = [];
        $conflicts = [];

        $urls = collect($urlsArray)->flatMap(function ($item) {
            $urlValue = data_get($item, 'url');
            if (blank($urlValue)) {
                return [];
            }

            return str($urlValue)->replaceStart(',', '')->replaceEnd(',', '')->trim()->explode(',')->map(fn ($url) => trim($url))->filter();
        });

        $urls = $urls->map(function ($url) use (&$errors) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $errors[] = "Invalid URL: {$url}";

                return $url;
            }
            $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
            if (! in_array(strtolower($scheme), ['http', 'https'])) {
                $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
            }

            return $url;
        });

        $duplicates = $urls->duplicates()->unique()->values();
        if ($duplicates->isNotEmpty() && ! $forceDomainOverride) {
            $errors[] = 'The current request contains conflicting URLs across containers: '.implode(', ', $duplicates->toArray()).'. Use force_domain_override=true to proceed.';
        }

        if (count($errors) > 0) {
            return ['errors' => $errors];
        }

        collect($urlsArray)->each(function ($item) use ($service, $teamId, $forceDomainOverride, &$errors, &$conflicts) {
            $name = data_get($item, 'name');
            $containerUrls = data_get($item, 'url');

            if (blank($name)) {
                $errors[] = 'Service container name is required to apply URLs.';

                return;
            }

            $application = $service->applications()->where('name', $name)->first();
            if (! $application) {
                $errors[] = "Service container with '{$name}' not found.";

                return;
            }

            if (filled($containerUrls)) {
                $containerUrls = str($containerUrls)->replaceStart(',', '')->replaceEnd(',', '')->trim();
                $containerUrls = str($containerUrls)->explode(',')->map(fn ($url) => str(trim($url))->lower());

                $result = checkIfDomainIsAlreadyUsedViaAPI($containerUrls, $teamId, $application->uuid);
                if (isset($result['error'])) {
                    $errors[] = $result['error'];

                    return;
                }

                if ($result['hasConflicts'] && ! $forceDomainOverride) {
                    $conflicts = array_merge($conflicts, $result['conflicts']);

                    return;
                }

                $containerUrls = $containerUrls->filter(fn ($u) => filled($u))->unique()->implode(',');
            } else {
                $containerUrls = null;
            }

            $application->fqdn = $containerUrls;
            $application->save();
        });

        if (! empty($errors)) {
            return ['errors' => $errors];
        }

        if (! empty($conflicts)) {
            return [
                'conflicts' => $conflicts,
                'warning' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.',
            ];
        }

        return null;
    }

    #[OA\Get(
        summary: 'List',
        description: 'List all services.',
        path: '/services',
        operationId: 'list-services',
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
        summary: 'Create service',
        description: 'Create a one-click / custom service',
        path: '/services',
        operationId: 'create-service',
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
                    required: ['server_uuid', 'project_uuid', 'environment_name', 'environment_uuid'],
                    properties: [
                        'type' => ['description' => 'The one-click service type (e.g. "actualbudget", "calibre-web", "gitea-with-mysql" ...)', 'type' => 'string'],
                        'name' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Name of the service.'],
                        'description' => ['type' => 'string', 'nullable' => true, 'description' => 'Description of the service.'],
                        'project_uuid' => ['type' => 'string', 'description' => 'Project UUID.'],
                        'environment_name' => ['type' => 'string', 'description' => 'Environment name. You need to provide at least one of environment_name or environment_uuid.'],
                        'environment_uuid' => ['type' => 'string', 'description' => 'Environment UUID. You need to provide at least one of environment_name or environment_uuid.'],
                        'server_uuid' => ['type' => 'string', 'description' => 'Server UUID.'],
                        'destination_uuid' => ['type' => 'string', 'description' => 'Destination UUID. Required if server has multiple destinations.'],
                        'instant_deploy' => ['type' => 'boolean', 'default' => false, 'description' => 'Start the service immediately after creation.'],
                        'docker_compose_raw' => ['type' => 'string', 'description' => 'The base64 encoded Docker Compose content.'],
                        'urls' => [
                            'type' => 'array',
                            'description' => 'Array of URLs to be applied to containers of a service.',
                            'items' => new OA\Schema(
                                type: 'object',
                                properties: [
                                    'name' => ['type' => 'string', 'description' => 'The service name as defined in docker-compose.'],
                                    'url' => ['type' => 'string', 'description' => 'Comma-separated list of URLs (e.g. "http://app.coolify.io,https://app2.coolify.io").'],
                                ],
                            ),
                        ],
                        'force_domain_override' => ['type' => 'boolean', 'default' => false, 'description' => 'Force domain override even if conflicts are detected.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Service created successfully.',
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
            new OA\Response(
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function create_service(Request $request)
    {
        $allowedFields = ['type', 'name', 'description', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'docker_compose_raw', 'urls', 'force_domain_override'];

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $this->authorize('create', Service::class);

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validationRules = [
            'type' => 'string|required_without:docker_compose_raw',
            'docker_compose_raw' => 'string|required_without:type',
            'project_uuid' => 'string|required',
            'environment_name' => 'string|nullable',
            'environment_uuid' => 'string|nullable',
            'server_uuid' => 'string|required',
            'destination_uuid' => 'string|nullable',
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'instant_deploy' => 'boolean',
            'urls' => 'array|nullable',
            'urls.*' => 'array:name,url',
            'urls.*.name' => 'string|required',
            'urls.*.url' => 'string|nullable',
            'force_domain_override' => 'boolean',
        ];
        $validationMessages = [
            'urls.*.array' => 'An item in the urls array has invalid fields. Only name and url fields are supported.',
        ];
        $validator = Validator::make($request->all(), $validationRules, $validationMessages);

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

        if (filled($request->type) && filled($request->docker_compose_raw)) {
            return response()->json([
                'message' => 'You cannot provide both service type and docker_compose_raw. Use one or the other.',
            ], 422);
        }

        $environmentUuid = $request->environment_uuid;
        $environmentName = $request->environment_name;
        if (blank($environmentUuid) && blank($environmentName)) {
            return response()->json(['message' => 'You need to provide at least one of environment_name or environment_uuid.'], 422);
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
        $environment = $project->environments()->where('name', $environmentName)->first();
        if (! $environment) {
            $environment = $project->environments()->where('uuid', $environmentUuid)->first();
        }
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
                $dockerComposeRaw = base64_decode($oneClickService);

                // Validate for command injection BEFORE creating service
                try {
                    validateDockerComposeForInjection($dockerComposeRaw);
                } catch (\Exception $e) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => [
                            'docker_compose_raw' => $e->getMessage(),
                        ],
                    ], 422);
                }

                $servicePayload = [
                    'name' => "$oneClickServiceName-".str()->random(10),
                    'docker_compose_raw' => $dockerComposeRaw,
                    'environment_id' => $environment->id,
                    'service_type' => $oneClickServiceName,
                    'server_id' => $server->id,
                    'destination_id' => $destination->id,
                    'destination_type' => $destination->getMorphClass(),
                ];
                if (in_array($oneClickServiceName, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK)) {
                    data_set($servicePayload, 'connect_to_docker_network', true);
                }
                $service = Service::create($servicePayload);
                $service->name = $request->name ?? "$oneClickServiceName-".$service->uuid;
                $service->description = $request->description;
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
                            'resourceable_id' => $service->id,
                            'resourceable_type' => $service->getMorphClass(),
                            'is_preview' => false,
                        ]);
                    });
                }
                $service->parse(isNew: true);

                // Apply service-specific application prerequisites
                applyServiceApplicationPrerequisites($service);

                if ($request->has('urls') && is_array($request->urls)) {
                    $urlResult = $this->applyServiceUrls($service, $request->urls, $teamId, $request->boolean('force_domain_override'));
                    if ($urlResult !== null) {
                        $service->delete();
                        if (isset($urlResult['errors'])) {
                            return response()->json([
                                'message' => 'Validation failed.',
                                'errors' => $urlResult['errors'],
                            ], 422);
                        }
                        if (isset($urlResult['conflicts'])) {
                            return response()->json([
                                'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                                'conflicts' => $urlResult['conflicts'],
                                'warning' => $urlResult['warning'],
                            ], 409);
                        }
                    }
                }

                if ($instantDeploy) {
                    StartService::dispatch($service);
                }

                return response()->json([
                    'uuid' => $service->uuid,
                    'domains' => $service->applications()->pluck('fqdn')->filter()->sort()->values(),
                ])->setStatusCode(201);
            }

            return response()->json(['message' => 'Service not found.', 'valid_service_types' => $serviceKeys], 404);
        } elseif (filled($request->docker_compose_raw)) {
            $allowedFields = ['name', 'description', 'project_uuid', 'environment_name', 'environment_uuid', 'server_uuid', 'destination_uuid', 'instant_deploy', 'docker_compose_raw', 'connect_to_docker_network', 'urls', 'force_domain_override'];

            $validationRules = [
                'project_uuid' => 'string|required',
                'environment_name' => 'string|nullable',
                'environment_uuid' => 'string|nullable',
                'server_uuid' => 'string|required',
                'destination_uuid' => 'string',
                'name' => 'string|max:255',
                'description' => 'string|nullable',
                'instant_deploy' => 'boolean',
                'connect_to_docker_network' => 'boolean',
                'docker_compose_raw' => 'string|required',
                'urls' => 'array|nullable',
                'urls.*' => 'array:name,url',
                'urls.*.name' => 'string|required',
                'urls.*.url' => 'string|nullable',
                'force_domain_override' => 'boolean',
            ];
            $validationMessages = [
                'urls.*.array' => 'An item in the urls array has invalid fields. Only name and url fields are supported.',
            ];
            $validator = Validator::make($request->all(), $validationRules, $validationMessages);

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

            $environmentUuid = $request->environment_uuid;
            $environmentName = $request->environment_name;
            if (blank($environmentUuid) && blank($environmentName)) {
                return response()->json(['message' => 'You need to provide at least one of environment_name or environment_uuid.'], 422);
            }
            $serverUuid = $request->server_uuid;
            $projectUuid = $request->project_uuid;
            $project = Project::whereTeamId($teamId)->whereUuid($projectUuid)->first();
            if (! $project) {
                return response()->json(['message' => 'Project not found.'], 404);
            }
            $environment = $project->environments()->where('name', $environmentName)->first();
            if (! $environment) {
                $environment = $project->environments()->where('uuid', $environmentUuid)->first();
            }
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
            if (! isBase64Encoded($request->docker_compose_raw)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => 'The docker_compose_raw should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerComposeRaw = base64_decode($request->docker_compose_raw);
            if (mb_detect_encoding($dockerComposeRaw, 'UTF-8', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => 'The docker_compose_raw should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerCompose = base64_decode($request->docker_compose_raw);
            $dockerComposeRaw = Yaml::dump(Yaml::parse($dockerCompose), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

            // Validate for command injection BEFORE saving to database
            try {
                validateDockerComposeForInjection($dockerComposeRaw);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => $e->getMessage(),
                    ],
                ], 422);
            }

            $connectToDockerNetwork = $request->connect_to_docker_network ?? false;
            $instantDeploy = $request->instant_deploy ?? false;

            $service = new Service;
            $service->name = $request->name ?? 'service-'.str()->random(10);
            $service->description = $request->description;
            $service->docker_compose_raw = $dockerComposeRaw;
            $service->environment_id = $environment->id;
            $service->server_id = $server->id;
            $service->destination_id = $destination->id;
            $service->destination_type = $destination->getMorphClass();
            $service->connect_to_docker_network = $connectToDockerNetwork;
            $service->save();

            $service->parse(isNew: true);

            if ($request->has('urls') && is_array($request->urls)) {
                $urlResult = $this->applyServiceUrls($service, $request->urls, $teamId, $request->boolean('force_domain_override'));
                if ($urlResult !== null) {
                    $service->delete();
                    if (isset($urlResult['errors'])) {
                        return response()->json([
                            'message' => 'Validation failed.',
                            'errors' => $urlResult['errors'],
                        ], 422);
                    }
                    if (isset($urlResult['conflicts'])) {
                        return response()->json([
                            'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                            'conflicts' => $urlResult['conflicts'],
                            'warning' => $urlResult['warning'],
                        ], 409);
                    }
                }
            }

            if ($instantDeploy) {
                StartService::dispatch($service);
            }

            return response()->json([
                'uuid' => $service->uuid,
                'domains' => $service->applications()->pluck('fqdn')->filter()->sort()->values(),
            ])->setStatusCode(201);
        } elseif (filled($request->type)) {
            return response()->json([
                'message' => 'Invalid service type.',
                'valid_service_types' => $serviceKeys,
            ], 404);
        }
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get service by UUID.',
        path: '/services/{uuid}',
        operationId: 'get-service-by-uuid',
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
                description: 'Get a service by UUID.',
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

        $this->authorize('view', $service);

        $service = $service->load(['applications', 'databases']);

        return response()->json($this->removeSensitiveData($service));
    }

    #[OA\Delete(
        summary: 'Delete',
        description: 'Delete service by UUID.',
        path: '/services/{uuid}',
        operationId: 'delete-service-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Service UUID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_configurations', in: 'query', required: false, description: 'Delete configurations.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'delete_volumes', in: 'query', required: false, description: 'Delete volumes.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'docker_cleanup', in: 'query', required: false, description: 'Run docker cleanup.', schema: new OA\Schema(type: 'boolean', default: true)),
            new OA\Parameter(name: 'delete_connected_networks', in: 'query', required: false, description: 'Delete connected networks.', schema: new OA\Schema(type: 'boolean', default: true)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Delete a service by UUID',
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

        $this->authorize('delete', $service);

        DeleteResourceJob::dispatch(
            resource: $service,
            deleteVolumes: $request->boolean('delete_volumes', true),
            deleteConnectedNetworks: $request->boolean('delete_connected_networks', true),
            deleteConfigurations: $request->boolean('delete_configurations', true),
            dockerCleanup: $request->boolean('docker_cleanup', true)
        );

        return response()->json([
            'message' => 'Service deletion request queued.',
        ]);
    }

    #[OA\Patch(
        summary: 'Update',
        description: 'Update service by UUID.',
        path: '/services/{uuid}',
        operationId: 'update-service-by-uuid',
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
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Service updated.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        properties: [
                            'name' => ['type' => 'string', 'description' => 'The service name.'],
                            'description' => ['type' => 'string', 'description' => 'The service description.'],
                            'project_uuid' => ['type' => 'string', 'description' => 'The project UUID.'],
                            'environment_name' => ['type' => 'string', 'description' => 'The environment name.'],
                            'environment_uuid' => ['type' => 'string', 'description' => 'The environment UUID.'],
                            'server_uuid' => ['type' => 'string', 'description' => 'The server UUID.'],
                            'destination_uuid' => ['type' => 'string', 'description' => 'The destination UUID.'],
                            'instant_deploy' => ['type' => 'boolean', 'description' => 'The flag to indicate if the service should be deployed instantly.'],
                            'connect_to_docker_network' => ['type' => 'boolean', 'default' => false, 'description' => 'Connect the service to the predefined docker network.'],
                            'docker_compose_raw' => ['type' => 'string', 'description' => 'The base64 encoded Docker Compose content.'],
                            'urls' => [
                                'type' => 'array',
                                'description' => 'Array of URLs to be applied to containers of a service.',
                                'items' => new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'name' => ['type' => 'string', 'description' => 'The service name as defined in docker-compose.'],
                                        'url' => ['type' => 'string', 'description' => 'Comma-separated list of URLs (e.g. "http://app.coolify.io,https://app2.coolify.io").'],
                                    ],
                                ),
                            ],
                            'force_domain_override' => ['type' => 'boolean', 'default' => false, 'description' => 'Force domain override even if conflicts are detected.'],
                        ],
                    )
                ),
            ]
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service updated.',
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
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
            new OA\Response(
                response: 409,
                description: 'Domain conflicts detected.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Domain conflicts detected. Use force_domain_override=true to proceed.'],
                                'warning' => ['type' => 'string', 'example' => 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.'],
                                'conflicts' => [
                                    'type' => 'array',
                                    'items' => new OA\Schema(
                                        type: 'object',
                                        properties: [
                                            'domain' => ['type' => 'string', 'example' => 'example.com'],
                                            'resource_name' => ['type' => 'string', 'example' => 'My Application'],
                                            'resource_uuid' => ['type' => 'string', 'nullable' => true, 'example' => 'abc123-def456'],
                                            'resource_type' => ['type' => 'string', 'enum' => ['application', 'service', 'instance'], 'example' => 'application'],
                                            'message' => ['type' => 'string', 'example' => 'Domain example.com is already in use by application \'My Application\''],
                                        ]
                                    ),
                                ],
                            ]
                        )
                    ),
                ]
            ),
            new OA\Response(
                response: 422,
                ref: '#/components/responses/422',
            ),
        ]
    )]
    public function update_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $this->authorize('update', $service);

        $allowedFields = ['name', 'description', 'instant_deploy', 'docker_compose_raw', 'connect_to_docker_network', 'urls', 'force_domain_override'];

        $validationRules = [
            'name' => 'string|max:255',
            'description' => 'string|nullable',
            'instant_deploy' => 'boolean',
            'connect_to_docker_network' => 'boolean',
            'docker_compose_raw' => 'string|nullable',
            'urls' => 'array|nullable',
            'urls.*' => 'array:name,url',
            'urls.*.name' => 'string|required',
            'urls.*.url' => 'string|nullable',
            'force_domain_override' => 'boolean',
        ];
        $validationMessages = [
            'urls.*.array' => 'An item in the urls array has invalid fields. Only name and url fields are supported.',
        ];
        $validator = Validator::make($request->all(), $validationRules, $validationMessages);

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
        if ($request->has('docker_compose_raw')) {
            if (! isBase64Encoded($request->docker_compose_raw)) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => 'The docker_compose_raw should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerComposeRaw = base64_decode($request->docker_compose_raw);
            if (mb_detect_encoding($dockerComposeRaw, 'UTF-8', true) === false) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => 'The docker_compose_raw should be base64 encoded.',
                    ],
                ], 422);
            }
            $dockerCompose = base64_decode($request->docker_compose_raw);
            $dockerComposeRaw = Yaml::dump(Yaml::parse($dockerCompose), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

            // Validate for command injection BEFORE saving to database
            try {
                validateDockerComposeForInjection($dockerComposeRaw);
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => [
                        'docker_compose_raw' => $e->getMessage(),
                    ],
                ], 422);
            }

            $service->docker_compose_raw = $dockerComposeRaw;
        }

        if ($request->has('name')) {
            $service->name = $request->name;
        }
        if ($request->has('description')) {
            $service->description = $request->description;
        }
        if ($request->has('connect_to_docker_network')) {
            $service->connect_to_docker_network = $request->connect_to_docker_network;
        }
        $service->save();

        $service->parse();

        if ($request->has('urls') && is_array($request->urls)) {
            $urlResult = $this->applyServiceUrls($service, $request->urls, $teamId, $request->boolean('force_domain_override'));
            if ($urlResult !== null) {
                if (isset($urlResult['errors'])) {
                    return response()->json([
                        'message' => 'Validation failed.',
                        'errors' => $urlResult['errors'],
                    ], 422);
                }
                if (isset($urlResult['conflicts'])) {
                    return response()->json([
                        'message' => 'Domain conflicts detected. Use force_domain_override=true to proceed.',
                        'conflicts' => $urlResult['conflicts'],
                        'warning' => $urlResult['warning'],
                    ], 409);
                }
            }
        }

        if ($request->instant_deploy) {
            StartService::dispatch($service);
        }

        return response()->json([
            'uuid' => $service->uuid,
            'domains' => $service->applications()->pluck('fqdn')->filter()->sort()->values(),
        ])->setStatusCode(200);
    }

    #[OA\Get(
        summary: 'List Envs',
        description: 'List all envs by service UUID.',
        path: '/services/{uuid}/envs',
        operationId: 'list-envs-by-service-uuid',
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
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'All environment variables by service UUID.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/EnvironmentVariable')
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
    public function envs(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $this->authorize('manageEnvironment', $service);

        $envs = $service->environment_variables->map(function ($env) {
            $env->makeHidden([
                'application_id',
                'standalone_clickhouse_id',
                'standalone_dragonfly_id',
                'standalone_keydb_id',
                'standalone_mariadb_id',
                'standalone_mongodb_id',
                'standalone_mysql_id',
                'standalone_postgresql_id',
                'standalone_redis_id',
            ]);

            return $this->removeSensitiveData($env);
        });

        return response()->json($envs);
    }

    #[OA\Patch(
        summary: 'Update Env',
        description: 'Update env by service UUID.',
        path: '/services/{uuid}/envs',
        operationId: 'update-env-by-service-uuid',
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
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Env updated.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['key', 'value'],
                        properties: [
                            'key' => ['type' => 'string', 'description' => 'The key of the environment variable.'],
                            'value' => ['type' => 'string', 'description' => 'The value of the environment variable.'],
                            'is_preview' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in preview deployments.'],
                            'is_literal' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is a literal, nothing espaced.'],
                            'is_multiline' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is multiline.'],
                            'is_shown_once' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable\'s value is shown on the UI.'],
                        ],
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Environment variable updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Environment variable updated.'],
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
    public function update_env_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $this->authorize('manageEnvironment', $service);

        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = str($request->key)->trim()->replace(' ', '_')->value;
        $env = $service->environment_variables()->where('key', $key)->first();
        if (! $env) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }

        $env->fill($request->all());
        $env->save();

        return response()->json($this->removeSensitiveData($env))->setStatusCode(201);
    }

    #[OA\Patch(
        summary: 'Update Envs (Bulk)',
        description: 'Update multiple envs by service UUID.',
        path: '/services/{uuid}/envs/bulk',
        operationId: 'update-envs-by-service-uuid',
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
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            description: 'Bulk envs updated.',
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        type: 'object',
                        required: ['data'],
                        properties: [
                            'data' => [
                                'type' => 'array',
                                'items' => new OA\Schema(
                                    type: 'object',
                                    properties: [
                                        'key' => ['type' => 'string', 'description' => 'The key of the environment variable.'],
                                        'value' => ['type' => 'string', 'description' => 'The value of the environment variable.'],
                                        'is_preview' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in preview deployments.'],
                                        'is_literal' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is a literal, nothing espaced.'],
                                        'is_multiline' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is multiline.'],
                                        'is_shown_once' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable\'s value is shown on the UI.'],
                                    ],
                                ),
                            ],
                        ],
                    ),
                ),
            ],
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Environment variables updated.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Environment variables updated.'],
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
    public function create_bulk_envs(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $this->authorize('manageEnvironment', $service);

        $bulk_data = $request->get('data');
        if (! $bulk_data) {
            return response()->json(['message' => 'Bulk data is required.'], 400);
        }

        $updatedEnvs = collect();
        foreach ($bulk_data as $item) {
            $validator = customApiValidator($item, [
                'key' => 'string|required',
                'value' => 'string|nullable',
                'is_literal' => 'boolean',
                'is_multiline' => 'boolean',
                'is_shown_once' => 'boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            $key = str($item['key'])->trim()->replace(' ', '_')->value;
            $env = $service->environment_variables()->updateOrCreate(
                ['key' => $key],
                $item
            );

            $updatedEnvs->push($this->removeSensitiveData($env));
        }

        return response()->json($updatedEnvs)->setStatusCode(201);
    }

    #[OA\Post(
        summary: 'Create Env',
        description: 'Create env by service UUID.',
        path: '/services/{uuid}/envs',
        operationId: 'create-env-by-service-uuid',
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
                )
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'Env created.',
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    properties: [
                        'key' => ['type' => 'string', 'description' => 'The key of the environment variable.'],
                        'value' => ['type' => 'string', 'description' => 'The value of the environment variable.'],
                        'is_preview' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is used in preview deployments.'],
                        'is_literal' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is a literal, nothing espaced.'],
                        'is_multiline' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable is multiline.'],
                        'is_shown_once' => ['type' => 'boolean', 'description' => 'The flag to indicate if the environment variable\'s value is shown on the UI.'],
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Environment variable created.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'uuid' => ['type' => 'string', 'example' => 'nc0k04gk8g0cgsk440g0koko'],
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
    public function create_env(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $this->authorize('manageEnvironment', $service);

        $validator = customApiValidator($request->all(), [
            'key' => 'string|required',
            'value' => 'string|nullable',
            'is_literal' => 'boolean',
            'is_multiline' => 'boolean',
            'is_shown_once' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $key = str($request->key)->trim()->replace(' ', '_')->value;
        $existingEnv = $service->environment_variables()->where('key', $key)->first();
        if ($existingEnv) {
            return response()->json([
                'message' => 'Environment variable already exists. Use PATCH request to update it.',
            ], 409);
        }

        $env = $service->environment_variables()->create($request->all());

        return response()->json($this->removeSensitiveData($env))->setStatusCode(201);
    }

    #[OA\Delete(
        summary: 'Delete Env',
        description: 'Delete env by UUID.',
        path: '/services/{uuid}/envs/{env_uuid}',
        operationId: 'delete-env-by-service-uuid',
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
                )
            ),
            new OA\Parameter(
                name: 'env_uuid',
                in: 'path',
                description: 'UUID of the environment variable.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Environment variable deleted.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'message' => ['type' => 'string', 'example' => 'Environment variable deleted.'],
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
            new OA\Response(
                response: 404,
                ref: '#/components/responses/404',
            ),
        ]
    )]
    public function delete_env_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $service = Service::whereRelation('environment.project.team', 'id', $teamId)->whereUuid($request->uuid)->first();
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $this->authorize('manageEnvironment', $service);

        $env = EnvironmentVariable::where('uuid', $request->env_uuid)
            ->where('resourceable_type', Service::class)
            ->where('resourceable_id', $service->id)
            ->first();

        if (! $env) {
            return response()->json(['message' => 'Environment variable not found.'], 404);
        }

        $env->forceDelete();

        return response()->json(['message' => 'Environment variable deleted.']);
    }

    #[OA\Get(
        summary: 'Start',
        description: 'Start service. `Post` request is also accepted.',
        path: '/services/{uuid}/start',
        operationId: 'start-service-by-uuid',
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

        $this->authorize('deploy', $service);

        if (str($service->status)->contains('running')) {
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
        operationId: 'stop-service-by-uuid',
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

        $this->authorize('stop', $service);

        if (str($service->status)->contains('stopped') || str($service->status)->contains('exited')) {
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
        operationId: 'restart-service-by-uuid',
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
                )
            ),
            new OA\Parameter(
                name: 'latest',
                in: 'query',
                description: 'Pull latest images.',
                schema: new OA\Schema(
                    type: 'boolean',
                    default: false,
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

        $this->authorize('deploy', $service);

        $pullLatest = $request->boolean('latest');
        RestartService::dispatch($service, $pullLatest);

        return response()->json(
            [
                'message' => 'Service restarting request queued.',
            ],
            200
        );
    }
}
