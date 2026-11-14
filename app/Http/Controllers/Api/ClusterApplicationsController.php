<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\RollbackNodeWorkload;
use App\Enums\NodeOperationStatus;
use App\Http\Controllers\Controller;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use RuntimeException;

/**
 * Revisions and rollback of cluster applications (decision 0017). A revision also stores the environment
 * variables and secrets of its deployment; this API never returns them.
 */
class ClusterApplicationsController extends Controller
{
    private const REVISION_LIMIT = 50;

    #[OA\Get(
        summary: 'List Revisions',
        description: 'List the latest 50 revisions of a cluster application, newest first. Environment variables and secrets are not returned.',
        path: '/cluster-applications/{uuid}/revisions',
        operationId: 'list-cluster-application-revisions',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The revisions of the cluster application.',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(
                        type: 'object',
                        properties: [
                            'uuid' => new OA\Property(property: 'uuid', type: 'string'),
                            'image' => new OA\Property(property: 'image', type: 'string'),
                            'commit_sha' => new OA\Property(property: 'commit_sha', type: 'string', nullable: true),
                            'created_at' => new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                            'current' => new OA\Property(property: 'current', type: 'boolean', description: 'The latest revision, which deploys next.'),
                            'last_deployment' => new OA\Property(
                                property: 'last_deployment',
                                type: 'object',
                                nullable: true,
                                properties: [
                                    'uuid' => new OA\Property(property: 'uuid', type: 'string'),
                                    'status' => new OA\Property(property: 'status', type: 'string'),
                                    'server' => new OA\Property(property: 'server', type: 'string', nullable: true),
                                    'finished_at' => new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
                                ],
                            ),
                            'can_roll_back' => new OA\Property(property: 'can_roll_back', type: 'boolean', description: 'An earlier image that deployed successfully.'),
                        ],
                    ),
                ),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function revisions(Request $request): JsonResponse
    {
        $workload = $this->workload($request);
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('view', $workload);

        $revisions = $workload->revisions()->with('build')->latest('id')->limit(self::REVISION_LIMIT)->get();
        $currentImage = $revisions->first()?->image;
        $deployments = NodeOperation::query()
            ->with('node')
            ->where('command_type', 'workload.deploy.v1')
            ->whereIn('node_workload_revision_id', $revisions->pluck('id'))
            ->latest('id')
            ->get()
            ->groupBy('node_workload_revision_id');

        return response()->json($revisions->map(function (NodeWorkloadRevision $revision, int $index) use ($workload, $currentImage, $deployments): array {
            $revisionDeployments = $deployments->get($revision->id, collect());
            $last = $revisionDeployments->first();

            return [
                'uuid' => $revision->uuid,
                'image' => $revision->image,
                'commit_sha' => $revision->build?->commit_sha,
                'created_at' => $revision->created_at,
                'current' => $index === 0,
                'last_deployment' => $last === null ? null : [
                    'uuid' => $last->uuid,
                    'status' => $last->status->value,
                    'server' => $last->node?->name,
                    'finished_at' => $last->completed_at,
                ],
                'can_roll_back' => ! $workload->isDatabase()
                    && $revision->image !== $currentImage
                    && $revisionDeployments->contains(fn (NodeOperation $operation): bool => $operation->status === NodeOperationStatus::SUCCEEDED),
            ];
        })->values());
    }

    #[OA\Post(
        summary: 'Roll Back',
        description: 'Roll a cluster application back to the image of an earlier revision. Environment variables, secrets, and settings keep their current values, and volume data is not rolled back. When the cluster registry no longer has the image, Coolify builds the same commit again and deploys the result.',
        path: '/cluster-applications/{uuid}/rollback',
        operationId: 'rollback-cluster-application',
        security: [
            ['bearerAuth' => []],
        ],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'UUID of the cluster application.', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['revision_uuid'],
                properties: [
                    'revision_uuid' => new OA\Property(property: 'revision_uuid', type: 'string', description: 'UUID of the revision to roll back to, from List Revisions.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'The rollback is queued.',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        'message' => new OA\Property(property: 'message', type: 'string'),
                        'deployment_uuid' => new OA\Property(property: 'deployment_uuid', type: 'string', nullable: true),
                        'build_uuid' => new OA\Property(property: 'build_uuid', type: 'string', nullable: true, description: 'Set when Coolify builds the image again.'),
                    ],
                ),
            ),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function rollback(Request $request): JsonResponse
    {
        $workload = $this->workload($request);
        if ($workload === null) {
            return response()->json(['message' => 'Cluster application not found.'], 404);
        }
        $this->authorize('update', $workload);

        $validator = customApiValidator($request->all(), ['revision_uuid' => 'required|string']);
        $extraFields = array_diff(array_keys($request->all()), ['revision_uuid']);
        if ($validator->fails() || $extraFields !== []) {
            $errors = $validator->errors();
            foreach ($extraFields as $field) {
                $errors->add($field, 'This field is not allowed.');
            }

            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        $revision = $workload->revisions()->where('uuid', $request->input('revision_uuid'))->first();
        if ($revision === null) {
            return response()->json(['message' => 'Revision not found.'], 404);
        }

        try {
            $result = RollbackNodeWorkload::run($workload, $revision, auth()->user());
        } catch (RuntimeException|DomainException|InvalidArgumentException $exception) {
            // Every one of these says what blocks the rollback: the revision, the build, or a server.
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        $deploymentUuid = $result['operation']?->uuid;
        $buildUuid = $result['build']?->uuid;
        auditLog('api.deployment.rollback', [
            'resource_type' => 'cluster-application',
            'workload_uuid' => $workload->uuid,
            'workload_name' => $workload->name,
            'revision_uuid' => $revision->uuid,
            'deployment_uuid' => $deploymentUuid,
            'build_uuid' => $buildUuid,
        ]);

        return response()->json([
            'message' => $buildUuid !== null
                ? "The image is no longer in the cluster registry. Application {$workload->name} builds it again and then deploys."
                : "Application {$workload->name} rollback queued.",
            'deployment_uuid' => $deploymentUuid,
            'build_uuid' => $buildUuid,
        ]);
    }

    private function workload(Request $request): ?NodeWorkload
    {
        return NodeWorkload::query()->where('uuid', $request->route('uuid'))->where('team_id', getTeamIdFromToken())->first();
    }
}
