<?php

namespace App\Http\Controllers\Api;

use App\Actions\Node\CreateNodeVolume;
use App\Actions\Node\DeleteNodeVolumeData;
use App\Actions\Node\ManageNodeVolumeBackups;
use App\Actions\Node\RemoveNodeVolume;
use App\Actions\Node\UpdateNodeVolume;
use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Http\Controllers\Api\Concerns\HandlesClusterResourcesApi;
use App\Http\Controllers\Api\Concerns\RequiresDeployAbility;
use App\Http\Controllers\Controller;
use App\Models\NodeStack;
use App\Models\NodeVolume;
use App\Models\NodeVolumeMount;
use App\Models\NodeWorkload;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use RuntimeException;

#[OA\Schema(
    schema: 'ClusterVolume',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'mount_path', type: 'string', nullable: true, description: 'The path in this workload. Null for a volume of a stack.'),
        new OA\Property(property: 'read_only', type: 'boolean'),
        new OA\Property(property: 'shared', type: 'boolean', description: 'A volume of a stack that services share.'),
        new OA\Property(property: 'detached', type: 'boolean', description: 'Removed from the configuration. The data stays until you delete it.'),
        new OA\Property(property: 'server_uuid', type: 'string', nullable: true, description: 'Null until a deployment creates the volume.'),
        new OA\Property(property: 'server_name', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['not created', 'unknown', 'ready', 'missing']),
        new OA\Property(property: 'backup_schedule_uuid', type: 'string', nullable: true),
        new OA\Property(property: 'mounts', type: 'array', description: 'Only for a volume of a stack: the services that mount it.', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'service', type: 'string'),
            new OA\Property(property: 'mount_path', type: 'string'),
            new OA\Property(property: 'read_only', type: 'boolean'),
        ])),
    ],
)]
#[OA\Schema(
    schema: 'ClusterVolumeBackup',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'volume_uuid', type: 'string'),
        new OA\Property(property: 'frequency', type: 'string'),
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'stop_during_backup', type: 'boolean'),
        new OA\Property(property: 's3_storage_uuid', type: 'string', nullable: true),
        new OA\Property(property: 'retention_amount_s3', type: 'integer'),
        new OA\Property(property: 'retention_days_s3', type: 'integer'),
        new OA\Property(property: 'retention_max_storage_s3', type: 'number'),
        new OA\Property(property: 'timeout', type: 'integer'),
        new OA\Property(property: 'missing_backup_notification_days', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
)]
#[OA\Schema(
    schema: 'ClusterVolumeBackupExecution',
    type: 'object',
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'message', type: 'string', nullable: true),
        new OA\Property(property: 'size', type: 'integer', nullable: true),
        new OA\Property(property: 'filename', type: 'string', nullable: true),
        new OA\Property(property: 's3_uploaded', type: 'boolean', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
    ],
)]
/**
 * The volumes and the volume backups of cluster applications, databases, and the services of
 * stacks (decision 0029). The calls use the actions of the storage pages, so the rules of
 * decisions 0007, 0009, and 0024 apply: a volume is a Podman volume (no host bind mount), and a
 * restore stops the workload. A stack call names the `service`. Without `service`, the volume
 * list and the volume delete work on the volumes of the stack.
 */
class ClusterVolumesController extends Controller
{
    use HandlesClusterResourcesApi;
    use RequiresDeployAbility;

    #[OA\Get(
        summary: 'List Volumes',
        description: 'List the volumes of a cluster application.',
        path: '/cluster-applications/{uuid}/volumes',
        operationId: 'list-volumes-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The volumes.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolume'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Volumes',
        description: 'List the volumes of a cluster database.',
        path: '/cluster-databases/{uuid}/volumes',
        operationId: 'list-volumes-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The volumes.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolume'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Volumes',
        description: 'List the volumes of a cluster stack. Without `service`, the volumes of the stack and the services that mount them.',
        path: '/cluster-stacks/{uuid}/volumes',
        operationId: 'list-volumes-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: false, description: 'The service of the stack. Without it, the volumes of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The volumes.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolume'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function listVolumes(Request $request): JsonResponse
    {
        if ($this->isStackLevel($request)) {
            $stack = $this->clusterStack($request);
            if ($stack === null) {
                return response()->json(['message' => 'Cluster stack not found.'], 404);
            }
            $this->authorize('view', $stack);

            return response()->json($this->stackVolumes($stack)->map(fn (NodeVolume $volume): array => $this->volumeResponse($volume))->values());
        }
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('view', $workload);

        return response()->json($this->volumes($workload)->map(fn (NodeVolume $volume): array => $this->volumeResponse($volume))->values());
    }

