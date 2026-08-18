<?php

namespace App\Http\Controllers\Api;

use App\Actions\Shared\DeleteScheduledVolumeBackup;
use App\Http\Controllers\Controller;
use App\Jobs\VolumeBackupJob;
use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\S3Storage;
use App\Models\ScheduledVolumeBackup;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use OpenApi\Attributes as OA;
use RuntimeException;

#[OA\Schema(
    schema: 'VolumeBackupScheduleRequest',
    required: ['frequency'],
    properties: [
        new OA\Property(property: 'frequency', type: 'string', maxLength: 255, example: '0 2 * * *'),
        new OA\Property(property: 'enabled', type: 'boolean', default: true),
        new OA\Property(property: 'save_s3', type: 'boolean', default: false),
        new OA\Property(property: 'disable_local_backup', type: 'boolean', default: false),
        new OA\Property(property: 'stop_during_backup', type: 'boolean', default: false),
        new OA\Property(property: 's3_storage_uuid', type: 'string', nullable: true),
        new OA\Property(property: 'retention_amount_locally', type: 'integer', default: 7, minimum: 0, maximum: 10000),
        new OA\Property(property: 'retention_days_locally', type: 'integer', default: 0, maximum: 2147483647, minimum: 0),
        new OA\Property(property: 'retention_max_storage_locally', type: 'number', format: 'float', default: 0, maximum: 9999999999, minimum: 0),
        new OA\Property(property: 'retention_amount_s3', type: 'integer', default: 7, minimum: 0, maximum: 10000),
        new OA\Property(property: 'retention_days_s3', type: 'integer', default: 0, maximum: 2147483647, minimum: 0),
        new OA\Property(property: 'retention_max_storage_s3', type: 'number', format: 'float', default: 0, maximum: 9999999999, minimum: 0),
        new OA\Property(property: 'timeout', type: 'integer', default: ScheduledVolumeBackup::DEFAULT_TIMEOUT, minimum: 60, maximum: 36000),
    ],
    type: 'object',
    additionalProperties: false,
)]
#[OA\Schema(
    schema: 'VolumeBackupScheduleResponse',
    required: ['uuid', 'message', 'storage_uuid', 'storage_type', 'frequency', 'enabled', 'save_s3', 'disable_local_backup', 'stop_during_backup', 'retention_amount_locally', 'retention_days_locally', 'retention_max_storage_locally', 'retention_amount_s3', 'retention_days_s3', 'retention_max_storage_s3', 'timeout'],
    properties: [
        new OA\Property(property: 'uuid', type: 'string'),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'storage_uuid', type: 'string'),
        new OA\Property(property: 'storage_type', type: 'string', enum: ['persistent', 'directory']),
        new OA\Property(property: 'frequency', type: 'string'),
        new OA\Property(property: 'enabled', type: 'boolean'),
        new OA\Property(property: 'save_s3', type: 'boolean'),
        new OA\Property(property: 'disable_local_backup', type: 'boolean'),
        new OA\Property(property: 'stop_during_backup', type: 'boolean'),
        new OA\Property(property: 's3_storage_uuid', type: 'string', nullable: true),
        new OA\Property(property: 'retention_amount_locally', type: 'integer'),
        new OA\Property(property: 'retention_days_locally', type: 'integer'),
        new OA\Property(property: 'retention_max_storage_locally', type: 'number', format: 'float'),
        new OA\Property(property: 'retention_amount_s3', type: 'integer'),
        new OA\Property(property: 'retention_days_s3', type: 'integer'),
        new OA\Property(property: 'retention_max_storage_s3', type: 'number', format: 'float'),
        new OA\Property(property: 'timeout', type: 'integer'),
    ],
    type: 'object',
)]
class VolumeBackupsController extends Controller
{
    #[OA\Put(
        summary: 'Set application storage backup schedule',
        description: 'Create or replace the backup schedule for an application persistent volume or directory storage.',
        path: '/applications/{uuid}/storages/{storage_uuid}/backups',
        operationId: 'set-application-storage-backup-schedule',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleRequest')),
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the application.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, description: 'UUID of the persistent volume or directory storage.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup schedule replaced.', content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleResponse')),
            new OA\Response(response: 201, description: 'Backup schedule created.', content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleResponse')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    #[OA\Put(
        summary: 'Set database storage backup schedule',
        description: 'Create or replace the backup schedule for a database persistent volume or directory storage.',
        path: '/databases/{uuid}/storages/{storage_uuid}/backups',
        operationId: 'set-database-storage-backup-schedule',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleRequest')),
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the database.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, description: 'UUID of the persistent volume or directory storage.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup schedule replaced.', content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleResponse')),
            new OA\Response(response: 201, description: 'Backup schedule created.', content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleResponse')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    #[OA\Put(
        summary: 'Set service storage backup schedule',
        description: 'Create or replace the backup schedule for a service persistent volume or directory storage.',
        path: '/services/{uuid}/storages/{storage_uuid}/backups',
        operationId: 'set-service-storage-backup-schedule',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleRequest')),
        tags: ['Services'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'UUID of the service.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, description: 'UUID of the persistent volume or directory storage.', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup schedule replaced.', content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleResponse')),
            new OA\Response(response: 201, description: 'Backup schedule created.', content: new OA\JsonContent(ref: '#/components/schemas/VolumeBackupScheduleResponse')),
            new OA\Response(response: 400, ref: '#/components/responses/400'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 422, ref: '#/components/responses/422'),
        ],
    )]
    public function upsert(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $invalidRequest = validateIncomingRequest($request);
        if ($invalidRequest instanceof JsonResponse) {
            return $invalidRequest;
        }

        $resourceType = $request->route('resource_type');
        $resource = $this->findResource($resourceType, $request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json([
                'message' => match ($resourceType) {
                    'application' => 'Application not found.',
                    'database' => 'Database not found.',
                    'service' => 'Service not found.',
                    default => 'Resource not found.',
                },
            ], 404);
        }

        $this->authorize('update', $resource);

        $storage = $this->findStorage($resource, $request->route('storage_uuid'));
        if (! $storage) {
            return response()->json(['message' => 'Storage not found.'], 404);
        }

        ['errors' => $errors, 's3Storage' => $s3Storage, 'saveToS3' => $saveToS3] = $this->validateUpsertRequest($request, $storage, $teamId);

        if ($errors->isNotEmpty()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        return $this->persistSchedule($request, $storage, $teamId, $s3Storage, $saveToS3, $resourceType, $resource);
    }

    /**
     * @return array{errors: MessageBag, s3Storage: S3Storage|null, saveToS3: bool}
     */
    private function validateUpsertRequest(
        Request $request,
        LocalPersistentVolume|LocalFileVolume $storage,
        int|string $teamId,
    ): array {
        $validator = customApiValidator($request->all(), [
            'frequency' => 'required|string|max:255',
            'enabled' => 'boolean',
            'save_s3' => 'boolean',
            'disable_local_backup' => 'boolean',
            'stop_during_backup' => 'boolean',
            's3_storage_uuid' => 'nullable|string',
            'retention_amount_locally' => 'integer|min:0|max:10000',
            'retention_days_locally' => 'integer|min:0|max:2147483647',
            'retention_max_storage_locally' => 'numeric|min:0|max:9999999999',
            'retention_amount_s3' => 'integer|min:0|max:10000',
            'retention_days_s3' => 'integer|min:0|max:2147483647',
            'retention_max_storage_s3' => 'numeric|min:0|max:9999999999',
            'timeout' => 'integer|min:60|max:36000',
        ]);
        $errors = $validator->errors();
        $allowedFields = [
            'frequency',
            'enabled',
            'save_s3',
            'disable_local_backup',
            'stop_during_backup',
            's3_storage_uuid',
            'retention_amount_locally',
            'retention_days_locally',
            'retention_max_storage_locally',
            'retention_amount_s3',
            'retention_days_s3',
            'retention_max_storage_s3',
            'timeout',
        ];

        foreach (array_diff(array_keys($request->all()), $allowedFields) as $field) {
            $errors->add($field, 'This field is not allowed.');
        }

        if (! $errors->has('frequency') && ! validate_cron_expression($request->string('frequency')->toString())) {
            $errors->add('frequency', 'The frequency must be a valid cron or human expression.');
        }

        $saveToS3 = $request->boolean('save_s3');
        if ($request->boolean('disable_local_backup') && ! $saveToS3) {
            $errors->add('disable_local_backup', 'Local backups can only be disabled when S3 backups are enabled.');
        }

        $s3Storage = null;
        if ($saveToS3) {
            $s3Storage = S3Storage::query()
                ->where('team_id', $teamId)
                ->where('is_usable', true)
                ->where('uuid', $request->input('s3_storage_uuid'))
                ->first();

            if (! $s3Storage) {
                $errors->add('s3_storage_uuid', 'Select a usable S3 storage owned by your team.');
            }
        }

        if ($storage instanceof LocalFileVolume && (! $storage->is_directory || $storage->is_host_file)) {
            $errors->add('storage_uuid', 'Only directory file storages can be backed up.');
        }

        return [
            'errors' => $errors,
            's3Storage' => $s3Storage,
            'saveToS3' => $saveToS3,
        ];
    }

    private function persistSchedule(
        Request $request,
        LocalPersistentVolume|LocalFileVolume $storage,
        int|string $teamId,
        ?S3Storage $s3Storage,
        bool $saveToS3,
        string $resourceType,
        Model $resource,
    ): JsonResponse {
        $attributes = [
            'team_id' => $teamId,
            'frequency' => $request->string('frequency')->toString(),
            'enabled' => $request->boolean('enabled', true),
            'save_s3' => $saveToS3,
            'disable_local_backup' => $saveToS3 && $request->boolean('disable_local_backup'),
            'stop_during_backup' => $request->boolean('stop_during_backup'),
            's3_storage_id' => $s3Storage?->id,
            'retention_amount_locally' => $request->integer('retention_amount_locally', 7),
            'retention_days_locally' => $request->integer('retention_days_locally'),
            'retention_max_storage_locally' => $request->float('retention_max_storage_locally'),
            'retention_amount_s3' => $request->integer('retention_amount_s3', 7),
            'retention_days_s3' => $request->integer('retention_days_s3'),
            'retention_max_storage_s3' => $request->float('retention_max_storage_s3'),
        ];
        if ($request->has('timeout')) {
            $attributes['timeout'] = $request->integer('timeout');
        }

        $backup = $storage->scheduledBackups()->updateOrCreate([], $attributes);
        $created = $backup->wasRecentlyCreated;

        auditLog('api.volume_backup.schedule_set', [
            'team_id' => $teamId,
            'resource_type' => $resourceType,
            'resource_uuid' => $resource->uuid,
            'storage_uuid' => $storage->uuid,
            'backup_uuid' => $backup->uuid,
        ]);

        return response()->json($this->responseData($backup, $storage, $s3Storage, $created), $created ? 201 : 200);
    }

    #[OA\Delete(
        summary: 'Delete application storage backup schedule',
        description: 'Delete the backup schedule and its local and S3 archives for an application storage.',
        path: '/applications/{uuid}/storages/{storage_uuid}/backups',
        operationId: 'delete-application-storage-backup-schedule',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup schedule and archives deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Backup or recovery operation is still running.'),
        ],
    )]
    #[OA\Delete(
        summary: 'Delete database storage backup schedule',
        description: 'Delete the backup schedule and its local and S3 archives for a database storage.',
        path: '/databases/{uuid}/storages/{storage_uuid}/backups',
        operationId: 'delete-database-storage-backup-schedule',
        security: [['bearerAuth' => []]],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup schedule and archives deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Backup or recovery operation is still running.'),
        ],
    )]
    #[OA\Delete(
        summary: 'Delete service storage backup schedule',
        description: 'Delete the backup schedule and its local and S3 archives for a service storage.',
        path: '/services/{uuid}/storages/{storage_uuid}/backups',
        operationId: 'delete-service-storage-backup-schedule',
        security: [['bearerAuth' => []]],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Backup schedule and archives deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 403, description: 'Forbidden.'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
            new OA\Response(response: 409, description: 'Backup or recovery operation is still running.'),
        ],
    )]
    public function destroy(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $resourceType = $request->route('resource_type');
        $resource = $this->findResource($resourceType, $request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }

        $this->authorize('update', $resource);

        $storage = $this->findStorage($resource, $request->route('storage_uuid'));
        if (! $storage) {
            return response()->json(['message' => 'Storage not found.'], 404);
        }

        $backup = $storage->scheduledBackups()->first();
        if (! $backup) {
            return response()->json(['message' => 'Storage backup schedule not found.'], 404);
        }

        try {
            DeleteScheduledVolumeBackup::run($backup);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        auditLog('api.volume_backup.schedule_deleted', [
            'team_id' => $teamId,
            'resource_type' => $resourceType,
            'resource_uuid' => $resource->uuid,
            'storage_uuid' => $storage->uuid,
            'backup_uuid' => $backup->uuid,
        ]);

        return response()->json(['message' => 'Storage backup schedule and archives deleted.']);
    }

    private function findResource(string $resourceType, string $uuid, int|string $teamId): ?Model
    {
        return match ($resourceType) {
            'application' => Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first(),
            'database' => queryDatabaseByUuidWithinTeam($uuid, $teamId),
            'service' => Service::query()->whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $uuid)->first(),
            default => null,
        };
    }

    private function findStorage(Model $resource, string $storageUuid): LocalPersistentVolume|LocalFileVolume|null
    {
        if ($resource instanceof Service) {
            foreach ($resource->applications->concat($resource->databases) as $serviceResource) {
                $storage = $this->findStorage($serviceResource, $storageUuid);
                if ($storage) {
                    return $storage;
                }
            }

            return null;
        }

        $storage = $resource->persistentStorages()->where('uuid', $storageUuid)->first();

        return $storage ?? $resource->fileStorages()->where('uuid', $storageUuid)->first();
    }

    private function responseData(
        ScheduledVolumeBackup $backup,
        LocalPersistentVolume|LocalFileVolume $storage,
        ?S3Storage $s3Storage,
        bool $created,
    ): array {
        return [
            'uuid' => $backup->uuid,
            'message' => $created ? 'Storage backup schedule created.' : 'Storage backup schedule updated.',
            'storage_uuid' => $storage->uuid,
            'storage_type' => $storage instanceof LocalFileVolume ? 'directory' : 'persistent',
            'frequency' => $backup->frequency,
            'enabled' => $backup->enabled,
            'save_s3' => $backup->save_s3,
            'disable_local_backup' => $backup->disable_local_backup,
            'stop_during_backup' => $backup->stop_during_backup,
            's3_storage_uuid' => $s3Storage?->uuid,
            'retention_amount_locally' => $backup->retention_amount_locally,
            'retention_days_locally' => $backup->retention_days_locally,
            'retention_max_storage_locally' => $backup->retention_max_storage_locally,
            'retention_amount_s3' => $backup->retention_amount_s3,
            'retention_days_s3' => $backup->retention_days_s3,
            'retention_max_storage_s3' => $backup->retention_max_storage_s3,
            'timeout' => $backup->timeout,
        ];
    }

    #[OA\Post(
        summary: 'Run application storage backup',
        description: 'Queue an immediate volume backup for an application storage that has a schedule.',
        path: '/applications/{uuid}/storages/{storage_uuid}/backups/run',
        operationId: 'run-application-storage-backup',
        security: [['bearerAuth' => []]],
        tags: ['Applications'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Storage backup queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Post(
        summary: 'Run database storage backup',
        description: 'Queue an immediate volume backup for a database storage that has a schedule.',
        path: '/databases/{uuid}/storages/{storage_uuid}/backups/run',
        operationId: 'run-database-storage-backup',
        security: [['bearerAuth' => []]],
        tags: ['Databases'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Storage backup queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    #[OA\Post(
        summary: 'Run service storage backup',
        description: 'Queue an immediate volume backup for a service storage that has a schedule.',
        path: '/services/{uuid}/storages/{storage_uuid}/backups/run',
        operationId: 'run-service-storage-backup',
        security: [['bearerAuth' => []]],
        tags: ['Services'],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'storage_uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Storage backup queued.'),
            new OA\Response(response: 401, ref: '#/components/responses/401'),
            new OA\Response(response: 404, ref: '#/components/responses/404'),
        ]
    )]
    public function run(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $resourceType = $request->route('resource_type');
        $resource = $this->findResource($resourceType, $request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json([
                'message' => match ($resourceType) {
                    'application' => 'Application not found.',
                    'database' => 'Database not found.',
                    'service' => 'Service not found.',
                    default => 'Resource not found.',
                },
            ], 404);
        }

        $this->authorize('update', $resource);

        $storage = $this->findStorage($resource, $request->route('storage_uuid'));
        if (! $storage) {
            return response()->json(['message' => 'Storage not found.'], 404);
        }

        $backup = $storage->scheduledBackups()->first();
        if (! $backup) {
            return response()->json(['message' => 'Storage backup schedule not found.'], 404);
        }

        VolumeBackupJob::dispatch($backup);

        auditLog('api.volume_backup.run', [
            'team_id' => $teamId,
            'resource_type' => $resourceType,
            'resource_uuid' => $resource->uuid,
            'storage_uuid' => $storage->uuid,
            'backup_uuid' => $backup->uuid,
        ]);

        return response()->json([
            'message' => 'Storage backup queued.',
            'uuid' => $backup->uuid,
        ]);
    }
}
