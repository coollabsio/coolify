<?php

namespace App\Http\Controllers\Api;

use App\Actions\Database\StartDatabase;
use App\Actions\Service\StartService;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use App\Models\Service;
use App\Models\Tag;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Visus\Cuid2\Cuid2;

class DeployController extends Controller
{
    private function removeSensitiveData($deployment)
    {
        if (request()->attributes->get('can_read_sensitive', false) === false) {
            $deployment->makeHidden([
                'logs',
            ]);
        }

        return serializeApiResponse($deployment);
    }

    #[OA\Get(
        summary: 'List',
        description: 'List currently running deployments',
        path: '/deployments',
        operationId: 'list-deployments',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Deployments'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get all currently running deployments.',
                content: [

                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/ApplicationDeploymentQueue'),
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
    public function deployments(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $servers = Server::whereTeamId($teamId)->get();
        $deployments_per_server = ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])->whereIn('server_id', $servers->pluck('id'))->get()->sortBy('id');
        $deployments_per_server = $deployments_per_server->map(function ($deployment) {
            return $this->removeSensitiveData($deployment);
        });

        return response()->json($deployments_per_server);
    }

    #[OA\Get(
        summary: 'Get',
        description: 'Get deployment by UUID.',
        path: '/deployments/{uuid}',
        operationId: 'get-deployment-by-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Deployments'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Deployment UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Get deployment by UUID.',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            ref: '#/components/schemas/ApplicationDeploymentQueue',
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
    public function deployment_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['message' => 'UUID is required.'], 400);
        }
        $deployment = ApplicationDeploymentQueue::where('deployment_uuid', $uuid)->first();
        if (! $deployment) {
            return response()->json(['message' => 'Deployment not found.'], 404);
        }

        return response()->json($this->removeSensitiveData($deployment));
    }

    #[OA\Get(
        summary: 'Deploy',
        description: 'Deploy by tag or uuid. `Post` request also accepted with `uuid` and `tag` json body.',
        path: '/deploy',
        operationId: 'deploy-by-tag-or-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Deployments'],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'query', description: 'Tag name(s). Comma separated list is also accepted.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'uuid', in: 'query', description: 'Resource UUID(s). Comma separated list is also accepted.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'force', in: 'query', description: 'Force rebuild (without cache)', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'pr', in: 'query', description: 'Pull Request Id for deploying specific PR builds. Cannot be used with tag parameter.', schema: new OA\Schema(type: 'integer')),
        ],

        responses: [
            new OA\Response(
                response: 200,
                description: 'Get deployment(s) UUID\'s',
                content: [
                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'object',
                            properties: [
                                'deployments' => new OA\Property(
                                    property: 'deployments',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            'message' => ['type' => 'string'],
                                            'resource_uuid' => ['type' => 'string'],
                                            'deployment_uuid' => ['type' => 'string'],
                                        ]
                                    ),
                                ),
                            ],
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
    public function deploy(Request $request)
    {
        $teamId = getTeamIdFromToken();

        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $uuids = $request->input('uuid');
        $tags = $request->input('tag');
        $force = $request->input('force') ?? false;
        $pr = $request->input('pr') ? max((int) $request->input('pr'), 0) : 0;

        if ($uuids && $tags) {
            return response()->json(['message' => 'You can only use uuid or tag, not both.'], 400);
        }
        if ($tags && $pr) {
            return response()->json(['message' => 'You can only use tag or pr, not both.'], 400);
        }
        if ($tags) {
            return $this->by_tags($tags, $teamId, $force);
        } elseif ($uuids) {
            return $this->by_uuids($uuids, $teamId, $force, $pr);
        }

        return response()->json(['message' => 'You must provide uuid or tag.'], 400);
    }

    private function by_uuids(string $uuid, int $teamId, bool $force = false, int $pr = 0)
    {
        $uuids = explode(',', $uuid);
        $uuids = collect(array_filter($uuids));

        if (count($uuids) === 0) {
            return response()->json(['message' => 'No UUIDs provided.'], 400);
        }
        $deployments = collect();
        $payload = collect();
        foreach ($uuids as $uuid) {
            $resource = getResourceByUuid($uuid, $teamId);
            if ($resource) {
                ['message' => $return_message, 'deployment_uuid' => $deployment_uuid] = $this->deploy_resource($resource, $force, $pr);
                if ($deployment_uuid) {
                    $deployments->push(['message' => $return_message, 'resource_uuid' => $uuid, 'deployment_uuid' => $deployment_uuid->toString()]);
                } else {
                    $deployments->push(['message' => $return_message, 'resource_uuid' => $uuid]);
                }
            }
        }
        if ($deployments->count() > 0) {
            $payload->put('deployments', $deployments->toArray());

            return response()->json(serializeApiResponse($payload->toArray()));
        }

        return response()->json(['message' => 'No resources found.'], 404);
    }

    public function by_tags(string $tags, int $team_id, bool $force = false)
    {
        $tags = explode(',', $tags);
        $tags = collect(array_filter($tags));

        if (count($tags) === 0) {
            return response()->json(['message' => 'No TAGs provided.'], 400);
        }
        $message = collect([]);
        $deployments = collect();
        $payload = collect();
        foreach ($tags as $tag) {
            $found_tag = Tag::where(['name' => $tag, 'team_id' => $team_id])->first();
            if (! $found_tag) {
                // $message->push("Tag {$tag} not found.");
                continue;
            }
            $applications = $found_tag->applications()->get();
            $services = $found_tag->services()->get();
            if ($applications->count() === 0 && $services->count() === 0) {
                $message->push("No resources found for tag {$tag}.");

                continue;
            }
            foreach ($applications as $resource) {
                ['message' => $return_message, 'deployment_uuid' => $deployment_uuid] = $this->deploy_resource($resource, $force);
                if ($deployment_uuid) {
                    $deployments->push(['resource_uuid' => $resource->uuid, 'deployment_uuid' => $deployment_uuid->toString()]);
                }
                $message = $message->merge($return_message);
            }
            foreach ($services as $resource) {
                ['message' => $return_message] = $this->deploy_resource($resource, $force);
                $message = $message->merge($return_message);
            }
        }
        if ($message->count() > 0) {
            $payload->put('message', $message->toArray());
            if ($deployments->count() > 0) {
                $payload->put('details', $deployments->toArray());
            }

            return response()->json(serializeApiResponse($payload->toArray()));
        }

        return response()->json(['message' => 'No resources found with this tag.'], 404);
    }

    public function deploy_resource($resource, bool $force = false, int $pr = 0): array
    {
        $message = null;
        $deployment_uuid = null;
        if (gettype($resource) !== 'object') {
            return ['message' => "Resource ($resource) not found.", 'deployment_uuid' => $deployment_uuid];
        }
        switch ($resource?->getMorphClass()) {
            case Application::class:
                $deployment_uuid = new Cuid2;
                $result = queue_application_deployment(
                    application: $resource,
                    deployment_uuid: $deployment_uuid,
                    force_rebuild: $force,
                    pull_request_id: $pr,
                );
                if ($result['status'] === 'skipped') {
                    $message = $result['message'];
                } else {
                    $message = "Application {$resource->name} deployment queued.";
                }
                break;
            case Service::class:
                StartService::run($resource);
                $message = "Service {$resource->name} started. It could take a while, be patient.";
                break;
            default:
                // Database resource
                StartDatabase::dispatch($resource);
                $resource->update([
                    'started_at' => now(),
                ]);
                $message = "Database {$resource->name} started.";
                break;
        }

        return ['message' => $message, 'deployment_uuid' => $deployment_uuid];
    }

    #[OA\Get(
        summary: 'List application deployments',
        description: 'List application deployments by using the app uuid',
        path: '/deployments/applications/{uuid}',
        operationId: 'list-deployments-by-app-uuid',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Deployments'],
        parameters: [
            new OA\Parameter(
                name: 'uuid',
                in: 'path',
                description: 'UUID of the application.',
                required: true,
                schema: new OA\Schema(
                    type: 'string',
                    format: 'uuid',
                )
            ),
            new OA\Parameter(
                name: 'skip',
                in: 'query',
                description: 'Number of records to skip.',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 0,
                    default: 0,
                )
            ),
            new OA\Parameter(
                name: 'take',
                in: 'query',
                description: 'Number of records to take.',
                required: false,
                schema: new OA\Schema(
                    type: 'integer',
                    minimum: 1,
                    default: 10,
                )
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List application deployments by using the app uuid.',
                content: [

                    new OA\MediaType(
                        mediaType: 'application/json',
                        schema: new OA\Schema(
                            type: 'array',
                            items: new OA\Items(ref: '#/components/schemas/Application'),
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
    public function get_application_deployments(Request $request)
    {
        $request->validate([
            'skip' => ['nullable', 'integer', 'min:0'],
            'take' => ['nullable', 'integer', 'min:1'],
        ]);

        $app_uuid = $request->route('uuid', null);
        $skip = $request->get('skip', 0);
        $take = $request->get('take', 10);

        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $servers = Server::whereTeamId($teamId)->get();

        if (is_null($app_uuid)) {
            return response()->json(['message' => 'Application uuid is required'], 400);
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $app_uuid)->first();

        if (is_null($application)) {
            return response()->json(['message' => 'Application not found'], 404);
        }
        $deployments = $application->deployments($skip, $take);

        return response()->json($deployments);
    }
}
