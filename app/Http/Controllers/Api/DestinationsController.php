<?php

namespace App\Http\Controllers\Api;

use App\Actions\Destination\RemoveStandaloneDockerNetwork;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DestinationsController extends Controller
{
    private function transform(StandaloneDocker|SwarmDocker $destination): array
    {
        return [
            'uuid' => $destination->uuid,
            'name' => $destination->name,
            'network' => $destination->network,
            'type' => $destination instanceof SwarmDocker ? 'swarm' : 'standalone',
            'server_uuid' => $destination->server?->uuid,
            'created_at' => $destination->created_at,
            'updated_at' => $destination->updated_at,
        ];
    }

    /**
     * Resolve the calling token's team id, or return an invalid-token response.
     */
    private function teamIdOrAbort(): int|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        return $teamId;
    }

    /**
     * StandaloneDocker / SwarmDocker scoped to a team via their parent server.
     * Uses whereHas instead of the model's ownedByCurrentTeamAPI() scope so the
     * controller works on Coolify versions that pre-date that scope being added
     * to the destination models (e.g. 4.0.0-beta.470).
     */
    private function teamScopedDockers(int $teamId): array
    {
        return [
            'standalone' => StandaloneDocker::with('server:id,uuid')->whereHas('server', fn ($query) => $query->whereTeamId($teamId))->get(),
            'swarm' => SwarmDocker::with('server:id,uuid')->whereHas('server', fn ($query) => $query->whereTeamId($teamId))->get(),
        ];
    }

    private function findDestinationForTeam(int $teamId, string $uuid): StandaloneDocker|SwarmDocker
    {
        return StandaloneDocker::with('server:id,uuid,team_id,ip,user,port,private_key_id')->whereHas('server', fn ($query) => $query->whereTeamId($teamId))->whereUuid($uuid)->first()
            ?? SwarmDocker::with('server:id,uuid,team_id')->whereHas('server', fn ($query) => $query->whereTeamId($teamId))->whereUuid($uuid)->firstOrFail();
    }

    #[OA\Get(
        summary: 'List destinations',
        description: 'List all Docker network destinations for the authenticated team.',
        path: '/destinations',
        operationId: 'list-destinations',
        security: [['bearerAuth' => []]],
        tags: ['Destinations'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Destinations for the authenticated team.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Destination')),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $sets = $this->teamScopedDockers($teamId);

        return response()->json(
            $sets['standalone']->concat($sets['swarm'])
                ->map(fn ($destination) => $this->transform($destination))
                ->values()
        );
    }

    #[OA\Get(
        summary: 'List destinations by server',
        description: 'List Docker network destinations attached to a server owned by the authenticated team.',
        path: '/servers/{server_uuid}/destinations',
        operationId: 'list-server-destinations',
        security: [['bearerAuth' => []]],
        tags: ['Destinations'],
        parameters: [
            new OA\Parameter(name: 'server_uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Destinations attached to the server.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Destination')),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function index_by_server(Request $request, string $server_uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $server = Server::with(['standaloneDockers.server:id,uuid', 'swarmDockers.server:id,uuid'])
            ->whereTeamId($teamId)
            ->whereUuid($server_uuid)
            ->firstOrFail();
        $list = $server->standaloneDockers->concat($server->swarmDockers);

        return response()->json($list->map(fn ($destination) => $this->transform($destination))->values());
    }

    #[OA\Get(
        summary: 'Get destination',
        description: 'Get a Docker network destination by UUID.',
        path: '/destinations/{uuid}',
        operationId: 'get-destination-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Destinations'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Destination UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Destination details.',
                content: new OA\JsonContent(ref: '#/components/schemas/Destination'),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ],
    )]
    public function show(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $destination = $this->findDestinationForTeam($teamId, $uuid);

        return response()->json($this->transform($destination));
    }

    #[OA\Post(
        summary: 'Create destination',
        description: 'Create a Docker network destination on a server owned by the authenticated team.',
        path: '/servers/{server_uuid}/destinations',
        operationId: 'create-server-destination',
        security: [['bearerAuth' => []]],
        tags: ['Destinations'],
        parameters: [
            new OA\Parameter(name: 'server_uuid', in: 'path', required: true, description: 'Server UUID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['network'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'network', type: 'string', maxLength: 255, pattern: '^[a-zA-Z0-9][a-zA-Z0-9._-]*$'),
                    new OA\Property(property: 'type', type: 'string', enum: ['standalone', 'swarm']),
                ],
                type: 'object',
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Destination created.',
                content: new OA\JsonContent(ref: '#/components/schemas/Destination'),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'A destination with this network already exists.'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function create(Request $request, string $server_uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $server = Server::whereTeamId($teamId)->whereUuid($server_uuid)->firstOrFail();

        $allowed = ['name', 'network', 'type'];

        $validator = customApiValidator($request->all(), [
            'name' => 'nullable|string|max:255',
            'network' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'type' => 'nullable|in:standalone,swarm',
        ]);
        $extra = array_diff(array_keys($request->all()), $allowed);
        if ($validator->fails() || ! empty($extra)) {
            $errors = $validator->errors();
            if (! empty($extra)) {
                foreach ($extra as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $expectedType = $server->isSwarm() ? 'swarm' : 'standalone';
        $type = $request->input('type', $expectedType);
        if ($type !== $expectedType) {
            return response()->json(['message' => "Destination type must be {$expectedType} for this server."], 422);
        }

        $name = $request->input('name') ?: ($server->name.'-'.$request->input('network'));
        $class = $type === 'swarm' ? SwarmDocker::class : StandaloneDocker::class;

        $this->authorize('create', $class);

        $exists = $class::where('server_id', $server->id)->where('network', $request->input('network'))->exists();
        if ($exists) {
            return response()->json(['message' => 'A destination with this network already exists on the server.'], 409);
        }

        try {
            $destination = $class::create([
                'name' => $name,
                'network' => $request->input('network'),
                'server_id' => $server->id,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return response()->json(['message' => 'A destination with this network already exists on the server.'], 409);
            }

            throw $exception;
        }

        auditLog('api.destination.created', [
            'team_id' => $teamId,
            'destination_uuid' => $destination->uuid,
            'destination_name' => $destination->name,
            'destination_type' => $type,
            'server_uuid' => $server->uuid,
        ]);

        return response()->json($this->transform($destination->load('server:id,uuid')), 201);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (string) ($exception->errorInfo[1] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062', '2067'], true);
    }

    #[OA\Delete(
        summary: 'Delete destination',
        description: 'Delete an unused Docker network destination.',
        path: '/destinations/{uuid}',
        operationId: 'delete-destination-by-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Destinations'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'Destination UUID', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Destination deleted.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Deleted.'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Destination has attached resources.'),
        ],
    )]
    public function delete(Request $request, string $uuid): JsonResponse
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $destination = $this->findDestinationForTeam($teamId, $uuid);

        $this->authorize('delete', $destination);

        // Guard against deleting destinations with attached resources. attachedTo()
        // is recent on the destination models; fall back to a manual check for
        // older Coolify versions (e.g. 4.0.0-beta.470).
        if (method_exists($destination, 'attachedTo')) {
            if ($destination->attachedTo()) {
                return response()->json(['message' => 'Destination has attached resources, detach first.'], 409);
            }
        } else {
            $hasAttached = $destination->applications()->exists()
                || $destination->postgresqls()->exists()
                || (method_exists($destination, 'mysqls') && $destination->mysqls()->exists())
                || (method_exists($destination, 'mariadbs') && $destination->mariadbs()->exists())
                || (method_exists($destination, 'mongodbs') && $destination->mongodbs()->exists())
                || (method_exists($destination, 'redis') && $destination->redis()->exists())
                || (method_exists($destination, 'keydbs') && $destination->keydbs()->exists())
                || (method_exists($destination, 'dragonflies') && $destination->dragonflies()->exists())
                || (method_exists($destination, 'clickhouses') && $destination->clickhouses()->exists())
                || (method_exists($destination, 'services') && $destination->services()->exists());
            if ($hasAttached) {
                return response()->json(['message' => 'Destination has attached resources, detach first.'], 409);
            }
        }
        if ($destination instanceof StandaloneDocker) {
            app(RemoveStandaloneDockerNetwork::class)->handle($destination);
        }

        $destinationUuid = $destination->uuid;
        $destinationName = $destination->name;
        $destinationType = $destination instanceof SwarmDocker ? 'swarm' : 'standalone';
        $serverUuid = $destination->server?->uuid;

        $destination->delete();

        auditLog('api.destination.deleted', [
            'team_id' => $teamId,
            'destination_uuid' => $destinationUuid,
            'destination_name' => $destinationName,
            'destination_type' => $destinationType,
            'server_uuid' => $serverUuid,
        ]);

        return response()->json(['message' => 'Deleted.']);
    }
}