    #[OA\Post(
        summary: 'Create Volume',
        description: 'Add a volume to a cluster application. The next deployment creates and mounts it.',
        path: '/cluster-applications/{uuid}/volumes',
        operationId: 'create-volume-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name', 'mount_path'], properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Letters, numbers, spaces, dots, dashes, and underscores.'),
            new OA\Property(property: 'mount_path', type: 'string', description: 'An absolute path in the container, for example /data.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The volume.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolume')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Volume',
        description: 'Add a volume to a cluster database. The next deployment creates and mounts it.',
        path: '/cluster-databases/{uuid}/volumes',
        operationId: 'create-volume-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name', 'mount_path'], properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Letters, numbers, spaces, dots, dashes, and underscores.'),
            new OA\Property(property: 'mount_path', type: 'string', description: 'An absolute path in the container, for example /data.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The volume.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolume')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Volume',
        description: 'Add a volume to a cluster stack. The next deployment creates and mounts it.',
        path: '/cluster-stacks/{uuid}/volumes',
        operationId: 'create-volume-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name', 'mount_path'], properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Letters, numbers, spaces, dots, dashes, and underscores.'),
            new OA\Property(property: 'mount_path', type: 'string', description: 'An absolute path in the container, for example /data.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The volume.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolume')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createVolume(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, [
            'name' => CreateNodeVolume::nameRules(),
            'mount_path' => ['required', 'string', 'max:4096'],
        ], CreateNodeVolume::nameMessages());
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $workload): JsonResponse {
            $volume = CreateNodeVolume::run($workload, (string) $request->input('name'), (string) $request->input('mount_path'));

            return response()->json($this->volumeResponse($this->volumes($workload)->firstWhere('id', $volume->id)), 201);
        });
    }

    #[OA\Patch(
        summary: 'Update Volume',
        description: 'Rename a volume of a cluster application or change its mount path. The data stays. The next deployment mounts it at the new path.',
        path: '/cluster-applications/{uuid}/volumes/{volume_uuid}',
        operationId: 'update-volume-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'volume_uuid', in: 'path', required: true, description: 'UUID of the volume.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Letters, numbers, spaces, dots, dashes, and underscores.'),
            new OA\Property(property: 'mount_path', type: 'string', description: 'An absolute path in the container, for example /data.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The volume.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolume')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Volume',
        description: 'Rename a volume of a cluster database or change its mount path. The data stays. The next deployment mounts it at the new path.',
        path: '/cluster-databases/{uuid}/volumes/{volume_uuid}',
        operationId: 'update-volume-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'volume_uuid', in: 'path', required: true, description: 'UUID of the volume.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Letters, numbers, spaces, dots, dashes, and underscores.'),
            new OA\Property(property: 'mount_path', type: 'string', description: 'An absolute path in the container, for example /data.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The volume.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolume')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Volume',
        description: 'Rename a volume of a cluster stack or change its mount path. The data stays. The next deployment mounts it at the new path.',
        path: '/cluster-stacks/{uuid}/volumes/{volume_uuid}',
        operationId: 'update-volume-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'volume_uuid', in: 'path', required: true, description: 'UUID of the volume.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'name', type: 'string', description: 'Letters, numbers, spaces, dots, dashes, and underscores.'),
            new OA\Property(property: 'mount_path', type: 'string', description: 'An absolute path in the container, for example /data.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The volume.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolume')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function updateVolume(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $volume = $this->volume($workload, $request);
        if ($volume === null) {
            return response()->json(['message' => 'Volume not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, [
            'name' => ['sometimes', ...array_diff(CreateNodeVolume::nameRules(), ['required'])],
            'mount_path' => ['sometimes', 'string', 'max:4096'],
        ], CreateNodeVolume::nameMessages());
        if ($invalid !== null) {
            return $invalid;
        }
        if (! $request->hasAny(['name', 'mount_path'])) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }

        return $this->runClusterAction(function () use ($request, $workload, $volume): JsonResponse {
            UpdateNodeVolume::run($workload, $volume, $request->input('name'), $request->input('mount_path'));

            return response()->json($this->volumeResponse($this->volumes($workload)->firstWhere('id', $volume->id)));
        });
    }

    #[OA\Delete(
        summary: 'Delete Volume',
        description: 'Remove a volume from a cluster application. The data stays until you delete it with `delete_data=true`. Redeploy first when the current deployment mounts the volume.',
        path: '/cluster-applications/{uuid}/volumes/{volume_uuid}',
        operationId: 'delete-volume-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'volume_uuid', in: 'path', required: true, description: 'UUID of the volume.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_data', in: 'query', required: false, description: 'Also delete the data. The default is false.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The volume is removed.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Volume',
        description: 'Remove a volume from a cluster database. The data stays until you delete it with `delete_data=true`. Redeploy first when the current deployment mounts the volume.',
        path: '/cluster-databases/{uuid}/volumes/{volume_uuid}',
        operationId: 'delete-volume-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'volume_uuid', in: 'path', required: true, description: 'UUID of the volume.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_data', in: 'query', required: false, description: 'Also delete the data. The default is false.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The volume is removed.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Volume',
        description: 'Remove a volume from a cluster stack. The data stays until you delete it with `delete_data=true`. Redeploy first when the current deployment mounts the volume. Without `service`, delete a volume of the stack that no service mounts.',
        path: '/cluster-stacks/{uuid}/volumes/{volume_uuid}',
        operationId: 'delete-volume-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'volume_uuid', in: 'path', required: true, description: 'UUID of the volume.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: false, description: 'The service of the stack. Without it, delete a volume of the stack that no service mounts.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_data', in: 'query', required: false, description: 'Also delete the data. The default is false.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The volume is removed.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deleteVolume(Request $request): JsonResponse
    {
        if ($this->isStackLevel($request)) {
            return $this->deleteStackVolume($request);
        }
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $volume = $this->volume($workload, $request);
        if ($volume === null) {
            return response()->json(['message' => 'Volume not found.'], 404);
        }
        $this->authorize('update', $workload);

        return $this->runClusterAction(function () use ($request, $workload, $volume): JsonResponse {
            if (RemoveNodeVolume::run($volume, $workload) === 'deleted') {
                return response()->json(['message' => 'Volume removed.']);
            }
            if (! $request->boolean('delete_data')) {
                return response()->json(['message' => 'Volume removed from the configuration. Redeploy to unmount it. Its data stays until you delete it.']);
            }
            try {
                DeleteNodeVolumeData::run($volume);
            } catch (RuntimeException $exception) {
                return response()->json(['message' => 'Volume removed from the configuration, but its data stays: '.$exception->getMessage()], 400);
            }

            return response()->json(['message' => 'Volume and its data deleted.']);
        });
    }

    #[OA\Get(
        summary: 'List Volume Backups',
        description: 'List the volume backup schedules of a cluster application.',
        path: '/cluster-applications/{uuid}/volume-backups',
        operationId: 'list-volume-backups-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The schedules.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolumeBackup'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Volume Backups',
        description: 'List the volume backup schedules of a cluster database.',
        path: '/cluster-databases/{uuid}/volume-backups',
        operationId: 'list-volume-backups-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The schedules.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolumeBackup'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Volume Backups',
        description: 'List the volume backup schedules of a cluster stack.',
        path: '/cluster-stacks/{uuid}/volume-backups',
        operationId: 'list-volume-backups-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The schedules.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolumeBackup'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function listBackups(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('view', $workload);

        return response()->json(ManageNodeVolumeBackups::schedulesOf($workload)->with(['backupable', 's3'])->latest('id')->get()
            ->map(fn (ScheduledVolumeBackup $backup): array => $this->backupResponse($backup))->values());
    }

    #[OA\Post(
        summary: 'Create Volume Backup',
        description: 'Create a backup schedule of a volume of a cluster application. Backups go to one S3 storage of the team.',
        path: '/cluster-applications/{uuid}/volume-backups',
        operationId: 'create-volume-backup-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['volume_uuid', 'frequency', 's3_storage_uuid'], properties: [
            new OA\Property(property: 'volume_uuid', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 's3_storage_uuid', type: 'string', description: 'An S3 storage of the team.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The schedule.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolumeBackup')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Volume Backup',
        description: 'Create a backup schedule of a volume of a cluster database. Backups go to one S3 storage of the team.',
        path: '/cluster-databases/{uuid}/volume-backups',
        operationId: 'create-volume-backup-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['volume_uuid', 'frequency', 's3_storage_uuid'], properties: [
            new OA\Property(property: 'volume_uuid', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 's3_storage_uuid', type: 'string', description: 'An S3 storage of the team.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The schedule.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolumeBackup')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Create Volume Backup',
        description: 'Create a backup schedule of a volume of a cluster stack. Backups go to one S3 storage of the team.',
        path: '/cluster-stacks/{uuid}/volume-backups',
        operationId: 'create-volume-backup-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['volume_uuid', 'frequency', 's3_storage_uuid'], properties: [
            new OA\Property(property: 'volume_uuid', type: 'string'),
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 's3_storage_uuid', type: 'string', description: 'An S3 storage of the team.'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The schedule.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolumeBackup')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function createBackup(Request $request): JsonResponse
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, [
            'volume_uuid' => 'required|string',
            'frequency' => 'required|string',
            's3_storage_uuid' => 'required|string',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }

        return $this->runClusterAction(function () use ($request, $workload): JsonResponse {
            try {
                $backup = ManageNodeVolumeBackups::make()->createSchedule(
                    $workload,
                    ManageNodeVolumeBackups::volumesOf($workload)->firstWhere('uuid', (string) $request->input('volume_uuid')),
                    (string) $request->input('frequency'),
                    ManageNodeVolumeBackups::storages($workload)->where('uuid', (string) $request->input('s3_storage_uuid'))->first(),
                    $request->user(),
                    'api',
                );
            } catch (ValidationException $exception) {
                throw $this->renamed($exception);
            }

            return response()->json($this->backupResponse($backup->refresh()->load(['backupable', 's3'])), 201);
        });
    }

    #[OA\Patch(
        summary: 'Update Volume Backup',
        description: 'Change a volume backup schedule of a cluster application. Send only the fields to change.',
        path: '/cluster-applications/{uuid}/volume-backups/{backup_uuid}',
        operationId: 'update-volume-backup-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'stop_during_backup', type: 'boolean', description: 'Stop the workload during the backup. Needs a token with the deploy permission. Not for a volume that services of a stack share.'),
            new OA\Property(property: 's3_storage_uuid', type: 'string', description: 'An S3 storage of the team.'),
            new OA\Property(property: 'retention_amount_s3', type: 'integer'),
            new OA\Property(property: 'retention_days_s3', type: 'integer'),
            new OA\Property(property: 'retention_max_storage_s3', type: 'number'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds, 60 to 36000.'),
            new OA\Property(property: 'missing_backup_notification_days', type: 'integer', description: 'Alert after this many days without a run. 0 disables the alert.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The schedule.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolumeBackup')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Volume Backup',
        description: 'Change a volume backup schedule of a cluster database. Send only the fields to change.',
        path: '/cluster-databases/{uuid}/volume-backups/{backup_uuid}',
        operationId: 'update-volume-backup-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'stop_during_backup', type: 'boolean', description: 'Stop the workload during the backup. Needs a token with the deploy permission. Not for a volume that services of a stack share.'),
            new OA\Property(property: 's3_storage_uuid', type: 'string', description: 'An S3 storage of the team.'),
            new OA\Property(property: 'retention_amount_s3', type: 'integer'),
            new OA\Property(property: 'retention_days_s3', type: 'integer'),
            new OA\Property(property: 'retention_max_storage_s3', type: 'number'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds, 60 to 36000.'),
            new OA\Property(property: 'missing_backup_notification_days', type: 'integer', description: 'Alert after this many days without a run. 0 disables the alert.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The schedule.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolumeBackup')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Patch(
        summary: 'Update Volume Backup',
        description: 'Change a volume backup schedule of a cluster stack. Send only the fields to change.',
        path: '/cluster-stacks/{uuid}/volume-backups/{backup_uuid}',
        operationId: 'update-volume-backup-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'frequency', type: 'string', description: 'A cron expression, or `daily`, `hourly`, and so on.'),
            new OA\Property(property: 'enabled', type: 'boolean'),
            new OA\Property(property: 'stop_during_backup', type: 'boolean', description: 'Stop the workload during the backup. Needs a token with the deploy permission. Not for a volume that services of a stack share.'),
            new OA\Property(property: 's3_storage_uuid', type: 'string', description: 'An S3 storage of the team.'),
            new OA\Property(property: 'retention_amount_s3', type: 'integer'),
            new OA\Property(property: 'retention_days_s3', type: 'integer'),
            new OA\Property(property: 'retention_max_storage_s3', type: 'number'),
            new OA\Property(property: 'timeout', type: 'integer', description: 'Seconds, 60 to 36000.'),
            new OA\Property(property: 'missing_backup_notification_days', type: 'integer', description: 'Alert after this many days without a run. 0 disables the alert.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The schedule.', content: new OA\JsonContent(ref: '#/components/schemas/ClusterVolumeBackup')),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function updateBackup(Request $request): JsonResponse
    {
        [$workload, $backup] = $this->backup($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        if ($backup === null) {
            return response()->json(['message' => 'Backup schedule not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, [
            ...collect(ManageNodeVolumeBackups::scheduleRules())->map(fn (array $rules): array => ['sometimes', ...array_diff($rules, ['required', 'sometimes'])])->all(),
            's3_storage_uuid' => 'sometimes|string',
        ]);
        if ($invalid !== null) {
            return $invalid;
        }
        if ($request->all() === []) {
            return response()->json(['message' => 'At least one field must be provided.'], 422);
        }
        if ($request->boolean('stop_during_backup') && ! $backup->stop_during_backup
            && ($response = $this->deployAbilityForbiddenResponse($request, 'A backup that stops the workload needs a token with the deploy permission.'))) {
            return $response;
        }
        $s3 = $request->has('s3_storage_uuid')
            ? ManageNodeVolumeBackups::storages($workload, $backup->s3_storage_id)->where('uuid', (string) $request->input('s3_storage_uuid'))->first()
            : $backup->s3;
        if ($s3 === null) {
            return response()->json(['message' => 'Validation failed.', 'errors' => ['s3_storage_uuid' => ['Select a usable S3 storage of the team.']]], 422);
        }
        $input = [
            'frequency' => $backup->frequency,
            'enabled' => (bool) $backup->enabled,
            'stop_during_backup' => (bool) $backup->stop_during_backup,
            'retention_amount_s3' => $backup->retention_amount_s3,
            'retention_days_s3' => $backup->retention_days_s3,
            'retention_max_storage_s3' => $backup->retention_max_storage_s3,
            'timeout' => $backup->timeout,
            'missing_backup_notification_days' => $backup->missing_backup_notification_days,
            ...$request->only(array_keys(ManageNodeVolumeBackups::scheduleRules())),
            's3_storage' => $s3,
        ];

        return $this->runClusterAction(function () use ($request, $workload, $backup, $input): JsonResponse {
            try {
                $backup = ManageNodeVolumeBackups::make()->updateSchedule($workload, $backup, $input, $request->user(), 'api');
            } catch (ValidationException $exception) {
                throw $this->renamed($exception);
            }

            return response()->json($this->backupResponse($backup->refresh()->load(['backupable', 's3'])));
        });
    }

    #[OA\Delete(
        summary: 'Delete Volume Backup',
        description: 'Delete a volume backup schedule of a cluster application and its runs. With `delete_s3=true`, also the files of the runs in S3.',
        path: '/cluster-applications/{uuid}/volume-backups/{backup_uuid}',
        operationId: 'delete-volume-backup-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_s3', in: 'query', required: false, description: 'Also delete the files in S3. The default is false.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The schedule is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Volume Backup',
        description: 'Delete a volume backup schedule of a cluster database and its runs. With `delete_s3=true`, also the files of the runs in S3.',
        path: '/cluster-databases/{uuid}/volume-backups/{backup_uuid}',
        operationId: 'delete-volume-backup-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_s3', in: 'query', required: false, description: 'Also delete the files in S3. The default is false.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The schedule is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Delete(
        summary: 'Delete Volume Backup',
        description: 'Delete a volume backup schedule of a cluster stack and its runs. With `delete_s3=true`, also the files of the runs in S3.',
        path: '/cluster-stacks/{uuid}/volume-backups/{backup_uuid}',
        operationId: 'delete-volume-backup-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'delete_s3', in: 'query', required: false, description: 'Also delete the files in S3. The default is false.', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The schedule is deleted.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function deleteBackup(Request $request): JsonResponse
    {
        [$workload, $backup] = $this->backup($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        if ($backup === null) {
            return response()->json(['message' => 'Backup schedule not found.'], 404);
        }
        $this->authorize('delete', $workload);

        return $this->runClusterAction(function () use ($request, $workload, $backup): JsonResponse {
            DeleteScheduledVolumeBackup::run($backup, deleteLocalArchives: false, deleteS3Archives: $request->boolean('delete_s3'));
            auditLog('api.volume_backup.schedule_deleted', [
                'team_id' => $workload->team_id,
                'resource_uuid' => $workload->uuid,
                'backup_uuid' => $backup->uuid,
            ]);

            return response()->json(['message' => 'Backup schedule deleted.']);
        });
    }

    #[OA\Post(
        summary: 'Run Volume Backup',
        description: 'Run a volume backup schedule of a cluster application now.',
        path: '/cluster-applications/{uuid}/volume-backups/{backup_uuid}/run',
        operationId: 'run-volume-backup-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The backup is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Post(
        summary: 'Run Volume Backup',
        description: 'Run a volume backup schedule of a cluster database now.',
        path: '/cluster-databases/{uuid}/volume-backups/{backup_uuid}/run',
        operationId: 'run-volume-backup-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The backup is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Post(
        summary: 'Run Volume Backup',
        description: 'Run a volume backup schedule of a cluster stack now.',
        path: '/cluster-stacks/{uuid}/volume-backups/{backup_uuid}/run',
        operationId: 'run-volume-backup-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The backup is queued.', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function runBackup(Request $request): JsonResponse
    {
        [$workload, $backup] = $this->backup($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        if ($backup === null) {
            return response()->json(['message' => 'Backup schedule not found.'], 404);
        }
        $this->authorize('update', $workload);

        return $this->runClusterAction(function () use ($workload, $backup): JsonResponse {
            ManageNodeVolumeBackups::make()->runNow($workload, $backup, 'api');

            return response()->json(['message' => 'Backup queued.']);
        });
    }

    #[OA\Get(
        summary: 'List Volume Backup Runs',
        description: 'List the runs of a volume backup schedule of a cluster application, newest first.',
        path: '/cluster-applications/{uuid}/volume-backups/{backup_uuid}/executions',
        operationId: 'list-volume-backup-executions-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The runs.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolumeBackupExecution'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Volume Backup Runs',
        description: 'List the runs of a volume backup schedule of a cluster database, newest first.',
        path: '/cluster-databases/{uuid}/volume-backups/{backup_uuid}/executions',
        operationId: 'list-volume-backup-executions-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The runs.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolumeBackupExecution'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Get(
        summary: 'List Volume Backup Runs',
        description: 'List the runs of a volume backup schedule of a cluster stack, newest first.',
        path: '/cluster-stacks/{uuid}/volume-backups/{backup_uuid}/executions',
        operationId: 'list-volume-backup-executions-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The runs.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/ClusterVolumeBackupExecution'))),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function backupExecutions(Request $request): JsonResponse
    {
        [$workload, $backup] = $this->backup($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        if ($backup === null) {
            return response()->json(['message' => 'Backup schedule not found.'], 404);
        }
        $this->authorize('view', $workload);

        return response()->json($backup->executions()->latest('id')->limit(100)->get()->map(fn (ScheduledVolumeBackupExecution $execution): array => [
            'uuid' => $execution->uuid,
            'status' => $execution->status,
            'message' => $execution->message,
            'size' => $execution->size,
            'filename' => $execution->filename,
            's3_uploaded' => $execution->s3_uploaded,
            'created_at' => $execution->created_at,
            'finished_at' => $execution->finished_at,
        ])->values());
    }

    #[OA\Post(
        summary: 'Restore Volume Backup',
        description: 'Restore a backup run to a server of the cluster. The restore stops the workload, replaces the data of the volume, and starts the workload again.',
        path: '/cluster-applications/{uuid}/volume-backups/{backup_uuid}/executions/{execution_uuid}/restore',
        operationId: 'restore-volume-backup-by-application-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'execution_uuid', in: 'path', required: true, description: 'UUID of the backup run.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'server_uuid', type: 'string', description: 'A server of the cluster. The default is the server of the volume.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The restore is queued.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Restore Volume Backup',
        description: 'Restore a backup run to a server of the cluster. The restore stops the workload, replaces the data of the volume, and starts the workload again.',
        path: '/cluster-databases/{uuid}/volume-backups/{backup_uuid}/executions/{execution_uuid}/restore',
        operationId: 'restore-volume-backup-by-database-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'execution_uuid', in: 'path', required: true, description: 'UUID of the backup run.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'server_uuid', type: 'string', description: 'A server of the cluster. The default is the server of the volume.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The restore is queued.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    #[OA\Post(
        summary: 'Restore Volume Backup',
        description: 'Restore a backup run to a server of the cluster. The restore stops the workload, replaces the data of the volume, and starts the workload again.',
        path: '/cluster-stacks/{uuid}/volume-backups/{backup_uuid}/executions/{execution_uuid}/restore',
        operationId: 'restore-volume-backup-by-stack-cluster-uuid',
        security: [['bearerAuth' => []]],
        tags: ['Cluster Stacks'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the cluster stack.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'backup_uuid', in: 'path', required: true, description: 'UUID of the backup schedule.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'execution_uuid', in: 'path', required: true, description: 'UUID of the backup run.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', required: true, description: 'The service of the stack.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: [], properties: [
            new OA\Property(property: 'server_uuid', type: 'string', description: 'A server of the cluster. The default is the server of the volume.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The restore is queued.'),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'The token or the user may not do this.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ]
    )]
    public function restore(Request $request): JsonResponse
    {
        [$workload, $backup] = $this->backup($request);
        if ($workload instanceof JsonResponse) {
            return $workload;
        }
        if ($backup === null) {
            return response()->json(['message' => 'Backup schedule not found.'], 404);
        }
        $execution = $backup->executions()->where('uuid', (string) $request->route('execution_uuid'))->first();
        if ($execution === null) {
            return response()->json(['message' => 'Backup run not found.'], 404);
        }
        $this->authorize('update', $workload);
        $invalid = $this->validateRequest($request, ['server_uuid' => 'sometimes|string']);
        if ($invalid !== null) {
            return $invalid;
        }
        $targets = ManageNodeVolumeBackups::restoreTargets($workload, $backup, $request->user());
        $target = $request->has('server_uuid') ? $targets->firstWhere('uuid', (string) $request->input('server_uuid')) : $targets->first();

        return $this->runClusterAction(function () use ($request, $workload, $backup, $execution, $target): JsonResponse {
            try {
                $operation = ManageNodeVolumeBackups::make()->restore($workload, $backup, $execution, $target, $request->user(), 'api');
            } catch (ValidationException $exception) {
                throw $this->renamed($exception);
            }

            return response()->json(['message' => 'Restore queued.', 'deployment_uuid' => $operation->uuid]);
        });
    }

    /** A stack call without `service` works on the volumes of the stack. */
    private function isStackLevel(Request $request): bool
    {
        return $request->route('cluster_kind') === 'stack' && ! $request->has('service') && ! $request->query->has('service');
    }

    private function deleteStackVolume(Request $request): JsonResponse
    {
        $stack = $this->clusterStack($request);
        if ($stack === null) {
            return response()->json(['message' => 'Cluster stack not found.'], 404);
        }
        $volume = $stack->volumes()->where('team_id', $stack->team_id)->where('uuid', (string) $request->route('volume_uuid'))->first();
        if ($volume === null) {
            return response()->json(['message' => 'Volume not found.'], 404);
        }
        $this->authorize('update', $stack);

        return $this->runClusterAction(function () use ($volume): JsonResponse {
            DeleteNodeVolumeData::run($volume);

            return response()->json(['message' => 'Volume and its data deleted.']);
        });
    }

    /**
     * The volumes of the workload, like the storage page: its own volumes (also the detached ones)
     * and the stack volumes that it mounts, with the path in this workload.
     *
     * @return Collection<int, NodeVolume>
     */
    private function volumes(NodeWorkload $workload): Collection
    {
        $own = $workload->volumes()->with(['node', 'scheduledBackups'])->get();
        $shared = $workload->stackVolumes()->with(['node', 'scheduledBackups'])->get()
            ->each(function (NodeVolume $volume): void {
                $volume->setAttribute('mount_path', $volume->pivot->mount_path);
                $volume->setAttribute('read_only', (bool) $volume->pivot->read_only);
            });

        return $own->concat($shared)->sortBy('mount_path')->values();
    }

    /** @return Collection<int, NodeVolume> */
    private function stackVolumes(NodeStack $stack): Collection
    {
        return $stack->volumes()
            ->with(['node', 'scheduledBackups', 'mounts.workload' => fn ($workloads) => $workloads->withoutGlobalScopes()])
            ->orderBy('name')
            ->get();
    }

    private function volume(NodeWorkload $workload, Request $request): ?NodeVolume
    {
        return NodeVolume::query()
            ->where(fn (Builder $query) => $query
                ->where('node_workload_id', $workload->id)
                ->orWhereHas('mounts', fn (Builder $mounts) => $mounts->where('node_workload_id', $workload->id)))
            ->where('team_id', $workload->team_id)
            ->where('uuid', (string) $request->route('volume_uuid'))
            ->first();
    }

    /** @return array{0: NodeWorkload|JsonResponse, 1: ?ScheduledVolumeBackup} */
    private function backup(Request $request): array
    {
        $workload = $this->kindWorkload($request);
        if ($workload instanceof JsonResponse) {
            return [$workload, null];
        }

        return [$workload, ManageNodeVolumeBackups::schedulesOf($workload)->with(['backupable.node', 's3'])->where('uuid', (string) $request->route('backup_uuid'))->first()];
    }

    /** The action names the fields of the page. The API names them like its request fields. */
    private function renamed(ValidationException $exception): ValidationException
    {
        $fields = ['volume' => 'volume_uuid', 's3_storage' => 's3_storage_uuid', 'server' => 'server_uuid'];

        return ValidationException::withMessages(collect($exception->errors())
            ->mapWithKeys(fn (array $messages, string $key): array => [$fields[$key] ?? $key => $messages])
            ->all());
    }

    /** @return array<string, mixed> */
    private function volumeResponse(NodeVolume $volume): array
    {
        $mounts = $volume->isStackVolume() && $volume->relationLoaded('mounts') ? $volume->mounts : null;

        return [
            'uuid' => $volume->uuid,
            'name' => $volume->name,
            'mount_path' => $mounts === null ? $volume->mount_path : null,
            'read_only' => (bool) ($volume->getAttribute('read_only') ?? false),
            'shared' => $volume->isStackVolume(),
            'detached' => $volume->isDetached(),
            'server_uuid' => $volume->node?->uuid,
            'server_name' => $volume->node?->name,
            'status' => $volume->inventoryStatus($volume->node?->freshVolumeInventory()),
            'backup_schedule_uuid' => $volume->scheduledBackups->first()?->uuid,
            ...($mounts === null ? [] : ['mounts' => $mounts->sortBy('node_workload_id')->map(fn (NodeVolumeMount $mount): array => [
                'service' => $mount->workload?->stack_service,
                'mount_path' => $mount->mount_path,
                'read_only' => (bool) $mount->read_only,
            ])->values()->all()]),
        ];
    }

    /** @return array<string, mixed> */
    private function backupResponse(ScheduledVolumeBackup $backup): array
    {
        return [
            'uuid' => $backup->uuid,
            'volume_uuid' => $backup->backupable?->uuid,
            'frequency' => $backup->frequency,
            'enabled' => (bool) $backup->enabled,
            'stop_during_backup' => (bool) $backup->stop_during_backup,
            's3_storage_uuid' => $backup->s3?->uuid,
            'retention_amount_s3' => $backup->retention_amount_s3,
            'retention_days_s3' => $backup->retention_days_s3,
            'retention_max_storage_s3' => (float) $backup->retention_max_storage_s3,
            'timeout' => $backup->timeout,
            'missing_backup_notification_days' => $backup->missing_backup_notification_days,
            'created_at' => $backup->created_at,
        ];
    }
}
