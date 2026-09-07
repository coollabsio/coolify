<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DockerCleanupJob;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ServerDockerCleanupController extends Controller
{
    private const ALLOWED_FIELDS = [
        'docker_cleanup_frequency',
        'docker_cleanup_threshold',
        'force_docker_cleanup',
        'delete_unused_volumes',
        'delete_unused_networks',
        'disable_application_image_retention',
    ];

    private function findServerForTeam(int $teamId, string $uuid): ?Server
    {
        return Server::whereTeamId($teamId)->whereUuid($uuid)->first();
    }

    private function transform(Server $server): array
    {
        $settings = $server->settings;

        return [
            'docker_cleanup_frequency' => $settings->docker_cleanup_frequency,
            'docker_cleanup_threshold' => (int) $settings->docker_cleanup_threshold,
            'force_docker_cleanup' => (bool) $settings->force_docker_cleanup,
            'delete_unused_volumes' => (bool) $settings->delete_unused_volumes,
            'delete_unused_networks' => (bool) $settings->delete_unused_networks,
            'disable_application_image_retention' => (bool) $settings->disable_application_image_retention,
        ];
    }

    #[OA\Get(
        summary: 'Get Docker cleanup settings',
        description: 'Get Docker cleanup settings for a server owned by the authenticated team.',
        path: '/servers/{uuid}/docker-cleanup',
        operationId: 'get-server-docker-cleanup',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Docker cleanup settings.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'docker_cleanup_frequency', type: 'string'),
                        new OA\Property(property: 'docker_cleanup_threshold', type: 'integer'),
                        new OA\Property(property: 'force_docker_cleanup', type: 'boolean'),
                        new OA\Property(property: 'delete_unused_volumes', type: 'boolean'),
                        new OA\Property(property: 'delete_unused_networks', type: 'boolean'),
                        new OA\Property(property: 'disable_application_image_retention', type: 'boolean'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        return response()->json($this->transform($server));
    }

    #[OA\Patch(
        summary: 'Update Docker cleanup settings',
        description: 'Update Docker cleanup settings for a server owned by the authenticated team.',
        path: '/servers/{uuid}/docker-cleanup',
        operationId: 'update-server-docker-cleanup',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'docker_cleanup_frequency', type: 'string', description: 'Cron / human frequency expression.'),
                    new OA\Property(property: 'docker_cleanup_threshold', type: 'integer', minimum: 1, maximum: 99),
                    new OA\Property(property: 'force_docker_cleanup', type: 'boolean'),
                    new OA\Property(property: 'delete_unused_volumes', type: 'boolean'),
                    new OA\Property(property: 'delete_unused_networks', type: 'boolean'),
                    new OA\Property(property: 'disable_application_image_retention', type: 'boolean'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated Docker cleanup settings.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'docker_cleanup_frequency', type: 'string'),
                        new OA\Property(property: 'docker_cleanup_threshold', type: 'integer'),
                        new OA\Property(property: 'force_docker_cleanup', type: 'boolean'),
                        new OA\Property(property: 'delete_unused_volumes', type: 'boolean'),
                        new OA\Property(property: 'delete_unused_networks', type: 'boolean'),
                        new OA\Property(property: 'disable_application_image_retention', type: 'boolean'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function update(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $validator = customApiValidator($request->all(), [
            'docker_cleanup_frequency' => 'string',
            'docker_cleanup_threshold' => 'integer|min:1|max:99',
            'force_docker_cleanup' => 'boolean',
            'delete_unused_volumes' => 'boolean',
            'delete_unused_networks' => 'boolean',
            'disable_application_image_retention' => 'boolean',
        ]);

        $extraFields = array_diff(array_keys($request->all()), self::ALLOWED_FIELDS);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        if ($request->has('docker_cleanup_frequency') && ! validate_cron_expression($request->docker_cleanup_frequency)) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['docker_cleanup_frequency' => ['Invalid Cron / Human expression for Docker Cleanup Frequency.']],
            ], 422);
        }

        $settings = $server->settings;
        foreach (self::ALLOWED_FIELDS as $field) {
            if ($request->has($field)) {
                $settings->{$field} = $request->input($field);
            }
        }
        $settings->save();

        auditLog('api.server.docker_cleanup.updated', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'changed_fields' => array_values(array_intersect(self::ALLOWED_FIELDS, array_keys($request->all()))),
        ]);

        return response()->json($this->transform($server->refresh()));
    }

    #[OA\Post(
        summary: 'Run Docker cleanup',
        description: 'Dispatch a manual Docker cleanup job for a server owned by the authenticated team.',
        path: '/servers/{uuid}/docker-cleanup/run',
        operationId: 'run-server-docker-cleanup',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'delete_unused_volumes', type: 'boolean'),
                    new OA\Property(property: 'delete_unused_networks', type: 'boolean'),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Docker cleanup job dispatched.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Manual cleanup job started.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function run(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('update', $server);

        $validator = customApiValidator($request->all(), [
            'delete_unused_volumes' => 'boolean',
            'delete_unused_networks' => 'boolean',
        ]);
        $extraFields = array_diff(array_keys($request->all()), ['delete_unused_volumes', 'delete_unused_networks']);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $deleteUnusedVolumes = $request->has('delete_unused_volumes')
            ? $request->boolean('delete_unused_volumes')
            : (bool) $server->settings->delete_unused_volumes;
        $deleteUnusedNetworks = $request->has('delete_unused_networks')
            ? $request->boolean('delete_unused_networks')
            : (bool) $server->settings->delete_unused_networks;

        DockerCleanupJob::dispatch($server, true, $deleteUnusedVolumes, $deleteUnusedNetworks);

        auditLog('api.server.docker_cleanup.run', [
            'team_id' => $teamId,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'delete_unused_volumes' => $deleteUnusedVolumes,
            'delete_unused_networks' => $deleteUnusedNetworks,
        ]);

        return response()->json([
            'message' => 'Manual cleanup job started. Depending on the amount of data, this might take a while.',
        ]);
    }

    #[OA\Get(
        summary: 'List Docker cleanup executions',
        description: 'List recent Docker cleanup execution logs for a server owned by the authenticated team.',
        path: '/servers/{uuid}/docker-cleanup/executions',
        operationId: 'list-server-docker-cleanup-executions',
        security: [['bearerAuth' => []]],
        tags: ['Servers'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Recent Docker cleanup executions.',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'uuid', type: 'string'),
                            new OA\Property(property: 'status', type: 'string'),
                            new OA\Property(property: 'message', type: 'string', nullable: true),
                            new OA\Property(property: 'finished_at', type: 'string', nullable: true),
                            new OA\Property(property: 'created_at', type: 'string'),
                            new OA\Property(property: 'updated_at', type: 'string'),
                        ],
                        type: 'object',
                    ),
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function executions(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $server = $this->findServerForTeam($teamId, $request->uuid);
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        $this->authorize('view', $server);

        $executions = $server->dockerCleanupExecutions()
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get()
            ->map(fn ($execution) => [
                'uuid' => $execution->uuid,
                'status' => $execution->status,
                'message' => $execution->message,
                'finished_at' => $execution->finished_at,
                'created_at' => $execution->created_at,
                'updated_at' => $execution->updated_at,
            ])
            ->values();

        return response()->json($executions);
    }
}
